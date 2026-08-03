<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

$failures = 0;
$passed = 0;

function check(string $label, bool $condition): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "  ok - {$label}\n";
    } else {
        $failures++;
        echo "  FAIL - {$label}\n";
    }
}

echo "JsonRpc\n";
require __DIR__ . '/JsonRpcTest.php';

echo "ToolRegistry\n";
require __DIR__ . '/ToolRegistryTest.php';

echo "WebFetchTool\n";
require __DIR__ . '/WebFetchToolTest.php';

echo "WebSearchTool\n";
require __DIR__ . '/WebSearchToolTest.php';

echo "\n{$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
