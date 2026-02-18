FROM php:8.5-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git unzip libicu-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql intl; \
    sed -ri -e "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" \
      /etc/apache2/sites-available/*.conf \
      /etc/apache2/apache2.conf \
      /etc/apache2/conf-available/*.conf; \
    a2enmod rewrite; \
    rm -rf /var/lib/apt/lists/*

RUN printf '%s\n' \
  '<Directory "/var/www/html/public">' \
  '    AllowOverride All' \
  '    Require all granted' \
  '</Directory>' \
  > /etc/apache2/conf-available/symfony.conf \
  && a2enconf symfony

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

EXPOSE 80

CMD ["apache2-foreground"]
