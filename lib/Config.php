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
            'fetch_timeout' => 10,
            'fetch_max_bytes' => 2 * 1024 * 1024,
            'fetch_max_redirects' => 3,
            'fetch_default_max_length' => 8000,
            'search_timeout' => 10,
            'search_default_max_results' => 8,
            'search_provider' => getenv('SEARCH_PROVIDER') ?: 'ddg',
            'search_brave_api_key' => getenv('BRAVE_API_KEY') ?: '',

            'feed_default_max_items' => 20,

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
}
