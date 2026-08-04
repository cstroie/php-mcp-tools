<?php
declare(strict_types=1);

namespace Mcp\Tools;

use Mcp\Config;

final class WebSearchTool implements ToolInterface
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function name(): string
    {
        return 'web_search';
    }

    public function description(): string
    {
        return 'Search the web (via Brave Search API or DuckDuckGo, depending on server config) '
            . 'and return a list of results (title, url, snippet).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'The search query.',
                ],
                'max_results' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of results to return (default '
                        . $this->config->get('search_default_max_results') . ').',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function call(array $arguments): array
    {
        $query = $arguments['query'] ?? null;
        if (!is_string($query) || trim($query) === '') {
            throw new \InvalidArgumentException('Missing required argument: query');
        }

        $maxResults = (int) ($arguments['max_results'] ?? $this->config->get('search_default_max_results'));

        $provider = strtolower((string) $this->config->get('search_provider', 'ddg'));
        if ($provider === 'brave') {
            $results = $this->searchBrave($query, $maxResults);
        } else {
            $html = $this->fetchResultsPage($query);
            $results = $this->parseResults($html, $maxResults);
        }

        if (empty($results)) {
            return [
                'content' => [
                    ['type' => 'text', 'text' => "No results found for: {$query}"],
                ],
            ];
        }

        $lines = [];
        foreach ($results as $i => $result) {
            $n = $i + 1;
            $lines[] = "{$n}. {$result['title']}\n   {$result['url']}\n   {$result['snippet']}";
        }

        return [
            'content' => [
                ['type' => 'text', 'text' => implode("\n\n", $lines)],
            ],
        ];
    }

    /**
     * @return array<int, array{title: string, url: string, snippet: string}>
     */
    private function searchBrave(string $query, int $maxResults): array
    {
        $apiKey = (string) $this->config->get('search_brave_api_key', '');
        if ($apiKey === '') {
            throw new \RuntimeException(
                'search_provider is set to "brave" but no search_brave_api_key is configured'
            );
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://api.search.brave.com/res/v1/web/search?q=' . rawurlencode($query)
                . '&count=' . max(1, min($maxResults, 20)),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) $this->config->get('search_timeout', 10),
            CURLOPT_CONNECTTIMEOUT => (int) $this->config->get('search_timeout', 10),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Accept-Encoding: gzip',
                'X-Subscription-Token: ' . $apiKey,
            ],
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Brave search request failed: {$error}");
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200) {
            throw new \RuntimeException("Brave search request failed with HTTP {$status}");
        }

        return $this->parseBraveResults($body, $maxResults);
    }

    /**
     * @return array<int, array{title: string, url: string, snippet: string}>
     */
    private function parseBraveResults(string $json, int $maxResults): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Brave search returned an unparseable response');
        }

        $entries = $data['web']['results'] ?? [];
        if (!is_array($entries)) {
            return [];
        }

        $results = [];
        foreach ($entries as $entry) {
            if (count($results) >= $maxResults) {
                break;
            }
            if (!is_array($entry)) {
                continue;
            }

            $title = trim((string) ($entry['title'] ?? ''));
            $url = trim((string) ($entry['url'] ?? ''));
            $snippet = trim((string) ($entry['description'] ?? ''));

            if ($title === '' || $url === '') {
                continue;
            }

            $results[] = ['title' => $title, 'url' => $url, 'snippet' => strip_tags($snippet)];
        }

        return $results;
    }

    private function fetchResultsPage(string $query): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://html.duckduckgo.com/html/?q=' . rawurlencode($query),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => (int) $this->config->get('search_timeout', 10),
            CURLOPT_CONNECTTIMEOUT => (int) $this->config->get('search_timeout', 10),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => $this->config->userAgent(),
            CURLOPT_HTTPHEADER => ['Accept-Language: en-US,en;q=0.9'],
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Search request failed: {$error}");
        }
        curl_close($ch);

        return $body;
    }

    /**
     * @return array<int, array{title: string, url: string, snippet: string}>
     */
    private function parseResults(string $html, int $maxResults): array
    {
        if (trim($html) === '') {
            return [];
        }

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' result ')]");

        $results = [];
        foreach ($nodes as $node) {
            if (count($results) >= $maxResults) {
                break;
            }

            $titleNode = $xpath->query(".//a[contains(concat(' ', normalize-space(@class), ' '), ' result__a ')]", $node)->item(0);
            $snippetNode = $xpath->query(".//a[contains(concat(' ', normalize-space(@class), ' '), ' result__snippet ')]", $node)->item(0);

            if ($titleNode === null) {
                continue;
            }

            $title = trim($titleNode->textContent);
            $href = trim($titleNode->getAttribute('href'));
            $url = $this->resolveResultUrl($href);
            $snippet = $snippetNode !== null ? trim($snippetNode->textContent) : '';

            if ($title === '' || $url === '') {
                continue;
            }

            $results[] = ['title' => $title, 'url' => $url, 'snippet' => $snippet];
        }

        return $results;
    }

    private function resolveResultUrl(string $href): string
    {
        // DuckDuckGo's HTML endpoint wraps result links as (//duckduckgo.com)/l/?uddg=<encoded target>.
        if (strpos(parse_url($href, PHP_URL_PATH) ?? '', '/l/') === 0) {
            $query = parse_url($href, PHP_URL_QUERY) ?: '';
            parse_str($query, $params);
            if (isset($params['uddg'])) {
                return $params['uddg'];
            }
        }

        if (strpos($href, '//') === 0) {
            return 'https:' . $href;
        }

        return $href;
    }
}
