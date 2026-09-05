# syntax=docker/dockerfile:1

# -----------------------------------------------------------------------------
# Stage 1: Frontend assets (Vite)
# -----------------------------------------------------------------------------
FROM node:22-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts

COPY vite.config.js vite.hybrid.config.js tailwind.config.js ./
COPY resources ./resources
COPY public ./public

RUN npm run build

# -----------------------------------------------------------------------------
# Stage 2: PHP dependencies (Composer)
# -----------------------------------------------------------------------------
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

# -----------------------------------------------------------------------------
# Stage 3: Production PHP-FPM runtime
# -----------------------------------------------------------------------------
FROM php:8.3-fpm-bookworm AS app

ARG APP_ENV=production
ENV APP_ENV=${APP_ENV} \
    COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update && apt-get install -y --no-install-recommends \
        curl \
        git \
        unzip \
        rsync \
        libicu-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libzip-dev \
        libonig-dev \
        libpq-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        exif \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-shepard.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-shepard.conf

WORKDIR /var/www/html

COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=frontend --chown=www-data:www-data /app/public/build ./public/build

# Seed copy for the shared nginx/public volume (overlay mounts start empty)
RUN mkdir -p \
        storage/framework/{cache,sessions,views} \
        storage/logs \
        storage/app/public \
        bootstrap/cache \
    && cp -a public /opt/public-seed \
    && chown -R www-data:www-data storage bootstrap/cache /opt/public-seed \
    && chmod -R ug+rwx storage bootstrap/cache \
    && rm -f public/hot \
    && php -r "file_exists('vendor/autoload.php') || exit(1);"

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Entrypoint runs as root to seed volumes / fix perms; php-fpm drops to www-data
EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm", "-F"]

# -----------------------------------------------------------------------------
# Stage 4: Nginx with baked public assets (served from this image)
# -----------------------------------------------------------------------------
FROM nginx:1.27-alpine AS nginx

COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public

# Uploaded files live on the shared storage volume mounted at runtime.
RUN ln -sfn ../storage/app/public /var/www/html/public/storage

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD wget -qO- http://127.0.0.1/healthz || exit 1
