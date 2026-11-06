# The shop server's PHP image (spec section 4; versions pinned in D20).
# Build from the repository root, so the image can later carry the catalogue seed from docs/catalogue:
#   docker build -f apps/shop-server/docker/php/Dockerfile --target dev .
# Targets: base (PHP-FPM and the extensions the app needs) and dev (adds Composer, git and unzip;
# the code is bind-mounted). The production target, with the code baked in, comes with the GHCR images.

ARG PHP_IMAGE=php:8.5.12-fpm-trixie
ARG COMPOSER_IMAGE=composer:2.11.1

FROM ${COMPOSER_IMAGE} AS composer

FROM ${PHP_IMAGE} AS base

# pdo_pgsql for PostgreSQL, intl for money and number formatting, pcntl so queue workers stop cleanly,
# bcmath for exact arithmetic. OPcache is built into PHP 8.5. The -dev packages are removed after the build.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libpq5 libicu76 libpq-dev libicu-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql intl pcntl bcmath; \
    apt-get purge -y --auto-remove libpq-dev libicu-dev; \
    rm -rf /var/lib/apt/lists/*

COPY apps/shop-server/docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /app

FROM base AS dev

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git unzip; \
    rm -rf /var/lib/apt/lists/*; \
    cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

COPY --from=composer /usr/bin/composer /usr/bin/composer

ENV COMPOSER_HOME=/tmp/composer
