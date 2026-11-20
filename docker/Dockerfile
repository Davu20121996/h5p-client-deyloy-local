FROM php:8.4-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev unzip \
    && docker-php-ext-install pdo_pgsql opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
WORKDIR /var/www/app
