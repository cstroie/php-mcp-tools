<?php
declare(strict_types=1);

use Mcp\Config;
use Mcp\Tools\WebFetchTool;

function invokePrivate(object $obj, string $method, array $args = [])
{
    $ref = new ReflectionMethod($obj, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs($obj, $args);
}

$config = new Config(['user_agent' => 'test-agent', 'fetch_max_redirects' => 3]);
$tool = new WebFetchTool($config);

check('isPublicIp accepts a public address', invokePrivate($tool, 'isPublicIp', ['8.8.8.8']) === true);
check('isPublicIp rejects loopback', invokePrivate($tool, 'isPublicIp', ['127.0.0.1']) === false);
check('isPublicIp rejects private 10/8', invokePrivate($tool, 'isPublicIp', ['10.1.2.3']) === false);
check('isPublicIp rejects private 192.168/16', invokePrivate($tool, 'isPublicIp', ['192.168.1.1']) === false);
check('isPublicIp rejects link-local', invokePrivate($tool, 'isPublicIp', ['169.254.1.1']) === false);

$threw = false;
try {
    invokePrivate($tool, 'resolveSafeIp', ['http://127.0.0.1/']);
} catch (\RuntimeException $e) {
    $threw = true;
}
check('resolveSafeIp rejects loopback URL', $threw);

$threw = false;
try {
    invokePrivate($tool, 'resolveSafeIp', ['file:///etc/passwd']);
} catch (\RuntimeException $e) {
    $threw = true;
}
check('resolveSafeIp rejects non-http(s) scheme', $threw);

$html = '<html><head><style>.x{}</style></head><body><h1>Hi &amp; welcome</h1><p>Text</p></body></html>';
$text = invokePrivate($tool, 'extractText', [$html, 'text/html']);
check('extractText strips tags', strpos($text, '<') === false);
check('extractText decodes entities', strpos($text, 'Hi & welcome') !== false);

$plain = invokePrivate($tool, 'extractText', ['raw text', 'text/plain']);
check('extractText passes through non-html content', $plain === 'raw text');
