# PHP 8.5.12 is the latest stable release verified on 2026-11-14.
FROM php:8.5.12-fpm-bookworm AS base
RUN apt-get update && apt-get install -y --no-install-recommends \
    libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev libicu-dev \
    libonig-dev libxml2-dev libsqlite3-dev libwebp-dev libreoffice-writer fonts-dejavu-core fonts-crosextra-carlito unzip git gosu \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_sqlite mbstring bcmath gd intl zip pcntl exif \
    && mkdir -p /usr/share/fonts/google-carlito-fonts \
    && ln -s /usr/share/fonts/truetype/crosextra/Carlito-Regular.ttf /usr/share/fonts/google-carlito-fonts/Carlito-Regular.ttf \
    && ln -s /usr/share/fonts/truetype/crosextra/Carlito-Bold.ttf /usr/share/fonts/google-carlito-fonts/Carlito-Bold.ttf \
    && rm -rf /var/lib/apt/lists/*
COPY docker/php/mapr.ini /usr/local/etc/php/conf.d/zz-mapr.ini
COPY docker/php/fpm.conf /usr/local/etc/php-fpm.d/zz-mapr.conf
COPY docker/scripts/entrypoint.sh /usr/local/bin/mapr-entrypoint
WORKDIR /var/www/html
ENTRYPOINT ["mapr-entrypoint"]
CMD ["php-fpm"]

FROM base AS development
ENV COMPOSER_HOME=/tmp/composer
ARG LOCAL_UID=1000
ARG LOCAL_GID=1000
RUN groupmod -o -g "$LOCAL_GID" www-data && usermod -o -u "$LOCAL_UID" -g "$LOCAL_GID" www-data
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN mkdir -p vendor storage/app/public storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/fonts bootstrap/cache \
    && chown -R www-data:www-data vendor storage bootstrap

FROM base AS dependencies
ENV COMPOSER_HOME=/tmp/composer
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer/cache composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-interaction

FROM node:22-bookworm-slim AS assets
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci
COPY resources resources
COPY vite.config.js ./
RUN npm run build

FROM base AS production
COPY --from=dependencies --chown=www-data:www-data /var/www/html /var/www/html
COPY --from=assets --chown=www-data:www-data /build/public/build /var/www/html/public/build
RUN mkdir -p storage/app/public storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/fonts bootstrap/cache \
    && ln -s ../storage/app/public public/storage \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

FROM nginx:stable-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=production /var/www/html/public /var/www/html/public

FROM node:22-bookworm-slim AS vite-development
ARG LOCAL_UID=1000
ARG LOCAL_GID=1000
ENV HOME=/home/mapr NPM_CONFIG_CACHE=/home/mapr/.npm
WORKDIR /var/www/html
RUN mkdir -p node_modules /home/mapr/.npm \
    && chown -R "$LOCAL_UID:$LOCAL_GID" node_modules /home/mapr
