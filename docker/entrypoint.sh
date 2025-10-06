#!/usr/bin/env bash
set -e

# getent group 1000 >/dev/null || groupadd -g 1000 app
# id -u 1000 >/dev/null 2>&1 || useradd -u 1000 -g 1000 -m -s /bin/bash app

# cd /var/www/html

# mkdir -p \
#   storage/framework/{cache,sessions,views} \
#   storage/logs \
#   bootstrap/cache \
#   database
# [ -f database/database.sqlite ] || touch database/database.sqlite
# [ -f storage/logs/laravel.log ] || touch storage/logs/laravel.log

# chmod -R 777 storage bootstrap/cache
# chmod 666 storage/logs/laravel.log || true

# su -s /bin/bash -c "composer install --no-interaction" app
# su -s /bin/bash -c "npm install" app

# php artisan key:generate --force || true
# php artisan migrate --force || true
# php artisan storage:link || true

# if [ "${APP_ENV:-local}" = "local" ]; then
#  su -s /bin/bash -c "npm run dev -- --host 0.0.0.0 --port ${VITE_PORT:-5173}" app &
# else
#  su -s /bin/bash -c "npm run build" app
# fi

exec $@
