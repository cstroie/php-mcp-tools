<?php
declare(strict_types=1);

namespace Mcp;

use Mcp\Tools\FeedDiscoverTool;
use Mcp\Tools\FeedFetchTool;
use Mcp\Tools\SitemapFetchTool;
use Mcp\Tools\UrlMetadataTool;
use Mcp\Tools\WebFetchTool;
use Mcp\Tools\WebSearchTool;

/**
 * Single place that lists which tools exist — shared by mcp.php (dispatch) and
 * index.php (guide page), so the two front controllers can't drift apart.
 */
final class Bootstrap
{
    public static function buildToolRegistry(Config $config): ToolRegistry
    {
        $tools = new ToolRegistry();
        $tools->register(new WebFetchTool($config));
        $tools->register(new WebSearchTool($config));
        $tools->register(new FeedDiscoverTool($config));
        $tools->register(new FeedFetchTool($config));
        $tools->register(new UrlMetadataTool($config));
        $tools->register(new SitemapFetchTool($config));

        return $tools;
    }
}
