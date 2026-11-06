#!/bin/sh
# Start LabAdmin: the web UI (PHP built-in server, parallel workers) + the terminal service.
cd "$(dirname "$0")"
[ -f vendor/autoload.php ] || composer install --no-dev
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-8}"

php bin/terminal-server.php &
TERM_PID=$!
trap 'kill $TERM_PID 2>/dev/null' EXIT INT TERM

echo "LabAdmin on http://${LABADMIN_LISTEN:-0.0.0.0:8080}/  (terminal service pid $TERM_PID)"
php -S "${LABADMIN_LISTEN:-0.0.0.0:8080}" -t public
