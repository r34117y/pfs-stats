FROM php:8.5-fpm

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libpq-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libfreetype6-dev; \
    pecl install xdebug; \
    pecl install redis; \
    docker-php-ext-enable xdebug; \
    docker-php-ext-enable redis; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_pgsql pgsql intl gd exif; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/entrypoint.sh /usr/local/bin/app-entrypoint
COPY docker/php/conf.d/xdebug.ini /usr/local/etc/php/conf.d/zz-xdebug.ini
COPY docker/php/php-cli /usr/local/bin/php-cli

RUN set -eux; \
    mv /usr/local/bin/php /usr/local/bin/php-real; \
    mv /usr/local/bin/php-cli /usr/local/bin/php; \
    chmod +x /usr/local/bin/php

WORKDIR /var/www/html

EXPOSE 9000

ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]
