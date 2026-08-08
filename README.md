# Tusk

A small, minimal-dependency [MCP](https://modelcontextprotocol.io) server in PHP 7.4, exposed over
plain HTTP (streamable-HTTP / "WebMCP" transport, not stdio) so it can be added as a remote MCP
connector. Ships with six tools:

- **web_fetch** — fetch a URL over HTTP(S) and return its content. For HTML pages, extracts the
  main article content (via Readability) as Markdown, falling back to plain text of the whole page
  when no clear article is found. Sends a browser-like default header set (Accept, Accept-Language,
  User-Agent, etc.); optional `headers`/`cookies` arguments can override/add to that, e.g. to
  replay a session cookie from your own browser. This does not defeat a JS-executed challenge
  (curl can't run JS), but does get past sites that just check for a normal-looking request.
- **web_search** — search the web and return results (title, url, snippet). Uses DuckDuckGo's
  HTML endpoint by default, or the Brave Search API if configured (see `search_provider` below).
- **feed_discover** — fetch a web page and return the RSS/Atom feed URLs it advertises.
- **feed_fetch** — fetch an RSS/Atom feed URL and return its items as JSON.
- **sitemap_fetch** — fetch a `sitemap.xml` URL and return its entries as JSON, or (for a sitemap
  index) the list of sub-sitemap URLs.
- **url_metadata** — fetch a page's title, description, Open Graph/Twitter Card tags, favicon,
  and canonical URL, without fetching the full body text.

## Requirements

- PHP 7.4+ with the `curl`, `dom`, `mbstring`, and `simplexml` extensions (all part of the default
  PHP build).
- [Composer](https://getcomposer.org/) — used for exactly one dependency
  ([`andreskrey/readability.php`](https://github.com/andreskrey/readability.php), see
  [Credits](#credits)). Everything else in this codebase is still hand-written with no other
  packages; this isn't a stack of framework dependencies, just the one library that would be
  wasteful to reimplement.

## Setup, running, and deploying

See [`INSTALL.md`](INSTALL.md) for setup, running locally, deploying, connecting a client, and
verifying an install/deploy with the test suites.

## Protocol

Two web-exposed endpoints (both at the repo root):

- **`mcp.php`** — the actual MCP endpoint. JSON-RPC 2.0 over `POST`. Every `POST` requires
  `Authorization: Bearer <token>`. `GET mcp.php?health=1` is an unauthenticated, plain-text `ok`
  health check for monitoring scripts / load balancers; any other `GET` just returns a one-line
  plain-text pointer at `index.php` (browsers landing here directly aren't shown an error, but
  this file doesn't render the guide itself — see below).
- **`index.php`** — a plain-English, unauthenticated HTML help page: the `mcp.php` endpoint URL,
  the auth header format, an example `curl` call, a ready-to-paste client JSON config, and the
  live `tools/list` output. Built from the same `ToolRegistry` the server itself uses via
  `lib/Guide.php`, so it can't drift out of sync with the real tool list. This is what you land on
  opening the site root in a browser (`index-file.names` picks it as the default document).

Supported JSON-RPC methods on `mcp.php`: `initialize`, `notifications/initialized`, `tools/list`,
`tools/call`. `initialize`'s response includes `serverInfo` (`name`, `title`, `version`) and an
`instructions` field with a short description, source/docs link, license, and maintainer contact —
this is the MCP protocol's own place for that metadata (there's no separate "copyright" field);
some clients (e.g. llama.cpp's MCP UI) surface `instructions` to the user. See
`Server::SERVER_INSTRUCTIONS` in `lib/Server.php` to change it.

The bearer token may optionally be suffixed with `@<client-id>`, e.g.
`Authorization: Bearer <token>@my-client`. Only the part before `@` is checked against the
configured token; the `@<client-id>` suffix is not validated and exists purely so different
clients/deployments can share one token while remaining distinguishable (`Mcp\Auth::clientId()`
returns it, currently unused beyond that). Omitting it works exactly as before.

CORS is enabled by default on `mcp.php` (`Access-Control-Allow-Origin: *`, `OPTIONS` preflight
handled) since browser-based MCP clients are common and a custom `Authorization` header always
triggers a preflight request — without this, browser clients fail with "Failed to fetch" and no
other error. Set `cors_allow_origin` in `config.php` to a specific origin instead of `*` if needed;
the bearer token is still required either way, this only controls which pages' JS is allowed to
*see* the response. `index.php` doesn't set CORS headers — it's not meant to be fetched
cross-origin by client code, only opened directly.

Example:

```bash
curl -X POST http://127.0.0.1:8080/mcp.php \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"web_fetch","arguments":{"url":"https://example.com"}}}'
```

## Client configuration

For MCP clients that read a JSON config (Claude Code, Claude Desktop, and others using the same
`mcpServers` convention), copy `mcp.json.example` to `mcp.json` and fill in your URL/token — see
[`INSTALL.md`](INSTALL.md#connect-a-client) for the shape of that file. Merge its `mcpServers`
entry into the client's own config file (e.g. `.mcp.json` in a project, or Claude Desktop's
config). Claude Code can also add it directly from the CLI:

```bash
claude mcp add-json tusk '{"type":"http","url":"https://your-host/tusk/mcp.php","headers":{"Authorization":"Bearer <token>"}}'
```

## CLI client

`bin/tusk.php` is a small standalone script for talking to a running instance from the
terminal — same JSON-RPC 2.0/HTTP wire protocol any MCP client uses, just convenient for manual
testing and everyday use instead of hand-writing `curl`/JSON.

```bash
export MCP_URL=http://127.0.0.1/tusk/mcp.php MCP_TOKEN=<token>   # or use --url=/--token= flags

php bin/tusk.php list
php bin/tusk.php call web_fetch '{"url":"https://example.com"}'
php bin/tusk.php call feed_fetch '{"url":"https://www.php.net/feed.atom","max_items":3}'
php bin/tusk.php init                    # raw initialize response
php bin/tusk.php health                  # unauthenticated ?health=1 check
php bin/tusk.php raw tools/list          # any JSON-RPC method, for debugging
```

`MCP_URL` for the CLI (and for client configs like `mcp.json.example`) is the `mcp.php` endpoint
itself — unlike `tests/live.php` below, which takes the *site root* since it needs to check both
`index.php` and `mcp.php`.

`call` exits non-zero and prints the error if the tool reports `isError: true`; `--help` prints
full usage.

## Architecture

```
bin/tusk.php         Standalone CLI client (talks the same JSON-RPC/HTTP protocol as any MCP client).
mcp.php               The MCP endpoint: CORS, auth, JSON-RPC dispatch. All "real" traffic.
index.php             Plain-English help/info page only — no auth, no JSON-RPC, no CORS.
lib/
  autoload.php            spl_autoload_register mapping Mcp\Foo\Bar -> lib/Foo/Bar.php.
  Config.php                Defaults merged with config.php / env; typed accessors.
  Auth.php                    Bearer-token check.
  JsonRpc.php                   Request parsing + success/error envelope builders.
  Server.php                     Dispatches initialize / tools/list / tools/call to a ToolRegistry.
  ToolRegistry.php                 Holds registered tools, builds the tools/list payload.
  Bootstrap.php                     Builds the ToolRegistry — the one list of tools, shared by
                                     mcp.php and index.php so they can't drift apart.
  Guide.php                         Renders the HTML page served by index.php.
  Http/
    SafeFetcher.php                  Shared SSRF-guarded HTTP(S) fetch, used by every tool that
                                      fetches a user-supplied URL.
    UrlResolver.php                  Shared "resolve this href against that page URL" logic,
                                      used by any tool that scrapes links out of HTML.
  Text/
    MarkdownConverter.php            HTML -> Markdown, scoped to the tag set Readability emits
                                      (not a general-purpose converter). Used by WebFetchTool.
  Tools/
    ToolInterface.php                Contract every tool implements.
    WebFetchTool.php, WebSearchTool.php, FeedDiscoverTool.php, FeedFetchTool.php,
    SitemapFetchTool.php, UrlMetadataTool.php
```

Data flow for a `tools/call`: `mcp.php` parses the JSON-RPC envelope → `Server::handle()`
looks up the tool by name in a `ToolRegistry` built by `Bootstrap::buildToolRegistry()` → calls
`Tool::call($arguments)` → the tool does its work (usually via `SafeFetcher`) and returns
`{content: [...], isError?: bool}` → `Server` wraps that back into a JSON-RPC success envelope
(tool errors are reported as `isError: true` inside a *successful* JSON-RPC response, not as a
JSON-RPC-level error — that's reserved for protocol-level problems like an unknown method or a
malformed request). `index.php` calls the same `Bootstrap::buildToolRegistry()` purely to
list tools on the help page — it never dispatches a `tools/call`.

## Adding a new tool

This is the main thing future changes to this repo will be: a new capability exposed as an MCP
tool. Follow this checklist — it's the exact process `web_fetch`/`web_search`/`feed_discover`/
`feed_fetch`/`sitemap_fetch` were built with.

1. **Implement `Mcp\Tools\ToolInterface`** in a new `lib/Tools/YourTool.php`:
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
     (`lib/Config.php`) following the existing `<area>_<setting>` naming (`fetch_timeout`,
     `search_default_max_results`, `feed_default_max_items`, ...), and read them via
     `$this->config->get('your_setting', $fallback)`.

2. **Register it** in `lib/Bootstrap.php` — two lines, alongside the existing tools:
   ```php
   use Mcp\Tools\YourTool;
   ...
   $tools->register(new YourTool($config));
   ```
   That's the *only* place to register a tool — both `mcp.php` (dispatch) and
   `index.php` (help page) call `Bootstrap::buildToolRegistry()`, so they can't disagree
   about which tools exist. Nothing else needs to change; `ToolRegistry`, `Server`, and `Guide` are
   all tool-agnostic.

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
   php -S 127.0.0.1:8080 &                                              # local dev server
   cp config.php.example config.php && $EDITOR config.php               # set a token
   MCP_URL=http://127.0.0.1:8080/ MCP_TOKEN=<token> php tests/live.php  # end-to-end (site root, not mcp.php)
   ```

6. **Deploy and re-verify** if you have a live instance: sync the repo to the deploy target (see
   "Running" above), then re-run `tests/live.php` against `MCP_URL` pointed at the real deployment.

7. Mention the new tool in the "Ships with" list at the top of this README.

## Notes

- **web_fetch** blocks requests to loopback/private/link-local addresses (SSRF guard), resolves
  the host once and pins curl to that IP (`CURLOPT_RESOLVE`) to avoid DNS-rebinding between the
  safety check and the actual connection, and caps response size and redirect count. This logic
  lives in `Mcp\Http\SafeFetcher` and is shared by every URL-fetching tool. For HTML pages, it
  first tries Readability article extraction (see [Credits](#credits)) to strip navigation/ads/
  boilerplate, then converts the extracted content to Markdown via `Mcp\Text\MarkdownConverter`
  (headings, lists, links, emphasis, code blocks, tables preserved — the title becomes an `# H1`).
  Readability does best-effort extraction on almost any page with *some* body content (it only
  gives up on truly empty/broken HTML), so the plain full-page-text fallback (no Markdown
  conversion applied there — it's the raw page, not isolated article content) is rarely hit in
  practice — and on heavily templated non-article pages (large SPA-style sites, some Wikipedia
  pages) the "best effort" can occasionally pull in unrelated embedded data instead of the
  intended content. This is an inherent limitation of the algorithm, not a bug in the integration.
  `MarkdownConverter` itself is scoped to the tag set Readability's cleanup actually emits, not a
  general-purpose HTML→Markdown converter — see its class docblock.
- **web_search** picks its backend from `search_provider` in `config.php` (or the `SEARCH_PROVIDER`
  env var): `'ddg'` (default) scrapes DuckDuckGo's no-JS HTML endpoint
  (`html.duckduckgo.com/html/`), which requires a browser-like `User-Agent` or DuckDuckGo returns
  `403` — this is best-effort scraping, not an official API, and can break if DuckDuckGo changes
  their markup. `'brave'` calls the real [Brave Search API](https://brave.com/search/api/)
  instead, and requires a `search_brave_api_key` (or `BRAVE_API_KEY` env var) — `web_search`
  throws if `search_provider` is `'brave'` and no key is configured. `ToolInterface` doesn't care
  which backend is active; adding another provider is a change local to `WebSearchTool`.
- **feed_discover** only looks at `<link rel="alternate" type="application/(rss|atom)+xml" ...>`
  tags in the fetched HTML — it does not guess common feed paths (`/feed`, `/rss.xml`, ...) if a
  page doesn't advertise one explicitly.
- **feed_fetch** supports RSS 2.0 (`<rss><channel><item>`) and Atom (`<feed><entry>`) via
  `SimpleXMLElement`. RSS 1.0/RDF feeds and JSON Feed are not handled and will raise an error
  naming the unsupported root element.
- **sitemap_fetch** supports the standard `<urlset>` (entries) and `<sitemapindex>` (sub-sitemap
  list) root elements from the [sitemaps.org](https://www.sitemaps.org/protocol.html) protocol —
  the response's `kind` field tells you which one you got. Sitemap indexes aren't recursed
  automatically; call `sitemap_fetch` again on a sub-sitemap URL to get its entries. Sitemap
  URLs can be large; `max_urls` (default `sitemap_default_max_urls`, 50) truncates the result
  rather than returning every entry.
- **url_metadata** prefers Open Graph tags (`og:title`, `og:description`) over the plain
  `<title>`/`<meta name="description">` fallbacks, and falls back to `<origin>/favicon.ico` when
  no `<link rel="icon">` is present (a guess, since most sites rely on that browser convention
  rather than declaring it explicitly — it is not verified to actually exist).

## Credits

`web_fetch`'s article extraction uses
[`andreskrey/readability.php`](https://github.com/andreskrey/readability.php) v2.1, a PHP port of
[Mozilla's Readability.js](https://github.com/mozilla/readability) (the algorithm behind Firefox's
Reader View), which is itself derived from Arc90's original 2010 Readability project. Licensed
Apache-2.0. Note: this package is archived/unmaintained since 2021 — it was chosen over its
actively maintained fork ([`fivefilters/readability.php`](https://github.com/fivefilters/readability.php))
because that fork's current release requires PHP 8.4+, while this older, frozen version still runs
fine on PHP 7.4 with only one extra dependency (`psr/log`). Worth revisiting if this project's PHP
requirement is ever raised.

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
MCP_URL=http://127.0.0.1/tusk/ MCP_TOKEN=<token> php tests/live.php
```

`MCP_URL` is the *site root* here (unlike the CLI/client-config `MCP_URL`, which is `mcp.php`
itself) — the script appends `mcp.php` internally for JSON-RPC calls while also checking
`index.php` at the root. Defaults to `http://127.0.0.1:8080/` (matching the `php -S` dev-server
instructions above); `MCP_TOKEN` is required. Run it after every deploy to confirm the live
instance is healthy.
