#!/usr/bin/env bash
set -e

cd /var/www/html

[ -f .env ] || cp .env.example .env

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache database
[ -f database/database.sqlite ] || touch database/database.sqlite
[ -f storage/logs/laravel.log ] || touch storage/logs/laravel.log

chown -R www-data:www-data storage bootstrap/cache database
chmod -R ug+rwX storage bootstrap/cache database

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
