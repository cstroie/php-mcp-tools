# php-mcp-tools

A small, dependency-free [MCP](https://modelcontextprotocol.io) server in PHP 7.4, exposed over
plain HTTP (streamable-HTTP / "WebMCP" transport, not stdio) so it can be added as a remote MCP
connector. Ships with two tools:

- **web_fetch** — fetch a URL over HTTP(S) and return its text content.
- **web_search** — search the web via DuckDuckGo's HTML endpoint and return results.

## Requirements

- PHP 7.4+ with the `curl` and `dom` extensions (both are part of the default PHP build).
- No Composer, no external packages.

## Setup

```bash
cp config.php.example config.php
```

Edit `config.php` and set a long random `token` — this is the bearer token clients must send.
Alternatively set the `MCP_TOKEN` environment variable instead of putting it in `config.php`.

## Running

Local dev:

```bash
php -S 127.0.0.1:8080 -t public
```

Production: point PHP-FPM/Apache/Nginx's document root at `public/`, with all requests routed to
`public/index.php` (it's the only web-exposed file — everything else lives outside the web root
or is otherwise not directly requestable).

### deploy.sh

If your server can't give this app its own document root (e.g. a single shared lighttpd/Apache
docroot serving several apps as sibling directories, each with everything flattened at the top
level — no separate `public/`), `deploy.sh` bridges the gap: it flattens `public/index.php` +
`src/` into a target directory, leaving `config.php` there untouched.

```bash
sudo mkdir -p /var/www/html/mcp-tools && sudo chown "$(id -un):$(id -gn)" /var/www/html/mcp-tools
cp config.php.example /var/www/html/mcp-tools/config.php   # first time only; then edit the token
./deploy.sh                                                  # re-run after every change to src/ or public/index.php
```

Override the target with `DEPLOY_DIR=/some/other/path ./deploy.sh`.

## Protocol

JSON-RPC 2.0 over `POST /`. A `GET /` returns a plain-text `ok` health check (no auth required).
Every `POST` requires `Authorization: Bearer <token>`.

Supported methods: `initialize`, `notifications/initialized`, `tools/list`, `tools/call`.

Example:

```bash
curl -X POST http://127.0.0.1:8080/ \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"web_fetch","arguments":{"url":"https://example.com"}}}'
```

## Adding a new tool

1. Create `src/Tools/YourTool.php` implementing `Mcp\Tools\ToolInterface`
   (`name()`, `description()`, `inputSchema()`, `call(array $arguments): array`).
2. Register it in `public/index.php`: `$tools->register(new YourTool($config));`.

No other files need to change — the registry and dispatcher are tool-agnostic.

## Notes

- **web_fetch** blocks requests to loopback/private/link-local addresses (SSRF guard), resolves
  the host once and pins curl to that IP (`CURLOPT_RESOLVE`) to avoid DNS-rebinding between the
  safety check and the actual connection, and caps response size and redirect count.
- **web_search** scrapes DuckDuckGo's no-JS HTML endpoint (`html.duckduckgo.com/html/`), which
  requires a browser-like `User-Agent` or DuckDuckGo returns `403`. This is best-effort scraping,
  not an official API — it can break if DuckDuckGo changes their markup. `ToolInterface` is
  designed so a real search API (SerpAPI, Brave Search, etc.) can be dropped in later without
  touching the dispatcher.

## Tests

```bash
php tests/run.php
```

Plain assertion-based unit tests, no PHPUnit — everything here is zero-dependency by design.
These don't touch the network (SSRF-guard logic, HTML parsing, etc. are tested in isolation via
reflection on private methods).

### Live / end-to-end tests

`tests/live.php` exercises a *running* instance over real HTTP — auth enforcement, the full
JSON-RPC surface, and the actual `web_fetch`/`web_search` tools (including a real DuckDuckGo
request and a real SSRF-guard rejection):

```bash
MCP_URL=http://127.0.0.1/mcp-tools/ MCP_TOKEN=<token> php tests/live.php
```

`MCP_URL` defaults to `http://127.0.0.1:8080/` (matching the `php -S` dev-server instructions
above); `MCP_TOKEN` is required. Run it after every deploy to confirm the live instance is
healthy.
