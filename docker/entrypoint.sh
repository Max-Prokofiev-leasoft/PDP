#!/usr/bin/env bash
set -e

cd /var/www/html

[ -f .env ] || cp .env.example .env

mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
touch storage/logs/laravel.log

chmod -R u+rwX,g+rwX storage bootstrap/cache || true

if [ ! -d vendor ]; then
  composer install --no-interaction --prefer-dist
fi

if [ ! -d node_modules ]; then
  if [ -f package-lock.json ]; then npm ci; else npm install; fi
fi

php artisan key:generate --force || true
php artisan migrate --force || true
php artisan storage:link || true

exec "$@"
