<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use Mcp\Bootstrap;
use Mcp\Config;
use Mcp\Guide;

// Pure help/info page — no auth, no JSON-RPC, no CORS. The actual MCP endpoint
// clients talk to is the sibling mcp.php.

$config = Config::load();
$tools = Bootstrap::buildToolRegistry($config);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$dir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
$dir = ($dir === '/' || $dir === '\\') ? '' : $dir;
$endpointUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/mcp.php';

header('Content-Type: text/html; charset=utf-8');
echo Guide::render($tools, $endpointUrl);
