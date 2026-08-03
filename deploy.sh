#!/usr/bin/env bash
# Flattens public/{index,mcp}.php + src/ (the layout this repo uses for
# portability) into the flat layout this box's lighttpd expects (index.php,
# mcp.php, and src/ as siblings, no public/ subdir — matches the other apps
# under /var/www/html).
# Never touches config.php on the target: that holds the live bearer token
# and isn't part of the repo.
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEPLOY_DIR="${DEPLOY_DIR:-/var/www/html/mcp-tools}"

if [ ! -d "$DEPLOY_DIR" ]; then
    echo "error: $DEPLOY_DIR does not exist." >&2
    echo "One-time setup (needs sudo):" >&2
    echo "  sudo mkdir -p $DEPLOY_DIR && sudo chown \"\$(id -un):\$(id -gn)\" $DEPLOY_DIR" >&2
    exit 1
fi

STAGE_DIR="$(mktemp -d)"
trap 'rm -rf "$STAGE_DIR"' EXIT
chmod 755 "$STAGE_DIR"

cp -r "$REPO_DIR/src" "$STAGE_DIR/src"
for f in index.php mcp.php; do
    sed "s#__DIR__ . '/../src/autoload.php'#__DIR__ . '/src/autoload.php'#" \
        "$REPO_DIR/public/$f" > "$STAGE_DIR/$f"
done

# --no-perms/--no-owner/--no-group: don't let rsync overwrite DEPLOY_DIR's own
# mode/ownership (needed for www-data to traverse it) with the staging dir's.
rsync -a --no-perms --no-owner --no-group --delete --exclude=config.php \
    "$STAGE_DIR/" "$DEPLOY_DIR/"

if [ ! -f "$DEPLOY_DIR/config.php" ]; then
    echo "warning: $DEPLOY_DIR/config.php does not exist — the server has no token configured." >&2
    echo "Copy config.php.example there and set a real token before using it." >&2
fi

echo "Deployed to $DEPLOY_DIR"
