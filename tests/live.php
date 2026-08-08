<?php
declare(strict_types=1);

/**
 * End-to-end tests against a *running* instance (local dev server or a real
 * deployment) — unlike run.php, this makes real HTTP requests and hits real
 * SSRF-guard / auth / DuckDuckGo code paths.
 *
 * Usage:
 *   MCP_URL=http://127.0.0.1/tusk/ MCP_TOKEN=... php tests/live.php
 *
 * MCP_URL defaults to http://127.0.0.1:8080/ ; MCP_TOKEN is required.
 */

$baseUrl = rtrim(getenv('MCP_URL') ?: 'http://127.0.0.1:8080/', '/') . '/';
$rpcUrl = $baseUrl . 'mcp.php';
$token = getenv('MCP_TOKEN') ?: '';

if ($token === '') {
    fwrite(STDERR, "MCP_TOKEN env var is required (the bearer token configured on the target instance).\n");
    exit(1);
}

$failures = 0;
$passed = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "  ok - {$label}\n";
    } else {
        $failures++;
        echo "  FAIL - {$label}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
    }
}

/**
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function httpRequest(string $url, string $method, ?string $body = null, ?string $authToken = null): array
{
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if ($authToken !== null) {
        $headers[] = "Authorization: Bearer {$authToken}";
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 20,
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new \RuntimeException("Request to {$url} failed: {$error}");
    }

    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $rawHeaders = substr($raw, 0, $headerSize);
    $respBody = substr($raw, $headerSize);
    $respHeaders = [];
    foreach (explode("\r\n", $rawHeaders) as $line) {
        if (strpos($line, ':') !== false) {
            [$k, $v] = explode(':', $line, 2);
            $respHeaders[strtolower(trim($k))] = trim($v);
        }
    }

    return ['status' => $status, 'headers' => $respHeaders, 'body' => $respBody];
}

/**
 * @param array<string, mixed>|null $params
 */
function rpc(string $baseUrl, string $token, string $method, ?array $params = null, $id = 1): array
{
    $payload = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];
    if ($params !== null) {
        $payload['params'] = $params;
    }

    $resp = httpRequest($baseUrl, 'POST', json_encode($payload), $token);
    $decoded = json_decode($resp['body'], true);

    return ['status' => $resp['status'], 'json' => $decoded, 'raw' => $resp['body']];
}

echo "Target: {$baseUrl} (RPC endpoint: {$rpcUrl})\n\n";

echo "Health check\n";
$health = httpRequest($rpcUrl . '?health=1', 'GET');
check('GET mcp.php?health=1 returns 200', $health['status'] === 200, "got {$health['status']}");
check('GET mcp.php?health=1 body is "ok"', trim($health['body']) === 'ok', "got '" . trim($health['body']) . "'");

echo "\nSetup guide\n";
$guide = httpRequest($baseUrl, 'GET');
check('GET / returns 200', $guide['status'] === 200, "got {$guide['status']}");
check('GET / renders HTML guide', strpos($guide['body'], '<html') !== false, "got '" . substr($guide['body'], 0, 60) . "'");
check('guide lists web_fetch', strpos($guide['body'], 'web_fetch') !== false);
check('guide lists web_search', strpos($guide['body'], 'web_search') !== false);
check('guide points at the mcp.php endpoint', strpos($guide['body'], 'mcp.php') !== false);

echo "\nAuth\n";
$noAuth = httpRequest($rpcUrl, 'POST', json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));
check('missing bearer token -> 401', $noAuth['status'] === 401, "got {$noAuth['status']}");

