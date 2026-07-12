# syntax=docker/dockerfile:1

#######################################
# Stage 1: PHP dependencies
#######################################
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --prefer-dist \
        --optimize-autoloader

COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

#######################################
# Stage 2: Frontend assets
#######################################
FROM node:20-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
RUN npm run build

#######################################
# Stage 3: Runtime image
#######################################
FROM php:8.3-cli-alpine AS runtime

LABEL org.opencontainers.image.title="Ytoberr" \
      org.opencontainers.image.description="Painel self-hosted para arquivamento e monitoramento automatizado de canais do YouTube" \
      org.opencontainers.image.licenses="MIT"

# System packages:
#   bash/curl/tar/xz  -> needed by `make setup-bins` to fetch yt-dlp/ffmpeg at startup
#   python3           -> yt-dlp ships as a python zipapp
#   make              -> reuses the project's Makefile (setup-bins target)
#   supervisor        -> supervises the web server, queue worker and cron in a single container
#   sqlite-libs       -> SQLite runtime (with FTS5) used by pdo_sqlite
RUN apk add --no-cache \
        bash \
        curl \
        tar \
        xz \
        python3 \
        make \
        ca-certificates \
        tzdata \
        supervisor \
        sqlite-libs

# Install required PHP extensions
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/install-php-extensions
RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions pdo_sqlite sqlite3 bcmath pcntl opcache intl

WORKDIR /var/www/html

ENV APP_ENV=production \
    APP_DEBUG=false \
    APP_URL=http://localhost:8080 \
    APP_PORT=8080 \
    LOG_CHANNEL=stack \
    LOG_LEVEL=warning \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/var/www/html/storage/app/database.sqlite \
    SESSION_DRIVER=database \
    CACHE_STORE=database \
    QUEUE_CONNECTION=database \
    FILESYSTEM_DISK=local \
    PHP_CLI_SERVER_WORKERS=4 \
    PATH="/var/www/html/bin:${PATH}"

COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/crontab /etc/crontabs/root
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-ytoberr.ini

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p \
        bin \
        storage/app/public/channels \
        storage/app/public/downloads \
        storage/app/private \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && touch storage/app/database.sqlite \
    && ln -sfn /var/www/html/storage/app/public /var/www/html/public/storage \
    && chmod 0600 /etc/crontabs/root

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:${APP_PORT}/up" || exit 1

VOLUME ["/var/www/html/storage/app", "/var/www/html/bin"]

ENTRYPOINT ["entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf", "-n"]
