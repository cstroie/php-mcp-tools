<?php
declare(strict_types=1);

use Mcp\JsonRpc;

$parsed = JsonRpc::parse('{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}');
check('parses a valid request', $parsed !== null && $parsed['method'] === 'tools/list');

check('rejects missing jsonrpc version', JsonRpc::parse('{"id":1,"method":"x"}') === null);
check('rejects invalid json', JsonRpc::parse('not json') === null);

$defaultParams = JsonRpc::parse('{"jsonrpc":"2.0","id":1,"method":"initialize"}');
check('defaults params to empty array', $defaultParams['params'] === []);

$success = JsonRpc::success(1, ['ok' => true]);
check('success envelope has result', $success['result']['ok'] === true && !isset($success['error']));

$error = JsonRpc::error(1, JsonRpc::METHOD_NOT_FOUND, 'nope');
check('error envelope has code', $error['error']['code'] === JsonRpc::METHOD_NOT_FOUND);
