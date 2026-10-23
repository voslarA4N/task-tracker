#!/bin/sh
set -eu

mkdir -p writable/cache writable/logs writable/session writable/uploads writable/debugbar writable/mpdf
chown -R www-data:www-data writable

if [ -f composer.json ] && [ ! -f vendor/autoload.php ] && command -v composer >/dev/null 2>&1; then
    composer install --no-interaction --prefer-dist
fi

exec docker-php-entrypoint "$@"
