<?php
declare(strict_types=1);

namespace Mcp\Http;

/**
 * Resolves an href found on a fetched page (absolute, protocol-relative, or
 * relative) against that page's URL — shared by every tool that scrapes links
 * out of HTML (feed_discover, url_metadata, ...).
 */
final class UrlResolver
{
    public static function resolve(string $base, string $href): string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        if (strpos($href, '//') === 0) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
            return "{$scheme}:{$href}";
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        if (strpos($href, '/') === 0) {
            return "{$scheme}://{$host}{$port}{$href}";
        }

        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, strrpos($path, '/') + 1);
        return "{$scheme}://{$host}{$port}{$dir}{$href}";
    }
}
