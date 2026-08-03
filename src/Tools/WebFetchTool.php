<?php
declare(strict_types=1);

namespace Mcp\Tools;

use Mcp\Config;
use Mcp\Http\SafeFetcher;

final class WebFetchTool implements ToolInterface
{
    private Config $config;
    private SafeFetcher $fetcher;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->fetcher = new SafeFetcher($config);
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

        $result = $this->fetcher->fetch($url);
        $contentType = $result['headers']['content-type'] ?? '';

        $text = $this->extractText($result['body'], $contentType);
        $truncated = false;
        if (mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, $maxLength);
            $truncated = true;
        }

        $header = "Fetched: {$result['url']}\n\n";
        if ($truncated) {
            $text .= "\n\n[...truncated]";
        }

        return [
            'content' => [
                ['type' => 'text', 'text' => $header . $text],
            ],
        ];
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
