FROM node:22-alpine AS frontend
WORKDIR /app/frontend
COPY frontend/package*.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

FROM php:8.3-fpm-bookworm AS php-base
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev openssl unzip \
    && docker-php-ext-install -j2 pdo_pgsql opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-mark manual libpq5 \
    && apt-get purge -y --auto-remove libpq-dev \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app

FROM php-base AS dev
ENV COMPOSER_HOME=/tmp/composer
COPY docker/php/dev.ini /usr/local/etc/php/conf.d/99-app.ini
CMD ["php-fpm"]

FROM php-base AS prod
ENV APP_ENV=prod APP_DEBUG=0
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --optimize-autoloader
COPY . .
COPY --from=frontend /app/public/app ./public/app
RUN composer run-script post-install-cmd && php bin/console cache:warmup
CMD ["php-fpm"]
