<?php
declare(strict_types=1);

namespace Mcp\Tools;

use Mcp\Config;
use Mcp\Http\SafeFetcher;
use Mcp\Http\UrlResolver;

final class FeedDiscoverTool implements ToolInterface
{
    private const FEED_TYPES = [
        'application/rss+xml',
        'application/atom+xml',
        'application/feed+json',
        'application/json',
        'application/xml',
        'text/xml',
    ];

    private SafeFetcher $fetcher;

    public function __construct(Config $config)
    {
        $this->fetcher = new SafeFetcher($config);
    }

    public function name(): string
    {
        return 'feed_discover';
    }

    public function description(): string
    {
        return 'Fetch a web page and return the RSS/Atom feed URLs it advertises '
            . '(via <link rel="alternate"> tags).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'The absolute http:// or https:// URL of the page to scan.',
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

        $result = $this->fetcher->fetch($url);
        $feeds = $this->extractFeedLinks($result['body'], $result['url']);

        return [
            'content' => [
                ['type' => 'text', 'text' => json_encode($feeds, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)],
            ],
        ];
    }

    /**
     * @return array<int, array{url: string, type: string, title: string}>
     */
    private function extractFeedLinks(string $html, string $pageUrl): array
    {
        if (trim($html) === '') {
            return [];
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query('//link[@rel]');

        $feeds = [];
        $seen = [];
        foreach ($nodes as $node) {
            $rel = strtolower(trim($node->getAttribute('rel')));
            if ($rel !== 'alternate') {
                continue;
            }

            $type = strtolower(trim($node->getAttribute('type')));
            if (!in_array($type, self::FEED_TYPES, true)) {
                continue;
            }

            $href = trim($node->getAttribute('href'));
            if ($href === '') {
                continue;
            }

            $feedUrl = UrlResolver::resolve($pageUrl, $href);
            if (isset($seen[$feedUrl])) {
                continue;
            }
            $seen[$feedUrl] = true;

            $feeds[] = [
                'url' => $feedUrl,
                'type' => $type,
                'title' => trim($node->getAttribute('title')),
            ];
        }

        return $feeds;
    }
}
