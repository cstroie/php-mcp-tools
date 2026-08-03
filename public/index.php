<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use Mcp\Auth;
use Mcp\Config;
use Mcp\Guide;
use Mcp\JsonRpc;
use Mcp\Server;
use Mcp\ToolRegistry;
use Mcp\Tools\FeedDiscoverTool;
use Mcp\Tools\FeedFetchTool;
use Mcp\Tools\WebFetchTool;
use Mcp\Tools\WebSearchTool;

$config = Config::load();

$tools = new ToolRegistry();
$tools->register(new WebFetchTool($config));
$tools->register(new WebSearchTool($config));
$tools->register(new FeedDiscoverTool($config));
$tools->register(new FeedFetchTool($config));

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Unauthenticated, script-friendly health check.
    if (isset($_GET['health'])) {
        header('Content-Type: text/plain');
        echo "ok\n";
        exit;
    }

    // Plain browser visit — show a human-readable setup guide instead of an error.
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $endpointUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['SCRIPT_NAME'] ?? '/');
    header('Content-Type: text/html; charset=utf-8');
    echo Guide::render($tools, $endpointUrl);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(JsonRpc::error(null, JsonRpc::INVALID_REQUEST, 'Method not allowed'));
    exit;
}

if (!Auth::check($config)) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(JsonRpc::error(null, JsonRpc::INVALID_REQUEST, 'Unauthorized'));
    exit;
}

$body = file_get_contents('php://input') ?: '';
$request = JsonRpc::parse($body);

if ($request === null) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(JsonRpc::error(null, JsonRpc::PARSE_ERROR, 'Invalid JSON-RPC request'));
    exit;
}

$server = new Server($tools);
$response = $server->handle($request);

if ($response === null) {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');
echo json_encode($response);
