<?php
declare(strict_types=1);

namespace Mcp;

final class Auth
{
    public static function check(Config $config): bool
    {
        $expected = $config->token();
        if ($expected === '') {
            // No token configured — authorization is disabled, allow all requests.
            return true;
        }

        $header = self::bearerHeader();
        if ($header === null) {
            return false;
        }

        return hash_equals($expected, self::tokenPart($header));
    }

    /**
     * Returns the client id from a "token@id" bearer value, or null if the
     * token carries no "@id" suffix. Purely informational (e.g. for logging);
     * it plays no role in the auth check itself.
     */
    public static function clientId(): ?string
    {
        $header = self::bearerHeader();
        if ($header === null) {
            return null;
        }

        $at = strpos($header, '@');
        if ($at === false) {
            return null;
        }

        return substr($header, $at + 1);
    }

    private static function tokenPart(string $header): string
    {
        $at = strpos($header, '@');
        return $at === false ? $header : substr($header, 0, $at);
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
