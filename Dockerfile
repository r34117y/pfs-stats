FROM php:8.5-fpm

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git unzip libicu-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql intl; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock symfony.lock ./
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --no-scripts

COPY . .

RUN set -eux; \
    mkdir -p var/cache var/log; \
    chown -R www-data:www-data var

EXPOSE 9000

CMD ["php-fpm"]
