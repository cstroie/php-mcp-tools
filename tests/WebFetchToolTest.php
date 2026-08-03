<?php
declare(strict_types=1);

use Mcp\Config;
use Mcp\Tools\WebFetchTool;

$config = new Config(['user_agent' => 'test-agent', 'fetch_max_redirects' => 3]);
$tool = new WebFetchTool($config);

$html = '<html><head><style>.x{}</style></head><body><h1>Hi &amp; welcome</h1><p>Text</p></body></html>';
$text = invokePrivate($tool, 'extractText', [$html, 'text/html']);
check('extractText strips tags', strpos($text, '<') === false);
check('extractText decodes entities', strpos($text, 'Hi & welcome') !== false);

$plain = invokePrivate($tool, 'extractText', ['raw text', 'text/plain']);
check('extractText passes through non-html content', $plain === 'raw text');
