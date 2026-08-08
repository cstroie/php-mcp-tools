<?php
declare(strict_types=1);

namespace Mcp;

final class Config
{
    /** @var array<string, mixed> */
    private array $values;

    public function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function load(): self
    {
        $defaults = [
            'token' => getenv('MCP_TOKEN') ?: '',
            'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            // Sent on every SafeFetcher request so a plain curl-shaped request doesn't
            // stand out to sites that check for a browser-like header set (Accept-Encoding
            // is deliberately absent here — CURLOPT_ENCODING owns that header so curl can
            // transparently decompress the response).
            'fetch_default_headers' => [
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,'
                    . 'image/avif,image/webp,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Upgrade-Insecure-Requests' => '1',
                'Sec-Fetch-Dest' => 'document',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'none',
                'Sec-Fetch-User' => '?1',
            ],
            'fetch_timeout' => 10,
            'fetch_max_bytes' => 2 * 1024 * 1024,
            'fetch_max_redirects' => 3,
            'fetch_default_max_length' => 8000,
            'search_timeout' => 10,
            'search_default_max_results' => 8,
            'search_provider' => getenv('SEARCH_PROVIDER') ?: 'ddg',
            'search_brave_api_key' => getenv('BRAVE_API_KEY') ?: '',

            'feed_default_max_items' => 20,
            'sitemap_default_max_urls' => 50,

            'cors_allow_origin' => '*',
        ];

        $file = __DIR__ . '/../config.php';
        $overrides = is_file($file) ? require $file : [];
        if (!is_array($overrides)) {
            $overrides = [];
        }

        return new self(array_merge($defaults, $overrides));
    }

    public function get(string $key, $default = null)
    {
        return $this->values[$key] ?? $default;
    }

    public function token(): string
    {
        return (string) $this->get('token', '');
    }

    public function userAgent(): string
    {
        return (string) $this->get('user_agent');
    }

    /**
     * @return array<string, string>
     */
    public function defaultHeaders(): array
    {
        $headers = $this->get('fetch_default_headers', []);
        return is_array($headers) ? $headers : [];
    }
}
