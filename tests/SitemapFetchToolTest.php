<?php
declare(strict_types=1);

use Mcp\Config;
use Mcp\Tools\SitemapFetchTool;

$config = new Config(['user_agent' => 'test-agent', 'sitemap_default_max_urls' => 50]);
$tool = new SitemapFetchTool($config);

$urlset = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc>https://example.com/</loc>
    <lastmod>2024-01-01</lastmod>
    <changefreq>daily</changefreq>
    <priority>1.0</priority>
  </url>
  <url>
    <loc>https://example.com/about</loc>
  </url>
</urlset>
XML;

[$kind, $entries] = invokePrivate($tool, 'parseSitemap', [$urlset, 50]);
check('parses urlset kind', $kind === 'urlset');
check('parses two url entries', count($entries) === 2);
check('url entry has url', $entries[0]['url'] === 'https://example.com/');
check('url entry has lastmod', $entries[0]['lastmod'] === '2024-01-01');
check('url entry has changefreq', $entries[0]['changefreq'] === 'daily');
check('url entry has priority', $entries[0]['priority'] === '1.0');
check('entry with no optional fields still has empty strings', $entries[1]['lastmod'] === '');

[, $limited] = invokePrivate($tool, 'parseSitemap', [$urlset, 1]);
check('respects max_urls on urlset', count($limited) === 1);

$index = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <sitemap>
    <loc>https://example.com/sitemap-pages.xml</loc>
    <lastmod>2024-02-01</lastmod>
  </sitemap>
  <sitemap>
    <loc>https://example.com/sitemap-posts.xml</loc>
  </sitemap>
</sitemapindex>
XML;

[$indexKind, $indexEntries] = invokePrivate($tool, 'parseSitemap', [$index, 50]);
check('parses sitemapindex kind', $indexKind === 'sitemapindex');
check('parses two sub-sitemap entries', count($indexEntries) === 2);
check('sub-sitemap entry has url', $indexEntries[0]['url'] === 'https://example.com/sitemap-pages.xml');
check('sub-sitemap entry has lastmod', $indexEntries[0]['lastmod'] === '2024-02-01');

$threw = false;
try {
    invokePrivate($tool, 'parseSitemap', ['<html>not a sitemap</html>', 50]);
} catch (\RuntimeException $e) {
    $threw = true;
}
check('rejects a non-sitemap root element', $threw);

$threwParseError = false;
try {
    invokePrivate($tool, 'parseSitemap', ['not xml at all', 50]);
} catch (\RuntimeException $e) {
    $threwParseError = true;
}
check('rejects unparseable XML', $threwParseError);
