<?php
declare(strict_types=1);

namespace Mcp;

final class JsonRpc
{
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    /**
     * @return array{id: mixed, method: string, params: array}|null
     */
    public static function parse(string $body): ?array
    {
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['jsonrpc']) || $data['jsonrpc'] !== '2.0' || !isset($data['method'])) {
            return null;
        }

        return [
            'id' => $data['id'] ?? null,
            'method' => (string) $data['method'],
            'params' => is_array($data['params'] ?? null) ? $data['params'] : [],
        ];
    }

    public static function success($id, $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }

    public static function error($id, int $code, string $message, $data = null): array
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];
        if ($data !== null) {
            $error['data'] = $data;
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error,
        ];
    }
}
