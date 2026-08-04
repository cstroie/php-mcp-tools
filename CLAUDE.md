# CLAUDE.md

Guidance for Claude/agents working in this repo. See `README.md` for full user-facing docs
(protocol, deploy, notes on each tool) — this file is the agent-specific complement: commands,
conventions, and the things that are easy to get wrong here.

## What this is

A minimal-dependency PHP 7.4 MCP server exposed over HTTP (JSON-RPC 2.0), not stdio. Two front
controllers at the repo root: `mcp.php` (the real endpoint — auth, CORS, JSON-RPC dispatch) and
`index.php` (a plain help/info page, no auth). Both build their tool list from the same
`Mcp\Bootstrap::buildToolRegistry()`. Tools live in `lib/Tools/`, each implementing
`Mcp\Tools\ToolInterface`. The project deliberately avoids a stack of dependencies — one exception
exists (`andreskrey/readability.php`, via Composer, used by `WebFetchTool` for article extraction;
see README "Credits" for why that specific package/version was picked). Do not add another
Composer package or reach for a framework without asking first; "hand-written, minimal
dependencies" is a deliberate constraint, not an oversight — the bar for a new dependency is high,
not zero.

## Commands

```bash
php tests/run.php                                                     # offline unit tests (no network, no server)
php -S 127.0.0.1:8080                                                 # local dev server
MCP_URL=http://127.0.0.1:8080/ MCP_TOKEN=<token> php tests/live.php   # end-to-end, against a running instance
```

Both test suites must pass before considering a change done — `tests/run.php` alone is not
sufficient, since it deliberately never touches the network and can't catch integration issues
(wrong JSON-RPC envelope shape, a tool that doesn't actually work against a real server, etc.).
`tests/live.php` needs a token: `cp config.php.example config.php` and edit it for local runs;
`config.php` is gitignored, never commit it.

## Adding a new MCP tool

Full step-by-step is in `README.md` under "Adding a new tool" — follow it exactly, it reflects
how every existing tool (`web_fetch`, `web_search`, `feed_discover`, `feed_fetch`,
`sitemap_fetch`) was actually built and tested. Short version:

1. `lib/Tools/YourTool.php` implementing `ToolInterface` (`name`, `description`, `inputSchema`,
   `call`). Throw on bad input instead of handling errors yourself — `Server` catches it.
2. If it fetches a caller-supplied URL, go through `Mcp\Http\SafeFetcher`, never raw `curl`. It's
   the one place SSRF protection (private/loopback IP rejection, DNS-pinned redirects, size caps)
   lives; skipping it on a new tool is a real vulnerability, not a style nit.
3. Register in `lib/Bootstrap.php` (`$tools->register(new YourTool($config));`) — that's the only
   other file that needs editing; both `mcp.php` and `index.php` read from it, and
   `ToolRegistry`/`Server`/`Guide` are all tool-agnostic.
4. Unit tests in `tests/YourToolTest.php`, wired into `tests/run.php`, using the shared
   `check()`/`invokePrivate()` helpers already defined there.
5. Live-test coverage added to `tests/live.php`: a `tools/list` membership check, a real
   `tools/call`, and (if it uses `SafeFetcher`) a loopback-URL call asserting the SSRF guard fires.
6. Run both test commands above. If there's a live deployment, sync the repo to the deploy target
   (see README "Running") then re-run `tests/live.php` against it before calling the task done.
7. Update the tool list at the top of `README.md`.

## Things that will bite you here

- **`config.php` on a deploy target holds the live bearer token.** Exclude it when syncing the
  repo there (e.g. `rsync --exclude=config.php`). Never overwrite or read-and-log it; if you need
  the live token for a test run, read it locally (`php -r "echo (require 'config.php')['token'];"`)
  rather than hardcoding it anywhere that could end up committed.
- **Tool `call()` errors vs JSON-RPC errors are different layers.** A tool failure (bad URL, parse
  error, etc.) should `throw`, which `Server::handleToolsCall()` turns into a *successful*
  JSON-RPC response with `isError: true` inside the content envelope. A JSON-RPC-level error
  (`error: {code, message}` at the top level) is reserved for protocol problems — unknown method,
  malformed request — not tool failures. Don't blur these.
- **The guide page (`index.php`, `lib/Guide.php`) is generated from the live `ToolRegistry`**, not
  hand-maintained — if `tools/list` is right, the guide is right. Don't add a second place that
  lists tool names/descriptions.
- **`mcp.php` and `index.php` are deliberately separate files, not one file with an if-branch.**
  `mcp.php` is the only one with auth/CORS; `index.php` is unauthenticated on purpose (it's just
  static-ish info) — don't merge them back into one router, and don't add auth to `index.php` or
  drop auth from `mcp.php`.
- **`MCP_URL` means different things in different scripts** — in `bin/tusk.php` and
  `mcp.json.example` it's the `mcp.php` endpoint itself (what a real MCP client would use); in
  `tests/live.php` it's the *site root*, because that script needs to check `index.php` too and
  appends `/mcp.php` itself for the JSON-RPC calls. Keep this distinction when editing either.
- **The repo root is now the deployable layout itself** — `mcp.php`/`index.php` live at the top
  level alongside `lib/`, so a document root pointed straight at the repo (or a synced copy of it)
  just works, no flattening step needed.
- **`vendor/` is gitignored.** Run `composer install` (add `--no-dev` for a deploy target) after
  cloning or pulling changes that touch `composer.lock`. If you add or bump a Composer dependency,
  run `composer update`/`composer require` locally so `composer.lock` changes, and commit the lock
  file; don't hand-edit `composer.lock`.
- **`Readability::parse()` almost never throws or signals "not an article."** It does best-effort
  extraction on nearly any page with *some* body text and only raises `ParseException` on truly
  empty/broken HTML (no `<body>` content at all) — see `WebFetchTool::extractArticle()`. Don't
  assume a null/exception path will catch "this isn't really an article page"; it won't, in
  practice the fallback to whole-page text is rarely hit.
- **`Mcp\Text\MarkdownConverter` is intentionally narrow, not a general HTML→Markdown library.**
  It only handles the tag set Readability's cleanup emits (headings, `p`, lists, links, images,
  `strong`/`em`, `code`/`pre`, `blockquote`, `table`, `hr`, `br`) — anything else falls through to
  its `default` case, which just recurses into children and drops the wrapping tag. If you feed it
  arbitrary uncleaned page HTML instead of Readability's output, expect worse results; it's not
  meant to replace `htmlToPlainText()` for that path.
