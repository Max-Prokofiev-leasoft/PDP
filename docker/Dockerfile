# ===== Base =====
FROM --platform=linux/amd64 php:8.2-fpm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip curl ca-certificates libzip-dev libpng-dev libonig-dev libxml2-dev \
    libpq-dev libsqlite3-dev pkg-config gnupg bash && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-configure zip && docker-php-ext-install -j$(nproc) \
    pdo pdo_mysql pdo_pgsql pdo_sqlite zip bcmath exif pcntl gd
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www/html

RUN groupadd -g 1000 app && useradd -u 1000 -g 1000 -m -s /bin/bash app
RUN sed -ri 's/^user = .*/user = app/; s/^group = .*/group = app/' /usr/local/etc/php-fpm.d/www.conf && \
    sed -ri 's@^;?listen.owner = .*@listen.owner = app@; s@^;?listen.group = .*@listen.group = app@; s@^;?listen.mode = .*@listen.mode = 0660@' /usr/local/etc/php-fpm.d/www.conf

# ===== Development =====
FROM base AS dev

RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && \
    apt-get update && apt-get install -y nodejs && rm -rf /var/lib/apt/lists/*

COPY docker/entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh

USER 1000:1000

ENTRYPOINT ["/entrypoint.sh"]

CMD ["composer","run","dev"]

# ===== Assets (build) =====
FROM dev AS assets

WORKDIR /var/www/html

USER 1000:1000

COPY --chown=app:app composer.* ./

RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-progress

COPY --chown=app:app package*.json ./

RUN npm ci --no-audit --no-fund || npm i

COPY --chown=app:app . .

RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi || true

RUN npm run build

# ===== Production =====
FROM base AS prod

WORKDIR /var/www/html

USER 1000:1000

COPY --chown=app:app composer.* ./

RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-progress

COPY --chown=app:app . .

RUN rm -f public/hot

COPY --from=assets /var/www/html/public/build /var/www/html/public/build

RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && touch storage/logs/laravel.log \
    && chmod -R u+rwX,g+rwX storage bootstrap/cache

RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi || true

# ===== NGINX =====
FROM nginx:alpine AS nginx

COPY --from=prod /var/www/html/public /var/www/html/public
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
