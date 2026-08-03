<?php
declare(strict_types=1);

namespace Mcp\Tools;

interface ToolInterface
{
    public function name(): string;

    public function description(): string;

    /** @return array JSON Schema for the tool's input */
    public function inputSchema(): array;

    /**
     * @param array $arguments
     * @return array{content: array<int, array{type: string, text: string}>, isError?: bool}
     */
    public function call(array $arguments): array;
}
