<?php
declare(strict_types=1);

use Mcp\Tools\ToolInterface;
use Mcp\ToolRegistry;

$fakeTool = new class implements ToolInterface {
    public function name(): string
    {
        return 'fake_tool';
    }

    public function description(): string
    {
        return 'A fake tool for testing.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function call(array $arguments): array
    {
        return ['content' => [['type' => 'text', 'text' => 'ok']]];
    }
};

$registry = new ToolRegistry();
$registry->register($fakeTool);

check('get() returns the registered tool', $registry->get('fake_tool') === $fakeTool);
check('get() returns null for unknown tool', $registry->get('missing') === null);

$list = $registry->list();
check('list() contains one entry', count($list) === 1);
check('list() entry has expected name', $list[0]['name'] === 'fake_tool');
check('list() entry includes inputSchema', $list[0]['inputSchema']['type'] === 'object');
