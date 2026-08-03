<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use Mcp\Auth;
use Mcp\Config;
use Mcp\JsonRpc;
use Mcp\Server;
use Mcp\ToolRegistry;
use Mcp\Tools\WebFetchTool;
use Mcp\Tools\WebSearchTool;

$config = Config::load();

// Unauthenticated health check.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/plain');
    echo "ok\n";
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

$tools = new ToolRegistry();
$tools->register(new WebFetchTool($config));
$tools->register(new WebSearchTool($config));

$server = new Server($tools);
$response = $server->handle($request);

if ($response === null) {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');
echo json_encode($response);
