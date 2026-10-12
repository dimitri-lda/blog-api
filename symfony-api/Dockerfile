# syntax=docker/dockerfile:1

FROM composer:2 AS composer

FROM php:8.4-fpm-alpine AS app

RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        oniguruma-dev \
        postgresql-dev \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        mbstring \
        opcache \
        pdo_pgsql \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && apk add --no-cache \
        icu-libs \
        libpq \
        oniguruma \
    && apk del .build-deps

COPY --from=composer /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /srv/app

COPY composer.json composer.lock symfony.lock ./
RUN composer install --prefer-dist --no-dev --no-scripts --no-autoloader --no-progress

COPY . .
RUN composer dump-autoload --optimize \
    && mkdir -p var/cache var/log \
    && chown -R www-data:www-data var

USER www-data
