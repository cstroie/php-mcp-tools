<?php
declare(strict_types=1);

namespace Mcp;

final class Guide
{
    public static function render(ToolRegistry $tools, string $endpointUrl): string
    {
        $endpoint = htmlspecialchars($endpointUrl, ENT_QUOTES);

        $toolsHtml = '';
        foreach ($tools->list() as $tool) {
            $name = htmlspecialchars($tool['name'], ENT_QUOTES);
            $description = htmlspecialchars($tool['description'], ENT_QUOTES);
            $schema = htmlspecialchars(
                json_encode($tool['inputSchema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                ENT_QUOTES
            );
            $toolsHtml .= <<<HTML
            <section class="tool">
              <h3><code>{$name}</code></h3>
              <p>{$description}</p>
              <pre>{$schema}</pre>
            </section>

            HTML;
        }

        $exampleCurl = htmlspecialchars(
            "curl -X POST {$endpointUrl} \\\n"
            . "  -H \"Authorization: Bearer <token>\" \\\n"
            . "  -H \"Content-Type: application/json\" \\\n"
            . "  -d '{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/list\"}'",
            ENT_QUOTES
        );

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
          <meta charset="utf-8">
          <title>php-mcp-tools</title>
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <style>
            body { font-family: system-ui, sans-serif; max-width: 46rem; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; color: #1a1a1a; }
            h1 { font-size: 1.4rem; }
            h2 { font-size: 1.1rem; margin-top: 2rem; border-bottom: 1px solid #ddd; padding-bottom: .3rem; }
            h3 { font-size: 1rem; margin-bottom: .2rem; }
            code, pre { background: #f4f4f4; border-radius: 4px; }
            code { padding: .1rem .3rem; }
            pre { padding: .8rem; overflow-x: auto; white-space: pre-wrap; }
            .tool { margin-bottom: 1.5rem; }
            .muted { color: #666; font-size: .9rem; }
          </style>
        </head>
        <body>
          <h1>php-mcp-tools</h1>
          <p class="muted">This is an MCP server (JSON-RPC 2.0 over HTTP). This page is only shown for
          plain browser <code>GET</code> requests &mdash; MCP clients should talk to this same URL
          with <code>POST</code>.</p>

          <h2>Configure this as an MCP connector</h2>
          <ul>
            <li>Endpoint URL: <code>{$endpoint}</code></li>
            <li>Auth: send header <code>Authorization: Bearer &lt;token&gt;</code> (the token is set
              server-side in <code>config.php</code> / <code>MCP_TOKEN</code>, not shown here)</li>
            <li>Transport: streamable HTTP, JSON-RPC 2.0 &mdash; supported methods:
              <code>initialize</code>, <code>notifications/initialized</code>, <code>tools/list</code>,
              <code>tools/call</code></li>
          </ul>

          <h2>Try it</h2>
          <pre>{$exampleCurl}</pre>

          <h2>Available tools</h2>
          {$toolsHtml}
          <h2>Health check</h2>
          <p>An unauthenticated, script-friendly health check is available at
          <code>?health=1</code> (returns plain-text <code>ok</code>).</p>
        </body>
        </html>

        HTML;
    }
}