$badAuth = httpRequest($rpcUrl, 'POST', json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']), 'wrong-token');
check('wrong bearer token -> 401', $badAuth['status'] === 401, "got {$badAuth['status']}");

$idAuth = httpRequest($rpcUrl, 'POST', json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']), "{$token}@test-client");
check('token with @id suffix -> 200', $idAuth['status'] === 200, "got {$idAuth['status']}");

echo "\nProtocol\n";
$malformed = httpRequest($rpcUrl, 'POST', 'not json', $token);
check('malformed JSON -> 400', $malformed['status'] === 400, "got {$malformed['status']}");

$init = rpc($rpcUrl, $token, 'initialize', []);
check('initialize succeeds', isset($init['json']['result']));
check(
    'initialize reports server name',
    ($init['json']['result']['serverInfo']['name'] ?? null) === 'tusk',
    json_encode($init['json'])
);

$notified = httpRequest(
    $rpcUrl,
    'POST',
    json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']),
    $token
);
check('notifications/initialized -> 204', $notified['status'] === 204, "got {$notified['status']}");

$unknownMethod = rpc($rpcUrl, $token, 'nonexistent/method');
check(
    'unknown method -> JSON-RPC error',
    isset($unknownMethod['json']['error']['code']),
    json_encode($unknownMethod['json'])
);

echo "\ntools/list\n";
$list = rpc($rpcUrl, $token, 'tools/list');
$toolNames = array_column($list['json']['result']['tools'] ?? [], 'name');
check('lists web_fetch', in_array('web_fetch', $toolNames, true), implode(',', $toolNames));
check('lists web_search', in_array('web_search', $toolNames, true), implode(',', $toolNames));
check('lists feed_discover', in_array('feed_discover', $toolNames, true), implode(',', $toolNames));
check('lists feed_fetch', in_array('feed_fetch', $toolNames, true), implode(',', $toolNames));
check('lists sitemap_fetch', in_array('sitemap_fetch', $toolNames, true), implode(',', $toolNames));
check('lists url_metadata', in_array('url_metadata', $toolNames, true), implode(',', $toolNames));

echo "\ntools/call web_fetch\n";
$fetch = rpc($rpcUrl, $token, 'tools/call', [
    'name' => 'web_fetch',
    'arguments' => ['url' => 'https://example.com', 'max_length' => 500],
]);
$fetchText = $fetch['json']['result']['content'][0]['text'] ?? '';
check('fetches example.com', strpos($fetchText, 'Example Domain') !== false, substr($fetchText, 0, 120));
check('fetch is not flagged as error', empty($fetch['json']['result']['isError']));

$ssrf = rpc($rpcUrl, $token, 'tools/call', [
    'name' => 'web_fetch',
    'arguments' => ['url' => 'http://127.0.0.1/'],
]);
check('blocks loopback URL', ($ssrf['json']['result']['isError'] ?? false) === true, json_encode($ssrf['json']));

$ssrfMapped = rpc($rpcUrl, $token, 'tools/call', [
    'name' => 'web_fetch',
    'arguments' => ['url' => 'http://[::ffff:127.0.0.1]/'],
]);
check(
    'blocks IPv4-mapped IPv6 loopback URL',
    ($ssrfMapped['json']['result']['isError'] ?? false) === true,
    json_encode($ssrfMapped['json'])
);

$badScheme = rpc($rpcUrl, $token, 'tools/call', [
    'name' => 'web_fetch',
    'arguments' => ['url' => 'file:///etc/passwd'],
]);
check('blocks non-http(s) scheme', ($badScheme['json']['result']['isError'] ?? false) === true, json_encode($badScheme['json']));

$headerEcho = rpc($rpcUrl, $token, 'tools/call', [
    'name' => 'web_fetch',
    'arguments' => [
        'url' => 'https://postman-echo.com/headers',
        'headers' => ['X-Tusk-Test' => 'live-header'],
        'cookies' => 'tusk_test=live-cookie',
    ],
]);
$headerEchoText = $headerEcho['json']['result']['content'][0]['text'] ?? '';
check('web_fetch sends a custom header', strpos($headerEchoText, 'x-tusk-test') !== false, substr($headerEchoText, 0, 300));
check('web_fetch sends the cookie', strpos($headerEchoText, 'tusk_test=live-cookie') !== false, substr($headerEchoText, 0, 300));

// Cookie must NOT survive a redirect to a different host (SSRF-adjacent
// session-leak guard — see SafeFetcher::stripCrossHostHeaders).
$crossHostRedirect = rpc($rpcUrl, $token, 'tools/call', [
    'name' => 'web_fetch',
    'arguments' => [
        'url' => 'https://postman-echo.com/redirect-to?url=' . urlencode('https://httpbingo.org/headers'),
        'cookies' => 'tusk_test=should-not-cross-hosts',
    ],
]);
$crossHostText = $crossHostRedirect['json']['result']['content'][0]['text'] ?? '';
check(
    'web_fetch does not replay the cookie across a cross-host redirect',
    strpos($crossHostText, 'should-not-cross-hosts') === false,
    substr($crossHostText, 0, 300)
);

echo "\ntools/call web_search\n";
$search = rpc($rpcUrl, $token, 'tools/call', [
    'name' => 'web_search',
    'arguments' => ['query' => 'PHP 7.4 release date', 'max_results' => 3],
]);
$searchText = $search['json']['result']['content'][0]['text'] ?? '';
check('search returns non-empty content', trim($searchText) !== '', 'empty response body');
check('search is not flagged as error', empty($search['json']['result']['isError']), $searchText);

echo "\ntools/call feed_discover\n";
$discover = rpc($rpcUrl, $token, 'tools/call', [
    'name' => 'feed_discover',
    'arguments' => ['url' => 'https://www.php.net/'],
]);
$discoverText = $discover['json']['result']['content'][0]['text'] ?? '';
$discoverFeeds = json_decode($discoverText, true);
check('feed_discover is not flagged as error', empty($discover['json']['result']['isError']), $discoverText);
check('feed_discover finds at least one feed on php.net', is_array($discoverFeeds) && count($discoverFeeds) > 0, $discoverText);
$discoveredUrls = is_array($discoverFeeds) ? array_column($discoverFeeds, 'url') : [];
check(
    'feed_discover finds the php.net atom feed',
    in_array('https://www.php.net/feed.atom', $discoveredUrls, true),
    implode(',', $discoveredUrls)
);

echo "\ntools/call feed_fetch\n";
$feedFetch = rpc($rpcUrl, $token, 'tools/call', [
    'name' => 'feed_fetch',
    'arguments' => ['url' => 'https://www.php.net/feed.atom', 'max_items' => 3],
]);
$feedFetchText = $feedFetch['json']['result']['content'][0]['text'] ?? '';
$feedFetchJson = json_decode($feedFetchText, true);
check('feed_fetch is not flagged as error', empty($feedFetch['json']['result']['isError']), $feedFetchText);
check('feed_fetch returns items', is_array($feedFetchJson['items'] ?? null) && count($feedFetchJson['items']) > 0, $feedFetchText);
check(
    'feed_fetch respects max_items',
    is_array($feedFetchJson['items'] ?? null) && count($feedFetchJson['items']) <= 3,
    $feedFetchText
);
check(
    'feed_fetch item has a title',
    !empty($feedFetchJson['items'][0]['title'] ?? ''),
    $feedFetchText
);

$feedFetchSsrf = rpc($rpcUrl, $token, 'tools/call', [
    'name' => 'feed_fetch',
    'arguments' => ['url' => 'http://127.0.0.1/'],
]);
check(
    'feed_fetch blocks loopback URL',
    ($feedFetchSsrf['json']['result']['isError'] ?? false) === true,
    json_encode($feedFetchSsrf['json'])
);

echo "\ntools/call sitemap_fetch\n";
$sitemap = rpc($rpcUrl, $token, 'tools/call', [
    'name' => 'sitemap_fetch',
    'arguments' => ['url' => 'https://www.php.net/sitemap.xml', 'max_urls' => 5],
]);
$sitemapText = $sitemap['json']['result']['content'][0]['text'] ?? '';
$sitemapJson = json_decode($sitemapText, true);
check('sitemap_fetch is not flagged as error', empty($sitemap['json']['result']['isError']), $sitemapText);
check('sitemap_fetch reports kind urlset', ($sitemapJson['kind'] ?? null) === 'urlset', $sitemapText);
check(
    'sitemap_fetch returns entries',
    is_array($sitemapJson['entries'] ?? null) && count($sitemapJson['entries']) > 0,
    $sitemapText
);
check(
    'sitemap_fetch respects max_urls',
    is_array($sitemapJson['entries'] ?? null) && count($sitemapJson['entries']) <= 5,
    $sitemapText
);
check(
    'sitemap_fetch entry has a url',
    !empty($sitemapJson['entries'][0]['url'] ?? ''),
    $sitemapText
);

$sitemapSsrf = rpc($rpcUrl, $token, 'tools/call', [
    'name' => 'sitemap_fetch',
    'arguments' => ['url' => 'http://127.0.0.1/'],
]);
check(
    'sitemap_fetch blocks loopback URL',
    ($sitemapSsrf['json']['result']['isError'] ?? false) === true,
    json_encode($sitemapSsrf['json'])
);

echo "\ntools/call url_metadata\n";
$metadata = rpc($rpcUrl, $token, 'tools/call', [
    'name' => 'url_metadata',
    'arguments' => ['url' => 'https://www.php.net/'],
]);
$metadataText = $metadata['json']['result']['content'][0]['text'] ?? '';
$metadataJson = json_decode($metadataText, true);
check('url_metadata is not flagged as error', empty($metadata['json']['result']['isError']), $metadataText);
check('url_metadata captures a title', !empty($metadataJson['title'] ?? ''), $metadataText);
check('url_metadata captures og:description', !empty($metadataJson['description'] ?? ''), $metadataText);
check(
    'url_metadata resolves og:image to an absolute URL',
    ($metadataJson['image'] ?? '') === 'https://www.php.net/images/meta-image.png',
    $metadataText
);

$metadataSsrf = rpc($rpcUrl, $token, 'tools/call', [
    'name' => 'url_metadata',
    'arguments' => ['url' => 'http://127.0.0.1/'],
]);
check(
    'url_metadata blocks loopback URL',
    ($metadataSsrf['json']['result']['isError'] ?? false) === true,
    json_encode($metadataSsrf['json'])
);

echo "\ntools/call errors\n";
$unknownTool = rpc($rpcUrl, $token, 'tools/call', ['name' => 'no_such_tool', 'arguments' => []]);
check('unknown tool -> JSON-RPC error', isset($unknownTool['json']['error']['code']), json_encode($unknownTool['json']));

$missingArg = rpc($rpcUrl, $token, 'tools/call', ['name' => 'web_fetch', 'arguments' => []]);
check('missing required arg -> isError', ($missingArg['json']['result']['isError'] ?? false) === true, json_encode($missingArg['json']));

echo "\n{$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
