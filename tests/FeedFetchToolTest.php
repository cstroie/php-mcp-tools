<?php
declare(strict_types=1);

use Mcp\Config;
use Mcp\Tools\FeedFetchTool;

$config = new Config(['user_agent' => 'test-agent', 'feed_default_max_items' => 20]);
$tool = new FeedFetchTool($config);

$rss = <<<XML
<?xml version="1.0"?>
<rss version="2.0">
  <channel>
    <title>Example Blog</title>
    <item>
      <title>First Post</title>
      <link>https://example.com/first</link>
      <pubDate>Mon, 01 Jan 2024 00:00:00 GMT</pubDate>
      <description>First summary</description>
      <guid>https://example.com/first</guid>
    </item>
    <item>
      <title>Second Post</title>
      <link>https://example.com/second</link>
      <pubDate>Tue, 02 Jan 2024 00:00:00 GMT</pubDate>
      <description>Second summary</description>
      <guid>https://example.com/second</guid>
    </item>
  </channel>
</rss>
XML;

$rssItems = invokePrivate($tool, 'parseFeed', [$rss, 20]);
check('parses two RSS items', count($rssItems) === 2);
check('RSS item has title', $rssItems[0]['title'] === 'First Post');
check('RSS item has link', $rssItems[0]['link'] === 'https://example.com/first');
check('RSS item has published date', $rssItems[0]['published'] === 'Mon, 01 Jan 2024 00:00:00 GMT');
check('RSS item has summary', $rssItems[0]['summary'] === 'First summary');

$rssLimited = invokePrivate($tool, 'parseFeed', [$rss, 1]);
check('respects max_items on RSS', count($rssLimited) === 1);

$atom = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>Example Atom Blog</title>
  <entry>
    <title>Atom Post</title>
    <link rel="alternate" href="https://example.com/atom-post"/>
    <link rel="self" href="https://example.com/feed"/>
    <id>urn:uuid:1</id>
    <published>2024-01-03T00:00:00Z</published>
    <summary>Atom summary</summary>
  </entry>
</feed>
XML;

$atomItems = invokePrivate($tool, 'parseFeed', [$atom, 20]);
check('parses one Atom entry', count($atomItems) === 1);
check('Atom item has title', $atomItems[0]['title'] === 'Atom Post');
check('Atom item picks the alternate link, not self', $atomItems[0]['link'] === 'https://example.com/atom-post');
check('Atom item has published date', $atomItems[0]['published'] === '2024-01-03T00:00:00Z');
check('Atom item has summary', $atomItems[0]['summary'] === 'Atom summary');
check('Atom item has id', $atomItems[0]['id'] === 'urn:uuid:1');

$threw = false;
try {
    invokePrivate($tool, 'parseFeed', ['<html>not a feed</html>', 20]);
} catch (\RuntimeException $e) {
    $threw = true;
}
check('rejects a non-feed root element', $threw);

$threwParseError = false;
try {
    invokePrivate($tool, 'parseFeed', ['not xml at all', 20]);
} catch (\RuntimeException $e) {
    $threwParseError = true;
}
check('rejects unparseable XML', $threwParseError);
