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
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : null;
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
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
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

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => $this->config->userAgent(),
            CURLOPT_RESOLVE => ["{$host}:{$port}:{$resolvedIp}"],
            CURLOPT_RANGE => '0-' . ($maxBytes - 1),
        ]);

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
