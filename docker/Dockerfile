FROM php:8.2-fpm AS dev

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip curl ca-certificates libzip-dev libpng-dev libonig-dev libxml2-dev \
    libpq-dev libsqlite3-dev pkg-config gnupg bash && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure zip && docker-php-ext-install -j$(nproc) \
    pdo pdo_mysql pdo_pgsql pdo_sqlite zip bcmath exif pcntl gd

RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && \
    apt-get update && apt-get install -y nodejs && rm -rf /var/lib/apt/lists/*

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www/html

COPY docker/entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]

CMD ["composer", "run", "dev"]
