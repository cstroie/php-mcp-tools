# Install

## Requirements

- PHP 7.4+ with the `curl`, `dom`, `mbstring`, and `simplexml` extensions (all part of the default
  PHP build).
- [Composer](https://getcomposer.org/), for the one dependency
  (`andreskrey/readability.php` — see README "Credits").

## Setup

```bash
git clone <this repo>
cd tusk
composer install
cp config.php.example config.php
```

Edit `config.php` and set a `token` — the bearer token clients must send. (Alternatively, set the
`MCP_TOKEN` environment variable instead of putting it in `config.php`.) The server refuses to
authenticate any request if no token is configured — it will not silently run open.

## Run locally

```bash
php -S 127.0.0.1:8080
```

Then visit `http://127.0.0.1:8080/` for the unauthenticated help/info page, or call the endpoint
directly:

```bash
curl -X POST http://127.0.0.1:8080/mcp.php \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

## Deploy

Point PHP-FPM/Apache/Nginx's document root at the repo root — `mcp.php` and `index.php` are the
only two web-exposed files, everything else (`lib/`, `tests/`, `bin/`) doesn't need to be
reachable directly.

Sync the repo to the target, excluding `.git`, `config.php`, `mcp.json`, and dev-only files like
`tests/`, then run:

```bash
composer install --no-dev
```

On the target, create `config.php` once from `config.php.example` (never overwrite it on
subsequent syncs — it holds the live bearer token).

## Connect a client

Copy `mcp.json.example` to `mcp.json` and fill in your deployed URL/token:

```json
{
  "mcpServers": {
    "tusk": {
      "type": "http",
      "url": "https://your-host/tusk/mcp.php",
      "headers": {
        "Authorization": "Bearer <token>"
      }
    }
  }
}
```

`mcp.json` is gitignored (it holds a live token) — merge its `mcpServers` entry into your client's
own config instead of committing it.

## Verify

```bash
php tests/run.php                                                     # offline unit tests
MCP_URL=http://127.0.0.1:8080/ MCP_TOKEN=<token> php tests/live.php    # end-to-end, against a running instance
```

Both should pass before considering an install/deploy done. See `README.md` for the full protocol
reference, tool list, and architecture notes.
