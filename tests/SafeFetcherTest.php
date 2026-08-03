<?php
declare(strict_types=1);

use Mcp\Config;
use Mcp\Http\SafeFetcher;

$config = new Config(['user_agent' => 'test-agent', 'fetch_max_redirects' => 3]);
$fetcher = new SafeFetcher($config);

check('isPublicIp accepts a public address', invokePrivate($fetcher, 'isPublicIp', ['8.8.8.8']) === true);
check('isPublicIp rejects loopback', invokePrivate($fetcher, 'isPublicIp', ['127.0.0.1']) === false);
check('isPublicIp rejects private 10/8', invokePrivate($fetcher, 'isPublicIp', ['10.1.2.3']) === false);
check('isPublicIp rejects private 192.168/16', invokePrivate($fetcher, 'isPublicIp', ['192.168.1.1']) === false);
check('isPublicIp rejects link-local', invokePrivate($fetcher, 'isPublicIp', ['169.254.1.1']) === false);

$threw = false;
try {
    invokePrivate($fetcher, 'resolveSafeIp', ['http://127.0.0.1/']);
} catch (\RuntimeException $e) {
    $threw = true;
}
check('resolveSafeIp rejects loopback URL', $threw);

$threw = false;
try {
    invokePrivate($fetcher, 'resolveSafeIp', ['file:///etc/passwd']);
} catch (\RuntimeException $e) {
    $threw = true;
}
check('resolveSafeIp rejects non-http(s) scheme', $threw);

check(
    'resolveRedirect resolves an absolute Location',
    invokePrivate($fetcher, 'resolveRedirect', ['https://example.com/a/b', 'https://other.com/x'])
        === 'https://other.com/x'
);
check(
    'resolveRedirect resolves a root-relative Location',
    invokePrivate($fetcher, 'resolveRedirect', ['https://example.com/a/b', '/c'])
        === 'https://example.com/c'
);
check(
    'resolveRedirect resolves a relative Location',
    invokePrivate($fetcher, 'resolveRedirect', ['https://example.com/a/b', 'c'])
        === 'https://example.com/a/c'
);
