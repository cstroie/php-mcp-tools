#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Minimal CLI client for a running php-mcp-tools instance — talks JSON-RPC 2.0
 * over HTTP, same protocol any MCP client speaks. Handy for manual testing and
 * everyday use without hand-writing curl/JSON.
 *
 * Usage:
 *   mcp-cli.php list
 *   mcp-cli.php call <tool> ['{"arg":"value"}']
 *   mcp-cli.php init
 *   mcp-cli.php health
 *   mcp-cli.php raw <method> ['{"param":"value"}']
 *
 * Config: --url=<url> / --token=<token> flags, or MCP_URL / MCP_TOKEN env vars.
 * MCP_URL defaults to http://127.0.0.1:8080/.
 */

function usage(): void
{
    fwrite(STDERR, <<<TXT
    Usage: mcp-cli.php [--url=URL] [--token=TOKEN] <command> [args]

    Commands:
      list                       List available tools
      call <tool> [json-args]    Call a tool (json-args defaults to {})
      init                       Send an MCP initialize request
      health                     Hit the unauthenticated health check
      raw <method> [json-params] Send an arbitrary JSON-RPC method

    Config (flags override env vars):
      --url=URL      MCP server endpoint (env: MCP_URL, default http://127.0.0.1:8080/)
      --token=TOKEN  Bearer token (env: MCP_TOKEN)

    Examples:
      mcp-cli.php list
      mcp-cli.php call web_fetch '{"url":"https://example.com"}'
      mcp-cli.php call feed_fetch '{"url":"https://www.php.net/feed.atom","max_items":3}'

    TXT);
}

/**
 * @param array<int, string> $argv
 * @return array{options: array<string, string>, positional: array<int, string>}
 */
function parseArgs(array $argv): array
{
    $options = [];
    $positional = [];
    foreach ($argv as $arg) {
        if (strpos($arg, '--url=') === 0) {
            $options['url'] = substr($arg, 6);
        } elseif (strpos($arg, '--token=') === 0) {
            $options['token'] = substr($arg, 8);
        } elseif ($arg === '--help' || $arg === '-h') {
            $options['help'] = '1';
        } else {
            $positional[] = $arg;
        }
    }

    return ['options' => $options, 'positional' => $positional];
}

/**
 * @return array{status: int, body: string}
 */
function httpRequest(string $url, string $method, ?string $body, ?string $token): array
{
    $ch = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = "Authorization: Bearer {$token}";
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);

    $respBody = curl_exec($ch);
    if ($respBody === false) {
        $error = curl_error($ch);
        curl_close($ch);
        fwrite(STDERR, "Request failed: {$error}\n");
        exit(1);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'body' => (string) $respBody];
}

/**
 * @param array<string, mixed>|null $params
 * @return array<string, mixed>
 */
function rpc(string $baseUrl, ?string $token, string $method, ?array $params = null): array
{
    $payload = ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method];
    if ($params !== null) {
        $payload['params'] = $params;
    }

    $resp = httpRequest($baseUrl, 'POST', json_encode($payload), $token);

    if ($resp['status'] === 401) {
        fwrite(STDERR, "Unauthorized (401) — check --token / MCP_TOKEN.\n");
        exit(1);
    }

    $decoded = json_decode($resp['body'], true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "Non-JSON response (HTTP {$resp['status']}):\n{$resp['body']}\n");
        exit(1);
    }

    if (isset($decoded['error'])) {
        $message = $decoded['error']['message'] ?? 'unknown error';
        fwrite(STDERR, "JSON-RPC error: {$message}\n");
        exit(1);
    }

    return $decoded['result'] ?? [];
}

function printJson($value): void
{
    echo json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

$argv = array_slice($_SERVER['argv'], 1);
['options' => $options, 'positional' => $positional] = parseArgs($argv);

if (isset($options['help']) || count($positional) === 0) {
    usage();
    exit(isset($options['help']) ? 0 : 1);
}

$baseUrl = rtrim($options['url'] ?? getenv('MCP_URL') ?: 'http://127.0.0.1:8080/', '/') . '/';
$token = $options['token'] ?? (getenv('MCP_TOKEN') ?: null);

$command = array_shift($positional);

switch ($command) {
    case 'health':
        $resp = httpRequest($baseUrl . '?health=1', 'GET', null, null);
        echo trim($resp['body']) . "\n";
        exit($resp['status'] === 200 ? 0 : 1);

    case 'init':
        printJson(rpc($baseUrl, $token, 'initialize', []));
        break;

    case 'list':
        $result = rpc($baseUrl, $token, 'tools/list');
        foreach ($result['tools'] ?? [] as $tool) {
            echo "{$tool['name']}\n  {$tool['description']}\n\n";
        }
        break;

    case 'call':
        $toolName = array_shift($positional);
        if ($toolName === null) {
            fwrite(STDERR, "Usage: mcp-cli.php call <tool> [json-args]\n");
            exit(1);
        }
        $argsJson = $positional[0] ?? '{}';
        $arguments = json_decode($argsJson, true);
        if (!is_array($arguments)) {
            fwrite(STDERR, "Invalid JSON for tool arguments: {$argsJson}\n");
            exit(1);
        }

        $result = rpc($baseUrl, $token, 'tools/call', ['name' => $toolName, 'arguments' => $arguments]);
        $isError = $result['isError'] ?? false;
        foreach ($result['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                echo $block['text'] . "\n";
            } else {
                printJson($block);
            }
        }
        exit($isError ? 1 : 0);

    case 'raw':
        $method = array_shift($positional);
        if ($method === null) {
            fwrite(STDERR, "Usage: mcp-cli.php raw <method> [json-params]\n");
            exit(1);
        }
        $paramsJson = $positional[0] ?? null;
        $params = $paramsJson !== null ? json_decode($paramsJson, true) : null;
        if ($paramsJson !== null && !is_array($params)) {
            fwrite(STDERR, "Invalid JSON for params: {$paramsJson}\n");
            exit(1);
        }
        printJson(rpc($baseUrl, $token, $method, $params));
        break;

    default:
        fwrite(STDERR, "Unknown command: {$command}\n\n");
        usage();
        exit(1);
}
