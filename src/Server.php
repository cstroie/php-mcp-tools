<?php
declare(strict_types=1);

namespace Mcp;

final class Server
{
    private const PROTOCOL_VERSION = '2025-06-18';
    private const SERVER_NAME = 'php-mcp-tools';
    private const SERVER_VERSION = '1.0.0';

    private ToolRegistry $tools;

    public function __construct(ToolRegistry $tools)
    {
        $this->tools = $tools;
    }

    /**
     * @return array|null Response body to encode, or null for a no-content notification.
     */
    public function handle(array $request): ?array
    {
        $id = $request['id'];
        $method = $request['method'];
        $params = $request['params'];

        switch ($method) {
            case 'initialize':
                return JsonRpc::success($id, [
                    'protocolVersion' => self::PROTOCOL_VERSION,
                    'capabilities' => ['tools' => new \stdClass()],
                    'serverInfo' => [
                        'name' => self::SERVER_NAME,
                        'version' => self::SERVER_VERSION,
                    ],
                ]);

            case 'notifications/initialized':
                return null;

            case 'tools/list':
                return JsonRpc::success($id, ['tools' => $this->tools->list()]);

            case 'tools/call':
                return $this->handleToolsCall($id, $params);

            default:
                return JsonRpc::error($id, JsonRpc::METHOD_NOT_FOUND, "Method not found: {$method}");
        }
    }

    private function handleToolsCall($id, array $params): array
    {
        $name = $params['name'] ?? null;
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if (!is_string($name) || $name === '') {
            return JsonRpc::error($id, JsonRpc::INVALID_PARAMS, 'Missing tool name');
        }

        $tool = $this->tools->get($name);
        if ($tool === null) {
            return JsonRpc::error($id, JsonRpc::INVALID_PARAMS, "Unknown tool: {$name}");
        }

        try {
            $result = $tool->call($arguments);
        } catch (\Throwable $e) {
            return JsonRpc::success($id, [
                'content' => [
                    ['type' => 'text', 'text' => 'Tool error: ' . $e->getMessage()],
                ],
                'isError' => true,
            ]);
        }

        return JsonRpc::success($id, $result);
    }
}
