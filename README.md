# php-mcp-tools

A small, dependency-free [MCP](https://modelcontextprotocol.io) server in PHP 7.4, exposed over
plain HTTP (streamable-HTTP / "WebMCP" transport, not stdio) so it can be added as a remote MCP
connector. Ships with four tools:

- **web_fetch** — fetch a URL over HTTP(S) and return its text content.
- **web_search** — search the web via DuckDuckGo's HTML endpoint and return results.
- **feed_discover** — fetch a web page and return the RSS/Atom feed URLs it advertises.
- **feed_fetch** — fetch an RSS/Atom feed URL and return its items as JSON.

## Requirements

- PHP 7.4+ with the `curl`, `dom`, and `simplexml` extensions (all part of the default PHP build).
- No Composer, no external packages.

## Setup

```bash
cp config.php.example config.php
```

Edit `config.php` and set a `token` — this is the bearer token clients must send. Alternatively
set the `MCP_TOKEN` environment variable instead of putting it in `config.php`. The server refuses
to authenticate any request if no token is configured (see `src/Auth.php`) — it will not silently
run open.

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

Override the target with `DEPLOY_DIR=/some/other/path ./deploy.sh`. The script preserves the
target directory's own permissions/ownership (`rsync --no-perms --no-owner --no-group`) so it
doesn't accidentally lock the web server out — see the comment in `deploy.sh` if you're changing it.

## Protocol

JSON-RPC 2.0 over `POST /`.

`GET /` behaves as a small router of its own (see `public/index.php`):
- `GET /?health=1` → unauthenticated, plain-text `ok` — for monitoring scripts / load balancers.
- `GET /` (anything else, e.g. opening the URL in a browser) → an HTML setup guide: the endpoint
  URL, the auth header format, an example `curl` call, and the live `tools/list` output (built
  from the same `ToolRegistry` the server itself uses, via `src/Guide.php` — it can't drift out of
  sync with the real tool list).

Every `POST` requires `Authorization: Bearer <token>`.

Supported JSON-RPC methods: `initialize`, `notifications/initialized`, `tools/list`, `tools/call`.

CORS is enabled by default (`Access-Control-Allow-Origin: *`, `OPTIONS` preflight handled) since
browser-based MCP clients are common and a custom `Authorization` header always triggers a
preflight request — without this, browser clients fail with "Failed to fetch" and no other error.
Set `cors_allow_origin` in `config.php` to a specific origin instead of `*` if needed; the bearer
token is still required either way, this only controls which pages' JS is allowed to *see* the
response.

Example:

```bash
curl -X POST http://127.0.0.1:8080/ \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"web_fetch","arguments":{"url":"https://example.com"}}}'
```

## CLI client

`bin/mcp-cli.php` is a small standalone script for talking to a running instance from the
terminal — same JSON-RPC 2.0/HTTP wire protocol any MCP client uses, just convenient for manual
testing and everyday use instead of hand-writing `curl`/JSON.

```bash
export MCP_URL=http://127.0.0.1/mcp-tools/ MCP_TOKEN=<token>   # or use --url=/--token= flags

php bin/mcp-cli.php list
php bin/mcp-cli.php call web_fetch '{"url":"https://example.com"}'
php bin/mcp-cli.php call feed_fetch '{"url":"https://www.php.net/feed.atom","max_items":3}'
php bin/mcp-cli.php init                    # raw initialize response
php bin/mcp-cli.php health                  # unauthenticated ?health=1 check
php bin/mcp-cli.php raw tools/list          # any JSON-RPC method, for debugging
```

`call` exits non-zero and prints the error if the tool reports `isError: true`; `--help` prints
full usage.

## Architecture

```
bin/mcp-cli.php         Standalone CLI client (talks the same JSON-RPC/HTTP protocol as any MCP client).
public/index.php        Front controller: routing (GET guide/health vs POST JSON-RPC), auth check.
src/
  autoload.php            spl_autoload_register mapping Mcp\Foo\Bar -> src/Foo/Bar.php.
  Config.php                Defaults merged with config.php / env; typed accessors.
  Auth.php                    Bearer-token check.
  JsonRpc.php                   Request parsing + success/error envelope builders.
  Server.php                     Dispatches initialize / tools/list / tools/call to a ToolRegistry.
  ToolRegistry.php                 Holds registered tools, builds the tools/list payload.
  Guide.php                         Renders the HTML page for a plain browser GET /.
  Http/
    SafeFetcher.php                  Shared SSRF-guarded HTTP(S) fetch, used by every tool that
                                      fetches a user-supplied URL.
  Tools/
    ToolInterface.php                Contract every tool implements.
    WebFetchTool.php, WebSearchTool.php, FeedDiscoverTool.php, FeedFetchTool.php
```

Data flow for a `tools/call`: `public/index.php` parses the JSON-RPC envelope → `Server::handle()`
looks up the tool by name in `ToolRegistry` → calls `Tool::call($arguments)` → the tool does its
work (usually via `SafeFetcher`) and returns `{content: [...], isError?: bool}` → `Server` wraps
that back into a JSON-RPC success envelope (tool errors are reported as `isError: true` inside a
*successful* JSON-RPC response, not as a JSON-RPC-level error — that's reserved for
protocol-level problems like an unknown method or a malformed request).

## Adding a new tool

This is the main thing future changes to this repo will be: a new capability exposed as an MCP
tool. Follow this checklist — it's the exact process `web_fetch`/`web_search`/`feed_discover`/
`feed_fetch` were built with.

1. **Implement `Mcp\Tools\ToolInterface`** in a new `src/Tools/YourTool.php`:
   ```php
   final class YourTool implements ToolInterface
   {
       public function __construct(Config $config) { ... }       // take Config even if unused yet
       public function name(): string { return 'your_tool'; }     // snake_case, becomes the MCP tool name
       public function description(): string { ... }               // one sentence, shown in tools/list and the guide page
       public function inputSchema(): array { ... }                  // JSON Schema; see existing tools for the shape
       public function call(array $arguments): array { ... }          // do the work, return the content envelope
   }
   ```
   - `call()` must return `['content' => [['type' => 'text', 'text' => '...']], 'isError' => bool]`
     (`isError` can be omitted when false). If the tool's result is naturally structured data
     (like `feed_fetch`'s items), `json_encode()` it into the `text` field — MCP text content can
     hold JSON; there's no separate "JSON content type" in this codebase's `content` envelope.
   - Validate required arguments yourself and `throw` (any `\Throwable`) on bad input —
     `Server::handleToolsCall()` catches it and turns it into `isError: true` with the exception
     message, so you don't need a try/catch inside `call()`.
   - **If the tool fetches a URL the caller supplies**, use `Mcp\Http\SafeFetcher` (constructed
     with `new SafeFetcher($config)`, call `->fetch($url)`) rather than raw `curl`. It's the single
     place SSRF protection lives (scheme allowlist, private/loopback IP rejection, DNS-pinned
     redirects, size/timeout caps) — every tool that touches an arbitrary user-supplied URL must
     go through it. See `FeedDiscoverTool`/`FeedFetchTool` for the pattern: fetch, then parse
     `$result['body']` with whatever's appropriate (`DOMDocument`/`DOMXPath` for HTML,
     `SimpleXMLElement` for XML/RSS/Atom).
   - If the tool needs new config (timeouts, default limits), add defaults in `Config::load()`
     (`src/Config.php`) following the existing `<area>_<setting>` naming (`fetch_timeout`,
     `search_default_max_results`, `feed_default_max_items`, ...), and read them via
     `$this->config->get('your_setting', $fallback)`.

2. **Register it** in `public/index.php` — two lines, alongside the existing tools:
   ```php
   use Mcp\Tools\YourTool;
   ...
   $tools->register(new YourTool($config));
   ```
   Nothing else needs to change. `ToolRegistry`, `Server`, and `Guide` are all tool-agnostic and
   pick up the new tool automatically (`tools/list`, `tools/call`, and the browser guide page all
   read from the same registry).

3. **Add unit tests** in `tests/YourToolTest.php`, and require it from `tests/run.php` (add
   `echo "YourTool\n"; require __DIR__ . '/YourToolTest.php';` next to the others). Pattern to
   follow — see `tests/FeedFetchToolTest.php` for a full example:
   - Construct the tool directly (`new YourTool(new Config([...]))`), no HTTP involved.
   - Use the shared `invokePrivate($obj, 'methodName', [...args])` helper (defined once in
     `tests/run.php`) to unit-test private parsing/validation logic against hand-written sample
     input (sample HTML, sample XML, etc.) — this is how SSRF-guard logic, HTML text extraction,
     DuckDuckGo result parsing, and feed parsing are all tested without touching the network.
   - Call the shared `check(string $label, bool $condition)` helper for each assertion.

4. **Add live-test coverage** in `tests/live.php`: a `tools/list` membership check for the new
   tool name, plus at least one real `tools/call` against it (a real, stable target — see how
   `feed_discover`/`feed_fetch` use `https://www.php.net/` — and, if the tool uses `SafeFetcher`,
   a loopback-URL call asserting `isError: true` to prove the SSRF guard is wired up).

5. **Run both suites** before considering the tool done:
   ```bash
   php tests/run.php                                                    # offline unit tests
   php -S 127.0.0.1:8080 -t public &                                    # local dev server
   cp config.php.example config.php && $EDITOR config.php               # set a token
   MCP_URL=http://127.0.0.1:8080/ MCP_TOKEN=<token> php tests/live.php  # end-to-end
   ```

6. **Deploy and re-verify** if you have a live instance: `./deploy.sh`, then re-run
   `tests/live.php` against `MCP_URL` pointed at the real deployment.

7. Mention the new tool in the "Ships with" list at the top of this README.

## Notes

- **web_fetch** blocks requests to loopback/private/link-local addresses (SSRF guard), resolves
  the host once and pins curl to that IP (`CURLOPT_RESOLVE`) to avoid DNS-rebinding between the
  safety check and the actual connection, and caps response size and redirect count. This logic
  lives in `Mcp\Http\SafeFetcher` and is shared by every URL-fetching tool.
- **web_search** scrapes DuckDuckGo's no-JS HTML endpoint (`html.duckduckgo.com/html/`), which
  requires a browser-like `User-Agent` or DuckDuckGo returns `403`. This is best-effort scraping,
  not an official API — it can break if DuckDuckGo changes their markup. `ToolInterface` is
  designed so a real search API (SerpAPI, Brave Search, etc.) can be dropped in later without
  touching the dispatcher.
- **feed_discover** only looks at `<link rel="alternate" type="application/(rss|atom)+xml" ...>`
  tags in the fetched HTML — it does not guess common feed paths (`/feed`, `/rss.xml`, ...) if a
  page doesn't advertise one explicitly.
- **feed_fetch** supports RSS 2.0 (`<rss><channel><item>`) and Atom (`<feed><entry>`) via
  `SimpleXMLElement`. RSS 1.0/RDF feeds and JSON Feed are not handled and will raise an error
  naming the unsupported root element.

## Tests

```bash
php tests/run.php
```

Plain assertion-based unit tests, no PHPUnit — everything here is zero-dependency by design.
These don't touch the network (SSRF-guard logic, HTML/XML parsing, etc. are tested in isolation
via reflection on private methods, using the shared `invokePrivate()`/`check()` helpers defined in
`tests/run.php`).

### Live / end-to-end tests

`tests/live.php` exercises a *running* instance over real HTTP — auth enforcement, the full
JSON-RPC surface, the browser guide page, and the actual tools (including a real DuckDuckGo
search, a real feed fetch against php.net, and real SSRF-guard rejections):

```bash
MCP_URL=http://127.0.0.1/mcp-tools/ MCP_TOKEN=<token> php tests/live.php
```

`MCP_URL` defaults to `http://127.0.0.1:8080/` (matching the `php -S` dev-server instructions
above); `MCP_TOKEN` is required. Run it after every deploy to confirm the live instance is
healthy.
