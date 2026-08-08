<?php
declare(strict_types=1);

use Mcp\Config;
use Mcp\Http\SafeFetcher;

$config = new Config([
    'user_agent' => 'test-agent',
    'fetch_max_redirects' => 3,
    'fetch_default_headers' => ['Accept' => 'text/html', 'Accept-Language' => 'en-US,en;q=0.9'],
]);
$fetcher = new SafeFetcher($config);

check('isPublicIp accepts a public address', invokePrivate($fetcher, 'isPublicIp', ['8.8.8.8']) === true);
check('isPublicIp rejects loopback', invokePrivate($fetcher, 'isPublicIp', ['127.0.0.1']) === false);
check('isPublicIp rejects private 10/8', invokePrivate($fetcher, 'isPublicIp', ['10.1.2.3']) === false);
check('isPublicIp rejects private 192.168/16', invokePrivate($fetcher, 'isPublicIp', ['192.168.1.1']) === false);
check('isPublicIp rejects link-local', invokePrivate($fetcher, 'isPublicIp', ['169.254.1.1']) === false);
check('isPublicIp accepts a public IPv6 address', invokePrivate($fetcher, 'isPublicIp', ['2001:4860:4860::8888']) === true);
check('isPublicIp rejects IPv6 loopback', invokePrivate($fetcher, 'isPublicIp', ['::1']) === false);
check(
    'isPublicIp rejects IPv4-mapped IPv6 loopback (::ffff:127.0.0.1)',
    invokePrivate($fetcher, 'isPublicIp', ['::ffff:127.0.0.1']) === false
);
check(
    'isPublicIp rejects IPv4-mapped IPv6 link-local metadata address',
    invokePrivate($fetcher, 'isPublicIp', ['::ffff:169.254.169.254']) === false
);
check(
    'isPublicIp rejects fully-expanded IPv4-mapped IPv6 loopback',
    invokePrivate($fetcher, 'isPublicIp', ['0:0:0:0:0:ffff:127.0.0.1']) === false
);
check(
    'isPublicIp accepts an IPv4-mapped IPv6 public address',
    invokePrivate($fetcher, 'isPublicIp', ['::ffff:8.8.8.8']) === true
);

$threw = false;
try {
    invokePrivate($fetcher, 'resolveSafeIp', ['http://[::ffff:127.0.0.1]/']);
} catch (\RuntimeException $e) {
    $threw = true;
}
check('resolveSafeIp rejects IPv4-mapped IPv6 loopback URL', $threw);

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

$normalized = invokePrivate($fetcher, 'normalizeHeaders', [['Accept-Language' => 'fr-FR', 'X-Test' => 'v']]);
check('normalizeHeaders lower-cases keys', $normalized === ['accept-language' => 'fr-FR', 'x-test' => 'v']);
check('normalizeHeaders drops a Host override', invokePrivate($fetcher, 'normalizeHeaders', [['Host' => 'evil.com']]) === []);

$threw = false;
try {
    invokePrivate($fetcher, 'normalizeHeaders', [["X-Test" => "bad\r\nX-Injected: yes"]]);
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
check('normalizeHeaders rejects CRLF injection', $threw);

check(
    'normalizeCookies accepts an object of name/value pairs',
    invokePrivate($fetcher, 'normalizeCookies', [['a' => '1', 'b' => '2']]) === 'a=1; b=2'
);
check(
    'normalizeCookies passes a string through trimmed',
    invokePrivate($fetcher, 'normalizeCookies', [' a=1; b=2 ']) === 'a=1; b=2'
);

$headerLines = invokePrivate($fetcher, 'buildRequestHeaders', [['x-test' => 'hello'], 'foo=bar']);
$headerText = implode("\n", $headerLines);
check('buildRequestHeaders includes the default Accept header', strpos($headerText, 'Accept:') !== false);
check('buildRequestHeaders includes the User-Agent', strpos($headerText, 'User-Agent: test-agent') !== false);
check('buildRequestHeaders includes caller headers', in_array('x-test: hello', $headerLines, true));
check('buildRequestHeaders folds the jar cookie into a Cookie line', in_array('Cookie: foo=bar', $headerLines, true));

$overrideLines = invokePrivate($fetcher, 'buildRequestHeaders', [['accept-language' => 'fr-FR'], '']);
check(
    'buildRequestHeaders lets caller headers override a default',
    in_array('Accept-Language: fr-FR', $overrideLines, true)
);

check(
    'mergeCookieJar folds new Set-Cookie values into the existing jar string',
    invokePrivate($fetcher, 'mergeCookieJar', ['a=1', ['b=2; Path=/', 'a=3; HttpOnly']]) === 'a=3; b=2'
);

$sameHost = invokePrivate($fetcher, 'stripCrossHostHeaders', [
    ['cookie' => 'sess=abc', 'authorization' => 'Bearer x', 'x-test' => 'v'],
    'example.com',
    'example.com',
]);
check(
    'stripCrossHostHeaders keeps cookie/authorization on the same host',
    $sameHost === ['cookie' => 'sess=abc', 'authorization' => 'Bearer x', 'x-test' => 'v']
);

$crossHost = invokePrivate($fetcher, 'stripCrossHostHeaders', [
    ['cookie' => 'sess=abc', 'authorization' => 'Bearer x', 'x-test' => 'v'],
    'evil.com',
    'example.com',
]);
check(
    'stripCrossHostHeaders drops cookie/authorization once the host changes',
    $crossHost === ['x-test' => 'v']
);
