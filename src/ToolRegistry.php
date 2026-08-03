<?php
declare(strict_types=1);

namespace Mcp;

use Mcp\Tools\ToolInterface;

final class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /** @return array<int, array{name: string, description: string, inputSchema: array}> */
    public function list(): array
    {
        $list = [];
        foreach ($this->tools as $tool) {
            $list[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
            ];
        }

        return $list;
    }
}
