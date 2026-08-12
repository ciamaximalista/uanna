#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
HOST="${UANNA_HTTP_HOST:-127.0.0.1}"
PORT="${UANNA_HTTP_PORT:-8001}"

cd "$ROOT_DIR"
exec /usr/bin/php -S "$HOST:$PORT" -t "$ROOT_DIR/oannes/public" "$ROOT_DIR/oannes/public/server.php"
