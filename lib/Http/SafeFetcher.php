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
 *
 * Sends a browser-like default header set (Accept, Accept-Language,
 * Sec-Fetch-*, User-Agent) plus whatever headers/cookies a caller supplies, and
 * carries cookies (caller-supplied and Set-Cookie from responses) across a
 * redirect chain — but only ever to the host they were set for or supplied
 * for. Cookie/Authorization headers are dropped the moment a redirect crosses
 * to a different host, so a session cookie for the requested site can never
 * leak to a host a redirect happens to point at. None of this defeats an
 * actual JS-executed challenge (e.g. Cloudflare's managed challenge) — curl
 * doesn't run JS — but it does fix the much larger class of sites that just
 * 403 a request that doesn't look like a browser, or that need a
 * caller-supplied session/clearance cookie replayed.
 */
final class SafeFetcher
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @param array{headers?: array<string, string>, cookies?: string|array<string, string>} $options
     * @return array{statusCode: int, headers: array<string, string>, body: string, url: string}
     */
    public function fetch(
        string $url,
        ?int $maxRedirects = null,
        ?int $timeoutSeconds = null,
        ?int $maxBytes = null,
        array $options = []
    ): array {
        $maxRedirects = $maxRedirects ?? (int) $this->config->get('fetch_max_redirects', 3);
        $timeoutSeconds = $timeoutSeconds ?? (int) $this->config->get('fetch_timeout', 10);
        $maxBytes = $maxBytes ?? (int) $this->config->get('fetch_max_bytes', 2 * 1024 * 1024);

        $callerHeaders = $this->normalizeHeaders($options['headers'] ?? []);
        $initialHost = strtolower((string) (parse_url($url)['host'] ?? ''));

        // Per-host cookie jar: caller-supplied cookies seed the entry for the
        // originally requested host; Set-Cookie responses add to the entry for
        // whichever host actually sent them. Looking a hop's cookies up by its
        // own host (see below) is what keeps cookies from following a redirect
        // to a different host.
        $jar = [];
        if ($initialHost !== '') {
            $jar[$initialHost] = $this->normalizeCookies($options['cookies'] ?? '');
        }

        $current = $url;
        $statusCode = 0;
        $headers = [];
        $body = '';

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $resolvedIp = $this->resolveSafeIp($current);
            $host = strtolower((string) parse_url($current)['host']);

            $hopHeaders = $this->stripCrossHostHeaders($callerHeaders, $host, $initialHost);

            $requestHeaders = $this->buildRequestHeaders($hopHeaders, $jar[$host] ?? '');

            [$statusCode, $responseHeaders, $setCookies, $body] =
                $this->fetchOnce($current, $resolvedIp, $timeoutSeconds, $maxBytes, $requestHeaders);
            $headers = $responseHeaders;

            if ($setCookies !== []) {
                $jar[$host] = $this->mergeCookieJar($jar[$host] ?? '', $setCookies);
            }

            if ($statusCode >= 300 && $statusCode < 400 && isset($headers['location'])) {
                $current = $this->resolveRedirect($current, $headers['location']);
                continue;
            }

            break;
        }

        return ['statusCode' => $statusCode, 'headers' => $headers, 'body' => $body, 'url' => $current];
    }

    /**
     * Cookie/Authorization only ever go to the host they belong to — strip
     * them once a redirect has moved us off the originally requested host, so
     * a caller-supplied session cookie can't be replayed to a host a redirect
     * happens to point at.
     *
     * @param array<string, string> $headers lower-cased keys
     * @return array<string, string>
     */
    private function stripCrossHostHeaders(array $headers, string $host, string $initialHost): array
    {
        if ($host === $initialHost) {
            return $headers;
        }
        unset($headers['authorization'], $headers['cookie']);
        return $headers;
    }

    /**
     * Lower-cases caller header names so later merges/overrides are
     * case-insensitive, and rejects header-injection attempts and the one
     * header (Host) that would defeat CURLOPT_RESOLVE pinning.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new \InvalidArgumentException('Header names and values must be strings');
            }
            $key = strtolower(trim($name));
            if ($key === '' || $key === 'host') {
                continue;
            }
            if (preg_match('/[\r\n]/', $name . $value)) {
                throw new \InvalidArgumentException('Header names/values must not contain line breaks');
            }
            $normalized[$key] = $value;
        }
        return $normalized;
    }

    /**
     * @param string|array<string, string> $cookies
     */
    private function normalizeCookies($cookies): string
    {
        if (is_array($cookies)) {
            $pairs = [];
            foreach ($cookies as $name => $value) {
                $pairs[] = "{$name}={$value}";
            }
            $cookies = implode('; ', $pairs);
        }
        if (!is_string($cookies)) {
            throw new \InvalidArgumentException('cookies must be a string or an object of name/value pairs');
        }
        if (preg_match('/[\r\n]/', $cookies)) {
            throw new \InvalidArgumentException('cookies must not contain line breaks');
        }
        return trim($cookies);
    }

    /**
     * Merges the default browser-like header set, the caller's headers
     * (caller wins on conflicts), and the Cookie header built from this hop's
     * jar entry, into the final "Name: value" lines curl sends.
     *
     * @param array<string, string> $callerHeaders lower-cased keys
     * @return string[]
     */
    private function buildRequestHeaders(array $callerHeaders, string $cookieHeader): array
    {
        $defaults = [];
        foreach ($this->config->defaultHeaders() as $name => $value) {
            $defaults[strtolower($name)] = [$name, $value];
        }
        $defaults['user-agent'] = ['User-Agent', $this->config->userAgent()];

        $merged = [];
        foreach ($defaults as $key => [$name, $value]) {
            $merged[$key] = array_key_exists($key, $callerHeaders) ? $callerHeaders[$key] : $value;
        }
        foreach ($callerHeaders as $key => $value) {
            if (!isset($merged[$key])) {
                $merged[$key] = $value;
            }
        }

        // Prefer the caller's own casing for headers it supplied; fall back to
        // the canonical casing for our defaults.
        $canonicalNames = [];
        foreach ($defaults as $key => [$name, $value]) {
            $canonicalNames[$key] = $name;
        }

        $lines = [];
        foreach ($merged as $key => $value) {
            if ($key === 'cookie') {
                continue; // handled separately below, combined with the jar
            }
            $name = $canonicalNames[$key] ?? $key;
            $lines[] = "{$name}: {$value}";
        }

        $cookieParts = array_filter([$callerHeaders['cookie'] ?? '', $cookieHeader], fn($v) => $v !== '');
        if ($cookieParts !== []) {
            $lines[] = 'Cookie: ' . implode('; ', $cookieParts);
        }

        return $lines;
    }

    /**
     * Folds a batch of Set-Cookie response header values into an existing
     * "name=value; name2=value2" jar string for one host, replacing any
     * same-named cookie already there.
     *
     * @param string[] $setCookies
     */
    private function mergeCookieJar(string $existing, array $setCookies): string
    {
        $jar = [];
        foreach (explode('; ', $existing) as $pair) {
            if ($pair === '' || strpos($pair, '=') === false) {
                continue;
            }
            [$name, $value] = explode('=', $pair, 2);
            $jar[$name] = $value;
        }

        foreach ($setCookies as $setCookie) {
            $attr = explode(';', $setCookie, 2)[0];
            if (strpos($attr, '=') === false) {
                continue;
            }
            [$name, $value] = explode('=', trim($attr), 2);
            $jar[trim($name)] = trim($value);
        }

        $pairs = [];
        foreach ($jar as $name => $value) {
            $pairs[] = "{$name}={$value}";
        }
        return implode('; ', $pairs);
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
     * @param string[] $requestHeaders "Name: value" lines
     * @return array{0: int, 1: array<string, string>, 2: string[], 3: string}
     */
    private function fetchOnce(
        string $url,
        string $resolvedIp,
        int $timeoutSeconds,
        int $maxBytes,
        array $requestHeaders
    ): array {
        $parts = parse_url($url);
        $host = $parts['host'];
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        $headers = [];
        $setCookies = [];
        $body = '';
        $bodyBytes = 0;

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => $requestHeaders,
            // Advertises Accept-Encoding for us and transparently decompresses
            // the response — must not also set Accept-Encoding by hand above,
            // curl owns this header when CURLOPT_ENCODING is set.
            CURLOPT_ENCODING => '',
            CURLOPT_HEADER => false,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers, &$setCookies) {
                $trimmed = trim($line);
                if (stripos($trimmed, 'set-cookie:') === 0) {
                    $setCookies[] = trim(substr($trimmed, strlen('set-cookie:')));
                } elseif (strpos($trimmed, ':') !== false) {
                    [$k, $v] = explode(':', $trimmed, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
            // A hand-rolled cap (rather than CURLOPT_RANGE) so it applies to
            // the decompressed body regardless of whether the origin honors
            // Range at all, and so a server that ignores our size hint still
            // gets cut off instead of streaming an unbounded response. This is
            // a soft cap, not exact: a chunk is appended before we can decide
            // to abort, so the final body can run past maxBytes by up to one
            // chunk's worth (curl's/gzip's internal buffer size).
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, &$bodyBytes, $maxBytes) {
                $bodyBytes += strlen($chunk);
                $body .= $chunk;
                return ($chunk !== '' && $bodyBytes >= $maxBytes) ? 0 : strlen($chunk);
            },
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

        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        // CURLE_WRITE_ERROR (23): our own WRITEFUNCTION stopped the transfer
        // once maxBytes was hit — that's an intentional truncation, not a failure.
        if ($result === false && $errno !== CURLE_WRITE_ERROR) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Fetch failed: {$error}");
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$statusCode, $headers, $setCookies, $body];
    }
}
