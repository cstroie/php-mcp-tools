<?php
declare(strict_types=1);

namespace Mcp\Http;

use Mcp\Config;

/**
 * Shared HTTP(S) fetch logic with SSRF guards, used by any tool that fetches
 * an arbitrary user-supplied URL (web_fetch, feed_discover, feed_fetch).
 *
 * Guards: only http/https, resolves the host once and pins curl to that IP via
 * CURLOPT_RESOLVE (so a later DNS answer can't rebind to a private address
 * after the check passes), rejects loopback/private/link-local addresses, and
 * follows redirects manually so every hop is re-validated the same way.
 */
final class SafeFetcher
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @return array{statusCode: int, headers: array<string, string>, body: string, url: string}
     */
    public function fetch(
        string $url,
        ?int $maxRedirects = null,
        ?int $timeoutSeconds = null,
        ?int $maxBytes = null
    ): array {
        $maxRedirects = $maxRedirects ?? (int) $this->config->get('fetch_max_redirects', 3);
        $timeoutSeconds = $timeoutSeconds ?? (int) $this->config->get('fetch_timeout', 10);
        $maxBytes = $maxBytes ?? (int) $this->config->get('fetch_max_bytes', 2 * 1024 * 1024);

        $current = $url;
        $statusCode = 0;
        $headers = [];
        $body = '';

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $resolvedIp = $this->resolveSafeIp($current);
            [$statusCode, $headers, $body] = $this->fetchOnce($current, $resolvedIp, $timeoutSeconds, $maxBytes);

            if ($statusCode >= 300 && $statusCode < 400 && isset($headers['location'])) {
                $current = $this->resolveRedirect($current, $headers['location']);
                continue;
            }

            break;
        }

        return ['statusCode' => $statusCode, 'headers' => $headers, 'body' => $body, 'url' => $current];
    }

    private function resolveRedirect(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        if (strpos($location, '/') === 0) {
            return "{$scheme}://{$host}{$port}{$location}";
        }
        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, strrpos($path, '/') + 1);
        return "{$scheme}://{$host}{$port}{$dir}{$location}";
    }

    /**
     * Validate scheme + host, resolve to an IP, and reject private/loopback/link-local
     * targets before any connection is made (SSRF guard).
     */
    private function resolveSafeIp(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException("Invalid URL: {$url}");
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException("Unsupported URL scheme: {$scheme}");
        }

        $host = $parts['host'];
        // parse_url() leaves the brackets on a literal IPv6 host (e.g. "[::1]"),
        // which filter_var()/inet_pton() don't accept — strip them before checking.
        $hostForIp = trim($host, '[]');
        $ip = filter_var($hostForIp, FILTER_VALIDATE_IP) ? $hostForIp : null;
        if ($ip === null) {
            $ips = gethostbynamel($host);
            if ($ips === false || count($ips) === 0) {
                throw new \RuntimeException("Could not resolve host: {$host}");
            }
            $ip = $ips[0];
        }

        if (!$this->isPublicIp($ip)) {
            throw new \RuntimeException("Refusing to fetch non-public address: {$host} ({$ip})");
        }

        return $ip;
    }

    private function isPublicIp(string $ip): bool
    {
        if (!$this->isPublicRange($ip)) {
            return false;
        }

        // PHP's filter_var doesn't flag IPv4-mapped/-compatible IPv6 addresses
        // (e.g. ::ffff:127.0.0.1, ::ffff:169.254.169.254) as private/reserved,
        // even though they route to the embedded IPv4 address — check that too.
        $embedded = $this->embeddedIpv4($ip);
        if ($embedded !== null && !$this->isPublicRange($embedded)) {
            return false;
        }

        return true;
    }

    private function isPublicRange(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private function embeddedIpv4(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        // ::ffff:0:0/96 (IPv4-mapped) or ::0:0/96 (IPv4-compatible, deprecated).
        $prefix = substr($packed, 0, 12);
        if ($prefix !== str_repeat("\0", 10) . "\xff\xff" && $prefix !== str_repeat("\0", 12)) {
            return null;
        }

        return inet_ntop(substr($packed, 12));
    }

    /**
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    private function fetchOnce(string $url, string $resolvedIp, int $timeoutSeconds, int $maxBytes): array
    {
        $parts = parse_url($url);
        $host = $parts['host'];
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => $this->config->userAgent(),
            CURLOPT_RANGE => '0-' . ($maxBytes - 1),
        ];

        // CURLOPT_RESOLVE pins a *hostname* lookup to the IP we already validated,
        // closing the DNS-rebinding window between that check and the connection.
        // When the URL's host is already a literal IP (bracketed IPv6 or plain
        // IPv4), curl never performs a DNS lookup for it in the first place — there
        // is no resolution step to pin, and CURLOPT_RESOLVE doesn't accept a
        // colon-bearing host token anyway (it would be ambiguous with the
        // HOST:PORT:ADDRESS delimiters), so it must be omitted in that case.
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) === false) {
            $options[CURLOPT_RESOLVE] = ["{$host}:{$port}:{$resolvedIp}"];
        }

        $ch = curl_init();
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Fetch failed: {$error}");
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);

        $headers = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }

        return [$statusCode, $headers, $body];
    }
}
