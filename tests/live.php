<?php
declare(strict_types=1);

/**
 * End-to-end tests against a *running* instance (local dev server or a real
 * deployment) — unlike run.php, this makes real HTTP requests and hits real
 * SSRF-guard / auth / DuckDuckGo code paths.
 *
 * Usage:
 *   MCP_URL=http://127.0.0.1/mcp-tools/ MCP_TOKEN=... php tests/live.php
 *
 * MCP_URL defaults to http://127.0.0.1:8080/ ; MCP_TOKEN is required.
 */

$baseUrl = rtrim(getenv('MCP_URL') ?: 'http://127.0.0.1:8080/', '/') . '/';
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

echo "Target: {$baseUrl}\n\n";

echo "Health check\n";
$health = httpRequest($baseUrl . '?health=1', 'GET');
check('GET ?health=1 returns 200', $health['status'] === 200, "got {$health['status']}");
check('GET ?health=1 body is "ok"', trim($health['body']) === 'ok', "got '" . trim($health['body']) . "'");

echo "\nSetup guide\n";
$guide = httpRequest($baseUrl, 'GET');
check('GET / returns 200', $guide['status'] === 200, "got {$guide['status']}");
check('GET / renders HTML guide', strpos($guide['body'], '<html') !== false, "got '" . substr($guide['body'], 0, 60) . "'");
check('guide lists web_fetch', strpos($guide['body'], 'web_fetch') !== false);
check('guide lists web_search', strpos($guide['body'], 'web_search') !== false);

echo "\nAuth\n";
$noAuth = httpRequest($baseUrl, 'POST', json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));
check('missing bearer token -> 401', $noAuth['status'] === 401, "got {$noAuth['status']}");

$badAuth = httpRequest($baseUrl, 'POST', json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']), 'wrong-token');
check('wrong bearer token -> 401', $badAuth['status'] === 401, "got {$badAuth['status']}");

echo "\nProtocol\n";
$malformed = httpRequest($baseUrl, 'POST', 'not json', $token);
check('malformed JSON -> 400', $malformed['status'] === 400, "got {$malformed['status']}");

$init = rpc($baseUrl, $token, 'initialize', []);
check('initialize succeeds', isset($init['json']['result']));
check(
    'initialize reports server name',
    ($init['json']['result']['serverInfo']['name'] ?? null) === 'php-mcp-tools',
    json_encode($init['json'])
);

$notified = httpRequest(
    $baseUrl,
    'POST',
    json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']),
    $token
);
check('notifications/initialized -> 204', $notified['status'] === 204, "got {$notified['status']}");

$unknownMethod = rpc($baseUrl, $token, 'nonexistent/method');
check(
    'unknown method -> JSON-RPC error',
    isset($unknownMethod['json']['error']['code']),
    json_encode($unknownMethod['json'])
);

echo "\ntools/list\n";
$list = rpc($baseUrl, $token, 'tools/list');
$toolNames = array_column($list['json']['result']['tools'] ?? [], 'name');
check('lists web_fetch', in_array('web_fetch', $toolNames, true), implode(',', $toolNames));
check('lists web_search', in_array('web_search', $toolNames, true), implode(',', $toolNames));

echo "\ntools/call web_fetch\n";
$fetch = rpc($baseUrl, $token, 'tools/call', [
    'name' => 'web_fetch',
    'arguments' => ['url' => 'https://example.com', 'max_length' => 500],
]);
$fetchText = $fetch['json']['result']['content'][0]['text'] ?? '';
check('fetches example.com', strpos($fetchText, 'Example Domain') !== false, substr($fetchText, 0, 120));
check('fetch is not flagged as error', empty($fetch['json']['result']['isError']));

$ssrf = rpc($baseUrl, $token, 'tools/call', [
    'name' => 'web_fetch',
    'arguments' => ['url' => 'http://127.0.0.1/'],
]);
check('blocks loopback URL', ($ssrf['json']['result']['isError'] ?? false) === true, json_encode($ssrf['json']));

$badScheme = rpc($baseUrl, $token, 'tools/call', [
    'name' => 'web_fetch',
    'arguments' => ['url' => 'file:///etc/passwd'],
]);
check('blocks non-http(s) scheme', ($badScheme['json']['result']['isError'] ?? false) === true, json_encode($badScheme['json']));

echo "\ntools/call web_search\n";
$search = rpc($baseUrl, $token, 'tools/call', [
    'name' => 'web_search',
    'arguments' => ['query' => 'PHP 7.4 release date', 'max_results' => 3],
]);
$searchText = $search['json']['result']['content'][0]['text'] ?? '';
check('search returns non-empty content', trim($searchText) !== '', 'empty response body');
check('search is not flagged as error', empty($search['json']['result']['isError']), $searchText);

echo "\ntools/call errors\n";
$unknownTool = rpc($baseUrl, $token, 'tools/call', ['name' => 'no_such_tool', 'arguments' => []]);
check('unknown tool -> JSON-RPC error', isset($unknownTool['json']['error']['code']), json_encode($unknownTool['json']));

$missingArg = rpc($baseUrl, $token, 'tools/call', ['name' => 'web_fetch', 'arguments' => []]);
check('missing required arg -> isError', ($missingArg['json']['result']['isError'] ?? false) === true, json_encode($missingArg['json']));

echo "\n{$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
