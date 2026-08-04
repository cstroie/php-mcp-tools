<?php
declare(strict_types=1);

use Mcp\Config;
use Mcp\Tools\FeedDiscoverTool;

$config = new Config(['user_agent' => 'test-agent']);
$tool = new FeedDiscoverTool($config);

$html = <<<HTML
<html><head>
  <link rel="alternate" type="application/rss+xml" title="RSS Feed" href="/feed.xml">
  <link rel="alternate" type="application/atom+xml" title="Atom Feed" href="https://other.example/atom.xml">
  <link rel="stylesheet" type="text/css" href="/style.css">
  <link rel="alternate" type="application/rss+xml" title="Dup" href="/feed.xml">
</head><body></body></html>
HTML;

$feeds = invokePrivate($tool, 'extractFeedLinks', [$html, 'https://example.com/blog/']);
check('finds two distinct feeds', count($feeds) === 2);
check('resolves a relative href against the page URL', $feeds[0]['url'] === 'https://example.com/feed.xml');
check('keeps an absolute href as-is', $feeds[1]['url'] === 'https://other.example/atom.xml');
check('ignores non-feed <link> tags', !in_array('/style.css', array_column($feeds, 'url'), true));
check('captures the feed title', $feeds[0]['title'] === 'RSS Feed');
check('captures the feed type', $feeds[0]['type'] === 'application/rss+xml');

$noFeeds = invokePrivate($tool, 'extractFeedLinks', ['<html><head></head><body></body></html>', 'https://example.com/']);
check('returns empty array when no feeds present', $noFeeds === []);
