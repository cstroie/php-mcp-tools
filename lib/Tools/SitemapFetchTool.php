<?php
declare(strict_types=1);

namespace Mcp\Tools;

use Mcp\Config;
use Mcp\Http\SafeFetcher;

final class SitemapFetchTool implements ToolInterface
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
        return 'sitemap_fetch';
    }

    public function description(): string
    {
        return 'Fetch a sitemap.xml URL and return its entries as JSON (url, lastmod, changefreq, '
            . 'priority). If the URL is a sitemap index instead, returns the list of sub-sitemap '
            . 'URLs (url, lastmod) — call sitemap_fetch again on one of those to get its entries.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'The absolute http:// or https:// URL of the sitemap XML file.',
                ],
                'max_urls' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of entries to return (default '
                        . $this->config->get('sitemap_default_max_urls') . ').',
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

        $maxUrls = (int) ($arguments['max_urls'] ?? $this->config->get('sitemap_default_max_urls'));

        $result = $this->fetcher->fetch($url);
        [$kind, $entries] = $this->parseSitemap($result['body'], $maxUrls);

        $payload = ['sitemap_url' => $result['url'], 'kind' => $kind, 'entries' => $entries];

        return [
            'content' => [
                ['type' => 'text', 'text' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)],
            ],
        ];
    }

    /**
     * @return array{0: string, 1: array<int, array<string, string>>}
     */
    private function parseSitemap(string $xmlBody, int $maxUrls): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlBody);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        if ($xml === false) {
            $message = $errors[0]->message ?? 'unknown parse error';
            throw new \RuntimeException('Could not parse sitemap XML: ' . trim($message));
        }

        $root = $xml->getName();
        if ($root === 'urlset') {
            return ['urlset', $this->parseUrlset($xml, $maxUrls)];
        }
        if ($root === 'sitemapindex') {
            return ['sitemapindex', $this->parseSitemapIndex($xml, $maxUrls)];
        }

        throw new \RuntimeException("Unsupported sitemap format: <{$root}> (expected <urlset> or <sitemapindex>)");
    }

    /**
     * @return array<int, array{url: string, lastmod: string, changefreq: string, priority: string}>
     */
    private function parseUrlset(\SimpleXMLElement $xml, int $maxUrls): array
    {
        $entries = [];
        foreach ($xml->url as $entry) {
            if (count($entries) >= $maxUrls) {
                break;
            }
            $entries[] = [
                'url' => trim((string) $entry->loc),
                'lastmod' => trim((string) $entry->lastmod),
                'changefreq' => trim((string) $entry->changefreq),
                'priority' => trim((string) $entry->priority),
            ];
        }

        return $entries;
    }

    /**
     * @return array<int, array{url: string, lastmod: string}>
     */
    private function parseSitemapIndex(\SimpleXMLElement $xml, int $maxUrls): array
    {
        $entries = [];
        foreach ($xml->sitemap as $entry) {
            if (count($entries) >= $maxUrls) {
                break;
            }
            $entries[] = [
                'url' => trim((string) $entry->loc),
                'lastmod' => trim((string) $entry->lastmod),
            ];
        }

        return $entries;
    }
}
