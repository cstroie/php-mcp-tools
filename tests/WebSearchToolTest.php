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
