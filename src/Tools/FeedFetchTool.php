<?php
declare(strict_types=1);

namespace Mcp\Tools;

use Mcp\Config;
use Mcp\Http\SafeFetcher;

final class FeedFetchTool implements ToolInterface
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
        return 'feed_fetch';
    }

    public function description(): string
    {
        return 'Fetch an RSS or Atom feed URL and return its items as JSON '
            . '(title, link, published date, summary).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'The absolute http:// or https:// URL of the RSS/Atom feed.',
                ],
                'max_items' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of items to return (default '
                        . $this->config->get('feed_default_max_items') . ').',
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

        $maxItems = (int) ($arguments['max_items'] ?? $this->config->get('feed_default_max_items'));

        $result = $this->fetcher->fetch($url);
        $items = $this->parseFeed($result['body'], $maxItems);

        $payload = ['feed_url' => $result['url'], 'items' => $items];

        return [
            'content' => [
                ['type' => 'text', 'text' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)],
            ],
        ];
    }

    /**
     * @return array<int, array{title: string, link: string, published: string, summary: string, id: string}>
     */
    private function parseFeed(string $xmlBody, int $maxItems): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlBody);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        if ($xml === false) {
            $message = $errors[0]->message ?? 'unknown parse error';
            throw new \RuntimeException('Could not parse feed XML: ' . trim($message));
        }

        $root = $xml->getName();
        if ($root === 'rss') {
            return $this->parseRss($xml, $maxItems);
        }
        if ($root === 'feed') {
            return $this->parseAtom($xml, $maxItems);
        }

        throw new \RuntimeException("Unsupported feed format: <{$root}> (expected <rss> or <feed>)");
    }

    /**
     * @return array<int, array{title: string, link: string, published: string, summary: string, id: string}>
     */
    private function parseRss(\SimpleXMLElement $xml, int $maxItems): array
    {
        $items = [];
        foreach ($xml->channel->item as $item) {
            if (count($items) >= $maxItems) {
                break;
            }
            $items[] = [
                'title' => trim((string) $item->title),
                'link' => trim((string) $item->link),
                'published' => trim((string) $item->pubDate),
                'summary' => trim((string) $item->description),
                'id' => trim((string) $item->guid),
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array{title: string, link: string, published: string, summary: string, id: string}>
     */
    private function parseAtom(\SimpleXMLElement $xml, int $maxItems): array
    {
        $items = [];
        foreach ($xml->entry as $entry) {
            if (count($items) >= $maxItems) {
                break;
            }

            $link = '';
            foreach ($entry->link as $linkNode) {
                $attrs = $linkNode->attributes();
                $rel = (string) ($attrs['rel'] ?? 'alternate');
                $href = (string) ($attrs['href'] ?? '');
                if ($href === '') {
                    continue;
                }
                if ($rel === 'alternate' || $link === '') {
                    $link = $href;
                }
                if ($rel === 'alternate') {
                    break;
                }
            }

            $published = (string) ($entry->published ?: $entry->updated);
            $summary = (string) ($entry->summary ?: $entry->content);

            $items[] = [
                'title' => trim((string) $entry->title),
                'link' => trim($link),
                'published' => trim($published),
                'summary' => trim($summary),
                'id' => trim((string) $entry->id),
            ];
        }

        return $items;
    }
}
