<?php
declare(strict_types=1);

namespace Mcp\Tools;

use Mcp\Config;

final class WebFetchTool implements ToolInterface
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function name(): string
    {
        return 'web_fetch';
    }

    public function description(): string
    {
        return 'Fetch a web page over HTTP(S) and return its text content.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'The absolute http:// or https:// URL to fetch.',
                ],
                'max_length' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of characters to return (default '
                        . $this->config->get('fetch_default_max_length') . ').',
                ],
            ],
            'required' => ['url'],
        ];
    }

    public function call(array $arguments): array
    {
        $url = $arguments['url'] ?? null;
        if (!is_string($url) || $url === '') {
            throw new \InvalidArgumentException('Missing required argument: url');
        }

        $maxLength = (int) ($arguments['max_length'] ?? $this->config->get('fetch_default_max_length'));
        $maxRedirects = (int) $this->config->get('fetch_max_redirects', 3);

        $current = $url;
        $body = '';
        $contentType = '';
        $finalUrl = $current;

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $resolved = $this->resolveSafeIp($current);
            [$statusCode, $headers, $body] = $this->fetchOnce($current, $resolved);
            $finalUrl = $current;

            if ($statusCode >= 300 && $statusCode < 400 && isset($headers['location'])) {
                $current = $this->resolveRedirect($current, $headers['location']);
                continue;
            }

            $contentType = $headers['content-type'] ?? '';
            break;
        }

        $text = $this->extractText($body, $contentType);
        $truncated = false;
        if (mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, $maxLength);
            $truncated = true;
        }

        $header = "Fetched: {$finalUrl}\n\n";
        if ($truncated) {
            $text .= "\n\n[...truncated]";
        }

        return [
            'content' => [
                ['type' => 'text', 'text' => $header . $text],
            ],
        ];
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
    private function fetchOnce(string $url, string $resolvedIp): array
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
            CURLOPT_TIMEOUT => (int) $this->config->get('fetch_timeout', 10),
            CURLOPT_CONNECTTIMEOUT => (int) $this->config->get('fetch_timeout', 10),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => $this->config->userAgent(),
            CURLOPT_RESOLVE => ["{$host}:{$port}:{$resolvedIp}"],
            CURLOPT_RANGE => '0-' . ((int) $this->config->get('fetch_max_bytes', 2 * 1024 * 1024) - 1),
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

    private function extractText(string $body, string $contentType): string
    {
        if (stripos($contentType, 'html') === false) {
            return trim($body);
        }

        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $body);
        $text = preg_replace('#<!--.*?-->#s', ' ', $text ?? '');
        $text = preg_replace('#<[^>]+>#', ' ', $text ?? '');
        $text = html_entity_decode($text ?? '', ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n\s*\n+/', "\n\n", $text ?? '');

        return trim($text ?? '');
    }
}
