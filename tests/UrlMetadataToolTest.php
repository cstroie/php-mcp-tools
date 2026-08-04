<?php
declare(strict_types=1);

use Mcp\Config;
use Mcp\Tools\UrlMetadataTool;

$config = new Config(['user_agent' => 'test-agent']);
$tool = new UrlMetadataTool($config);

$html = <<<HTML
<html><head>
  <title>Fallback Title</title>
  <meta name="description" content="Fallback description">
  <meta property="og:title" content="OG Title">
  <meta property="og:description" content="OG description">
  <meta property="og:site_name" content="Example Site">
  <meta property="og:type" content="article">
  <meta property="og:image" content="/images/cover.png">
  <link rel="canonical" href="https://example.com/canonical-page">
  <link rel="icon" href="/favicon.png">
</head><body></body></html>
HTML;

$meta = invokePrivate($tool, 'extractMetadata', [$html, 'https://example.com/blog/post']);
check('prefers og:title over <title>', $meta['title'] === 'OG Title');
check('prefers og:description over meta description', $meta['description'] === 'OG description');
check('captures site_name', $meta['site_name'] === 'Example Site');
check('captures type', $meta['type'] === 'article');
check('resolves a relative og:image', $meta['image'] === 'https://example.com/images/cover.png');
check('captures canonical_url', $meta['canonical_url'] === 'https://example.com/canonical-page');
check('resolves a relative favicon link', $meta['favicon'] === 'https://example.com/favicon.png');
check('reports the fetched url', $meta['url'] === 'https://example.com/blog/post');

$minimalHtml = '<html><head><title>Just A Title</title></head><body></body></html>';
$minimalMeta = invokePrivate($tool, 'extractMetadata', [$minimalHtml, 'https://example.com/page']);
check('falls back to <title> when no og:title', $minimalMeta['title'] === 'Just A Title');
check('falls back to meta description when no og:description', $minimalMeta['description'] === null);
check(
    'falls back to /favicon.ico when no icon link present',
    $minimalMeta['favicon'] === 'https://example.com/favicon.ico'
);
check('canonical_url is null when absent', $minimalMeta['canonical_url'] === null);
check('image is null when absent', $minimalMeta['image'] === null);

$emptyMeta = invokePrivate($tool, 'extractMetadata', ['', 'https://example.com/']);
check('handles empty body without error', $emptyMeta['title'] === null);
check('still falls back to /favicon.ico on empty body', $emptyMeta['favicon'] === 'https://example.com/favicon.ico');
