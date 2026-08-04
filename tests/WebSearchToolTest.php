<?php
declare(strict_types=1);

use Mcp\Config;
use Mcp\Tools\WebSearchTool;

$config = new Config(['user_agent' => 'test-agent', 'search_default_max_results' => 8]);
$tool = new WebSearchTool($config);

$sampleHtml = <<<HTML
<div class="result results_links results_links_deep web-result">
  <a class="result__a" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fexample.com%2Fpage">Example Title</a>
  <a class="result__snippet">Example snippet text.</a>
</div>
HTML;

$results = invokePrivate($tool, 'parseResults', [$sampleHtml, 5]);
check('parseResults extracts one result', count($results) === 1);
check('parseResults extracts title', $results[0]['title'] === 'Example Title');
check('parseResults unwraps ddg redirect url', $results[0]['url'] === 'https://example.com/page');
check('parseResults extracts snippet', $results[0]['snippet'] === 'Example snippet text.');

$empty = invokePrivate($tool, 'parseResults', ['', 5]);
check('parseResults handles empty html', $empty === []);

$capped = invokePrivate($tool, 'parseResults', [str_repeat($sampleHtml, 3), 2]);
check('parseResults respects max_results', count($capped) === 2);

$braveTool = new WebSearchTool(new Config([
    'user_agent' => 'test-agent',
    'search_default_max_results' => 8,
    'search_provider' => 'brave',
]));

$braveJson = json_encode([
    'web' => [
        'results' => [
            ['title' => 'Brave Title', 'url' => 'https://example.com/brave', 'description' => 'A <strong>snippet</strong>.'],
            ['title' => 'PHP&#x27;s SDK', 'url' => 'https://example.com/second', 'description' => 'Uses &quot;quotes&quot; &amp; entities.'],
        ],
    ],
]);

$braveResults = invokePrivate($braveTool, 'parseBraveResults', [$braveJson, 5]);
check('parseBraveResults extracts both results', count($braveResults) === 2);
check('parseBraveResults extracts title', $braveResults[0]['title'] === 'Brave Title');
check('parseBraveResults extracts url', $braveResults[0]['url'] === 'https://example.com/brave');
check('parseBraveResults strips HTML from snippet', $braveResults[0]['snippet'] === 'A snippet.');
check('parseBraveResults decodes HTML entities in title', $braveResults[1]['title'] === "PHP's SDK");
check(
    'parseBraveResults decodes HTML entities in snippet',
    $braveResults[1]['snippet'] === 'Uses "quotes" & entities.'
);

$braveCapped = invokePrivate($braveTool, 'parseBraveResults', [$braveJson, 1]);
check('parseBraveResults respects max_results', count($braveCapped) === 1);

$braveEmpty = invokePrivate($braveTool, 'parseBraveResults', ['{"web":{}}', 5]);
check('parseBraveResults handles missing results', $braveEmpty === []);

$searchWithoutKey = new WebSearchTool(new Config([
    'user_agent' => 'test-agent',
    'search_default_max_results' => 8,
    'search_provider' => 'brave',
    'search_brave_api_key' => '',
]));
$missingKeyThrew = false;
try {
    $searchWithoutKey->call(['query' => 'test']);
} catch (\RuntimeException $e) {
    $missingKeyThrew = strpos($e->getMessage(), 'search_brave_api_key') !== false;
}
check('call() throws when brave provider has no api key configured', $missingKeyThrew);
