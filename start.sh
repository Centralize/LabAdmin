#!/bin/sh
# Start LabAdmin with PHP's built-in server (parallel workers, Linux/macOS only).
cd "$(dirname "$0")"
[ -f vendor/autoload.php ] || composer install --no-dev
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-8}"
exec php -S "${LABADMIN_LISTEN:-0.0.0.0:8080}" -t public
