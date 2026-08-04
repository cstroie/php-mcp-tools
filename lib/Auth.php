<?php
declare(strict_types=1);

namespace Mcp;

final class Auth
{
    public static function check(Config $config): bool
    {
        $expected = $config->token();
        if ($expected === '') {
            // No token configured — refuse to run open on a network-reachable server.
            return false;
        }

        $header = self::bearerHeader();
        if ($header === null) {
            return false;
        }

        return hash_equals($expected, $header);
    }

    private static function bearerHeader(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? null;

        if ($header === null && function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = $value;
                    break;
                }
            }
        }

        if ($header === null || stripos($header, 'Bearer ') !== 0) {
            return null;
        }

        return substr($header, 7);
    }
}
