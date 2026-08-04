<?php
declare(strict_types=1);

namespace Mcp\Tools;

use Mcp\Config;
use Mcp\Http\SafeFetcher;
use Mcp\Http\UrlResolver;

final class UrlMetadataTool implements ToolInterface
{
    private SafeFetcher $fetcher;

    public function __construct(Config $config)
    {
        $this->fetcher = new SafeFetcher($config);
    }

    public function name(): string
    {
        return 'url_metadata';
    }

    public function description(): string
    {
        return 'Fetch a web page and return its metadata: title, description, Open Graph / '
            . 'Twitter Card tags, favicon, and canonical URL — without fetching the full body text.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'The absolute http:// or https:// URL of the page to inspect.',
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
        $metadata = $this->extractMetadata($result['body'], $result['url']);

        return [
            'content' => [
                ['type' => 'text', 'text' => json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)],
            ],
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function extractMetadata(string $html, string $pageUrl): array
    {
        $meta = [];
        $links = [];

        if (trim($html) !== '') {
            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
            libxml_clear_errors();

            $xpath = new \DOMXPath($dom);

            foreach ($xpath->query('//meta[@property or @name]') as $node) {
                $key = strtolower(trim($node->getAttribute('property') ?: $node->getAttribute('name')));
                $content = trim($node->getAttribute('content'));
                if ($key !== '' && $content !== '' && !isset($meta[$key])) {
                    $meta[$key] = $content;
                }
            }

            foreach ($xpath->query('//link[@rel]') as $node) {
                $rel = strtolower(trim($node->getAttribute('rel')));
                $href = trim($node->getAttribute('href'));
                if ($href !== '' && !isset($links[$rel])) {
                    $links[$rel] = UrlResolver::resolve($pageUrl, $href);
                }
            }

            $titleNode = $xpath->query('//title')->item(0);
            $htmlTitle = $titleNode !== null ? trim($titleNode->textContent) : '';
        } else {
            $htmlTitle = '';
        }

        $favicon = $links['icon'] ?? $links['shortcut icon'] ?? $links['apple-touch-icon'] ?? null;
        if ($favicon === null) {
            $origin = parse_url($pageUrl, PHP_URL_SCHEME) . '://' . parse_url($pageUrl, PHP_URL_HOST);
            $favicon = $origin . '/favicon.ico';
        }

        $image = $meta['og:image'] ?? $meta['twitter:image'] ?? null;
        if ($image !== null) {
            $image = UrlResolver::resolve($pageUrl, $image);
        }

        return [
            'url' => $pageUrl,
            'title' => $meta['og:title'] ?? ($htmlTitle !== '' ? $htmlTitle : null),
            'description' => $meta['og:description'] ?? $meta['description'] ?? null,
            'site_name' => $meta['og:site_name'] ?? null,
            'type' => $meta['og:type'] ?? null,
            'image' => $image,
            'favicon' => $favicon,
            'canonical_url' => $links['canonical'] ?? null,
        ];
    }
}
