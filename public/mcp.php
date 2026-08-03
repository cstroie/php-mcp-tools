<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use Mcp\Auth;
use Mcp\Bootstrap;
use Mcp\Config;
use Mcp\JsonRpc;
use Mcp\Server;

// The MCP endpoint: all protocol traffic (auth, CORS, JSON-RPC dispatch) lives
// here. index.php next to this file is the human-readable help/info page only.

$config = Config::load();

// CORS: MCP clients are commonly browser-based JS talking cross-origin. A custom
// Authorization header always triggers a preflight OPTIONS request, so it must be
// answered even though nothing else here needs auth-free access.
header('Access-Control-Allow-Origin: ' . $config->get('cors_allow_origin', '*'));
header('Vary: Origin');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept, mcp-protocol-version, x-mcp-session-id');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Unauthenticated, script-friendly health check.
    if (isset($_GET['health'])) {
        header('Content-Type: text/plain');
        echo "ok\n";
        exit;
    }

    // A plain GET here isn't an error, but this file only speaks JSON-RPC —
    // point browsers at the help page instead of showing them nothing useful.
    $indexUrl = './index.php';
    header('Content-Type: text/plain');
    echo "This is the php-mcp-tools MCP JSON-RPC endpoint — POST requests here.\n";
    echo "See {$indexUrl} for setup help.\n";
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

$tools = Bootstrap::buildToolRegistry($config);
$server = new Server($tools);
$response = $server->handle($request);

if ($response === null) {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');
echo json_encode($response);
