FROM php:8.5-fpm

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git unzip libicu-dev libpq-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_pgsql pgsql intl; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

EXPOSE 9000

CMD ["php-fpm"]
