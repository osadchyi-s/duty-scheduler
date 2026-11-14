# One Dockerfile, several build targets:
#   dev  - local development (docker-compose.yml); the code is mounted from the host
#   prod - php-fpm application image for production/staging (app and queue worker)
#   web  - nginx image that serves public/ and proxies PHP requests to the app
# See README "Production deployment" for the production build and deployment.

# ---- base: PHP runtime shared by every target ----
FROM php:8.4-fpm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev libzip-dev libpng-dev libonig-dev libxml2-dev libicu-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install pdo pdo_pgsql pgsql zip gd bcmath intl opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www

# ---- composer: tooling for installing PHP dependencies ----
FROM base AS composer

RUN apt-get update && apt-get install -y --no-install-recommends git curl unzip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_MEMORY_LIMIT=-1
ENV COMPOSER_ALLOW_SUPERUSER=1

# ---- dev: local development ----
FROM composer AS dev

COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]

# ---- build: production dependencies and optimized autoloader ----
FROM composer AS build

# Dependencies first, so this layer is reused while only the application code changes.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
# Package discovery is regenerated here: the cache committed in bootstrap/cache lists dev packages.
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && php artisan package:discover --ansi

# ---- prod: application image (php-fpm, also used for the queue worker) ----
FROM base AS prod

COPY docker/php/prod.ini /usr/local/etc/php/conf.d/zz-prod.ini
COPY docker/entrypoint.prod.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

COPY --from=build --chown=www-data:www-data /var/www /var/www

# Writable directories must exist in the image: a new named volume mounted on
# storage/app takes over their owner (www-data) instead of root.
RUN mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
# www-data has no writable home directory; tools such as "artisan tinker" store their config there.
ENV HOME=/tmp

EXPOSE 9000
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]

# ---- web: nginx in front of the app ----
FROM nginx:1.27-alpine AS web

COPY docker/nginx/prod.conf /etc/nginx/conf.d/default.conf
COPY --from=build /var/www/public /var/www/public
