# The shop server's images (spec section 4; versions pinned in D20). Build from the repository root, so
# the app image can carry the catalogue seed from docs/catalogue:
#   docker build -f apps/shop-server/docker/Dockerfile --target dev .   development PHP; code bind-mounted
#   docker build -f apps/shop-server/docker/Dockerfile --target app .   a shop's PHP-FPM, code baked in
#   docker build -f apps/shop-server/docker/Dockerfile --target web .   a shop's nginx, with the built assets
#   docker build -f apps/shop-server/docker/Dockerfile --target db .    a shop's PostgreSQL, with the role script
# CI publishes app, web and db to GHCR on every merge to main.

ARG PHP_IMAGE=php:8.5.12-fpm-trixie
ARG COMPOSER_IMAGE=composer:2.11.1
ARG NODE_IMAGE=node:26.13.0-alpine3.24
ARG NGINX_IMAGE=nginx:1.30.6-alpine
ARG POSTGRES_IMAGE=postgres:18.6-trixie

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

# --- Development: Composer, git and unzip; the code is bind-mounted ---------------------------------
FROM base AS dev

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git unzip; \
    rm -rf /var/lib/apt/lists/*; \
    cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

COPY --from=composer /usr/bin/composer /usr/bin/composer

ENV COMPOSER_HOME=/tmp/composer

# --- The PHP packages, without development tools -----------------------------------------------------
FROM dev AS vendor

COPY apps/shop-server/composer.json apps/shop-server/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist
COPY apps/shop-server/ ./
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

# --- The built frontend ------------------------------------------------------------------------------
FROM ${NODE_IMAGE} AS assets

WORKDIR /app
COPY apps/shop-server/package.json apps/shop-server/package-lock.json apps/shop-server/.npmrc ./
RUN npm ci
COPY apps/shop-server/ ./
RUN npm run build

# --- A shop: code, packages and assets baked in, no development tools -------------------------------
FROM base AS app

ARG APP_VERSION=dev

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY apps/shop-server/docker/php/conf.d/production.ini /usr/local/etc/php/conf.d/zz-production.ini

COPY --from=vendor --chown=www-data:www-data /app /app
COPY --from=assets --chown=www-data:www-data /app/public/build /app/public/build
# The starter catalogue's master file (R8), read by catalogue:seed.
COPY docs/catalogue/starter-catalogue-v1.1.json /catalogue/starter-catalogue-v1.1.json

RUN set -eux; \
    rm -rf /app/tests /app/e2e /app/docker/postgres /app/node_modules /app/.env /app/public/hot; \
    mkdir -p /app/storage/app /app/storage/framework/cache/data /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs /tls; \
    chown -R www-data:www-data /app/storage /app/bootstrap/cache /tls

ENV APP_NAME="HAQ Retail OS" \
    APP_ENV=production \
    APP_DEBUG=false \
    APP_VERSION=${APP_VERSION} \
    LOG_CHANNEL=stderr \
    CATALOGUE_SEED=/catalogue/starter-catalogue-v1.1.json

USER www-data

# --- A shop's nginx: HTTPS with the shop's own certificate, and the built assets ------------------------
FROM ${NGINX_IMAGE} AS web

COPY apps/shop-server/docker/nginx/shop.conf /etc/nginx/conf.d/default.conf
COPY --from=assets /app/public /app/public

# --- A shop's PostgreSQL, with the script the one-shot `roles` job runs to create the three roles -------
FROM ${POSTGRES_IMAGE} AS db

# pgBackRest from the PostgreSQL project's own packages, pinned (spec section 9) [S16, R6], jq for the
# backup loop, and age and ssss for the encrypted off-site copy and its 2-of-3 key shares [S17, D53].
# /backup is the second disk; a new volume takes its owner from here.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends pgbackrest=2.60.0-1.pgdg13+1 jq age=1.2.1-1+b5 ssss=0.5-5; \
    rm -rf /var/lib/apt/lists/*; \
    mkdir -p /backup /etc/pgbackrest; \
    chown postgres:postgres /backup

COPY --chmod=755 apps/shop-server/docker/postgres/init/01-roles.sh /usr/local/bin/haq-roles.sh
COPY --chmod=755 apps/shop-server/docker/postgres/haq-backup.sh /usr/local/bin/haq-backup.sh
COPY --chmod=755 apps/shop-server/docker/postgres/haq-offsite.sh /usr/local/bin/haq-offsite.sh
COPY --chmod=644 apps/shop-server/docker/postgres/pgbackrest.conf /etc/pgbackrest/pgbackrest.conf

# WAL goes to the backup disk continuously; a quiet shop still archives at least every 15 minutes, the
# most a disk loss may cost (spec section 9) [F2]. Until the backup service has made the stanza there is
# nowhere to archive, so a segment is let go; afterwards a failure keeps it in pg_wal until the backup
# disk answers again, and the health page warns.
CMD ["postgres", "-c", "archive_mode=on", "-c", "archive_command=test ! -f /backup/repo/archive/shop/archive.info || pgbackrest --stanza=shop archive-push %p", "-c", "archive_timeout=900", "-c", "wal_compression=zstd"]
