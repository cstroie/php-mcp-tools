<?php
declare(strict_types=1);

namespace Mcp\Tools;

use andreskrey\Readability\Configuration;
use andreskrey\Readability\ParseException;
use andreskrey\Readability\Readability;
use Mcp\Config;
use Mcp\Http\SafeFetcher;
use Mcp\Text\MarkdownConverter;

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
        return 'Fetch a web page over HTTP(S) and return its content. For HTML pages, extracts '
            . 'the main article/content (via Readability) instead of the full page including '
            . 'navigation, ads, and boilerplate, and returns it as Markdown (headings, lists, '
            . 'links, code blocks preserved) — falling back to plain text of the raw page if no '
            . 'clear article content is found.';
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

        $text = $this->extractText($result['body'], $contentType, $result['url']);
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

    private function extractText(string $body, string $contentType, string $url): string
    {
        if (stripos($contentType, 'html') === false) {
            return trim($body);
        }

        $article = $this->extractArticle($body, $url);

        return $article ?? $this->htmlToPlainText($body);
    }

    /**
     * Uses Readability (Mozilla's Readability.js algorithm, ported to PHP by
     * andreskrey/readability.php) to isolate the main article content from
     * navigation/ads/boilerplate. Returns null if it can't find an article
     * (e.g. non-article pages like a homepage or search results) so the
     * caller can fall back to the whole page's text.
     */
    private function extractArticle(string $html, string $url): ?string
    {
        $configuration = new Configuration();
        $configuration->setFixRelativeURLs(true);
        $configuration->setOriginalURL($url);
        $configuration->setSummonCthulhu(true);

        $readability = new Readability($configuration);

        try {
            $readability->parse($html);
        } catch (ParseException $e) {
            return null;
        }

        $content = $readability->getContent();
        if ($content === null || trim($content) === '') {
            return null;
        }

        $markdown = MarkdownConverter::convert($content);
        if ($markdown === '') {
            return null;
        }

        $title = trim((string) $readability->getTitle());

        return $title !== '' ? "# {$title}\n\n{$markdown}" : $markdown;
    }

    private function htmlToPlainText(string $html): string
    {
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
        $text = preg_replace('#<!--.*?-->#s', ' ', $text ?? '');
        $text = preg_replace('#<[^>]+>#', ' ', $text ?? '');
        $text = html_entity_decode($text ?? '', ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n\s*\n+/', "\n\n", $text ?? '');

        return trim($text ?? '');
    }
}
