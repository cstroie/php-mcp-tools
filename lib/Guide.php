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
            <article class="tool">
              <div class="tool-tusk" aria-hidden="true"></div>
              <div class="tool-body">
                <h3><code>{$name}</code></h3>
                <p>{$description}</p>
                <details>
                  <summary>Input schema</summary>
                  <pre><code>{$schema}</code></pre>
                </details>
              </div>
            </article>

            HTML;
        }

        $exampleCurl = htmlspecialchars(
            "curl -X POST {$endpointUrl} \\\n"
            . "  -H \"Authorization: Bearer <token>\" \\\n"
            . "  -H \"Content-Type: application/json\" \\\n"
            . "  -d '{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/list\"}'",
            ENT_QUOTES
        );

        $clientConfig = htmlspecialchars(
            json_encode(
                [
                    'mcpServers' => [
                        'tusk' => [
                            'type' => 'http',
                            'url' => $endpointUrl,
                            'headers' => ['Authorization' => 'Bearer <token>'],
                        ],
                    ],
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ),
            ENT_QUOTES
        );

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
          <meta charset="utf-8">
          <title>Tusk &mdash; MCP server</title>
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <meta name="color-scheme" content="light dark">
          <style>
            :root {
              --bg: #f6f3ec;
              --bg-raised: #ece6d6;
              --ink: #211d16;
              --ink-dim: #6c6455;
              --accent: #8a6a2e;
              --accent-strong: #61491f;
              --rule: #ddd4bd;
              --radius: 10px;
            }
            @media (prefers-color-scheme: dark) {
              :root {
                --bg: #15191d;
                --bg-raised: #1d2329;
                --ink: #e9e4d8;
                --ink-dim: #9aa0a3;
                --accent: #d7bd8a;
                --accent-strong: #ecd7a8;
                --rule: #2a3238;
              }
            }

            * { box-sizing: border-box; }

            body {
              background: var(--bg);
              color: var(--ink);
              font-family: -apple-system, "Segoe UI", "Helvetica Neue", Arial, sans-serif;
              font-size: 16px;
              line-height: 1.6;
              max-width: 42rem;
              margin: 0 auto;
              padding: 3.5rem 1.25rem 5rem;
            }

            .tusk-rule {
              display: block;
              height: 7px;
              width: 5.5rem;
              margin: 0 0 1.1rem;
              background: linear-gradient(90deg, var(--accent), transparent);
              clip-path: polygon(0 12%, 100% 38%, 100% 62%, 0 88%);
            }
            h2 .tusk-rule { width: 3.75rem; height: 5px; margin: 2.75rem 0 0.9rem; }

            .eyebrow {
              display: block;
              font-family: ui-monospace, "SFMono-Regular", Menlo, Consolas, monospace;
              font-size: .72rem;
              letter-spacing: .14em;
              text-transform: uppercase;
              color: var(--ink-dim);
              margin-bottom: .6rem;
            }

            h1 {
              font-family: ui-serif, Georgia, "Times New Roman", serif;
              font-weight: 600;
              font-size: 2.35rem;
              letter-spacing: -.01em;
              margin: 0 0 .9rem;
            }

            .lede {
              color: var(--ink-dim);
              font-size: .98rem;
              max-width: 38rem;
            }
            .lede code { font-size: .88em; }

            h2 {
              font-family: ui-serif, Georgia, "Times New Roman", serif;
              font-weight: 600;
              font-size: 1.28rem;
              margin: 0 0 1rem;
              letter-spacing: -.005em;
            }

            code, pre, kbd {
              font-family: ui-monospace, "SFMono-Regular", Menlo, Consolas, monospace;
            }
            code {
              background: var(--bg-raised);
              border: 1px solid var(--rule);
              border-radius: 5px;
              padding: .08rem .35rem;
              font-size: .87em;
            }
            a { color: var(--accent-strong); text-underline-offset: .15em; }
            a:hover { color: var(--accent); }
            a:focus-visible, summary:focus-visible, details:focus-visible {
              outline: 2px solid var(--accent);
              outline-offset: 2px;
            }

            dl.facts {
              margin: 0;
              display: grid;
              grid-template-columns: max-content 1fr;
              row-gap: .85rem;
              column-gap: 1.5rem;
            }
            dl.facts dt {
              font-family: ui-monospace, "SFMono-Regular", Menlo, Consolas, monospace;
              font-size: .74rem;
              letter-spacing: .08em;
              text-transform: uppercase;
              color: var(--ink-dim);
              padding-top: .2rem;
              white-space: nowrap;
            }
            dl.facts dd { margin: 0; }
            dl.facts code { display: inline-block; word-break: break-all; }

            .panel {
              position: relative;
              background: var(--bg-raised);
              border: 1px solid var(--rule);
              border-radius: var(--radius);
              overflow: hidden;
            }
            .panel-label {
              font-family: ui-monospace, "SFMono-Regular", Menlo, Consolas, monospace;
              font-size: .7rem;
              letter-spacing: .1em;
              text-transform: uppercase;
              color: var(--ink-dim);
              padding: .55rem .9rem;
              border-bottom: 1px solid var(--rule);
            }
            .panel pre {
              margin: 0;
              padding: .95rem 1rem 1.1rem;
              overflow-x: auto;
              white-space: pre-wrap;
              font-size: .85rem;
              line-height: 1.55;
            }

            .tools-grid {
              display: grid;
              gap: .9rem;
            }
            .tool {
              display: flex;
              gap: .8rem;
              border: 1px solid var(--rule);
              border-radius: var(--radius);
              padding: 1rem 1.1rem;
              background: var(--bg-raised);
            }
            .tool-tusk {
              flex: none;
              width: 4px;
              border-radius: 3px;
              background: linear-gradient(180deg, var(--accent), transparent);
            }
            .tool h3 { margin: 0 0 .35rem; font-size: .95rem; }
            .tool h3 code {
              background: none;
              border: none;
              padding: 0;
              font-size: 1em;
              color: var(--accent-strong);
              font-weight: 600;
            }
            .tool p { margin: 0 0 .5rem; font-size: .92rem; color: var(--ink-dim); }
            .tool details { font-size: .82rem; }
            .tool summary {
              cursor: pointer;
              color: var(--ink-dim);
              user-select: none;
              width: fit-content;
            }
            .tool summary:hover { color: var(--ink); }
            .tool pre {
              margin: .6rem 0 0;
              padding: .75rem .85rem;
              background: var(--bg);
              border: 1px solid var(--rule);
              border-radius: 7px;
              overflow-x: auto;
              font-size: .78rem;
              line-height: 1.5;
            }

            footer {
              margin-top: 3.25rem;
              padding-top: 1.5rem;
              border-top: 1px solid var(--rule);
              color: var(--ink-dim);
              font-size: .85rem;
            }
          </style>
        </head>
        <body>
          <div class="tusk-rule"></div>
          <span class="eyebrow">MCP server &middot; JSON-RPC 2.0 over HTTP</span>
          <h1>Tusk</h1>
          <p class="lede">This page is shown for a plain browser <code>GET</code>. MCP clients talk to
          this same URL with <code>POST</code> instead &mdash; point yours at the endpoint below.</p>

          <h2><span class="tusk-rule"></span>Configure this as an MCP connector</h2>
          <dl class="facts">
            <dt>Endpoint</dt>
            <dd><code>{$endpoint}</code></dd>
            <dt>Auth</dt>
            <dd><code>Authorization: Bearer &lt;token&gt;</code> &mdash; set server-side in
              <code>config.php</code> / <code>MCP_TOKEN</code>, not shown here</dd>
            <dt>Methods</dt>
            <dd><code>initialize</code>, <code>notifications/initialized</code>,
              <code>tools/list</code>, <code>tools/call</code></dd>
          </dl>

          <h2><span class="tusk-rule"></span>Try it</h2>
          <div class="panel">
            <div class="panel-label">curl</div>
            <pre>{$exampleCurl}</pre>
          </div>

          <h2><span class="tusk-rule"></span>Client config</h2>
          <p class="lede">Paste into a client's <code>mcpServers</code> config (or
          <code>claude mcp add-json</code>), replacing <code>&lt;token&gt;</code>:</p>
          <div class="panel">
            <div class="panel-label">json</div>
            <pre>{$clientConfig}</pre>
          </div>

          <h2><span class="tusk-rule"></span>Available tools</h2>
          <div class="tools-grid">
          {$toolsHtml}
          </div>

          <footer>
            Unauthenticated health check at <code>?health=1</code> (returns plain-text <code>ok</code>).
          </footer>
        </body>
        </html>

        HTML;
    }
}
