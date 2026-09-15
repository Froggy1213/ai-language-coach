# syntax=docker/dockerfile:1

# Production image for the Laravel API (Lighthouse GraphQL).
#
# One image covers every Laravel role in the ECS task family — the command decides
# which one runs:
#
#   web (default CMD)  supervisord -> nginx :8080 + php-fpm
#   horizon worker     php artisan horizon
#   reverb             php artisan reverb:start --host=0.0.0.0 --port=8081
#   migrations         php artisan migrate --force
#
# Build:  docker build -t ai-language-coach/api ./backend
# Smoke:  docker run --rm -p 8080:8080 -e APP_KEY=base64:... ai-language-coach/api
#         then: curl -i localhost:8080/up
#
# Local development does NOT use this image: Laravel runs natively against the
# docker-compose MySQL/Redis (see README -> "Local setup"). This image exists for
# CI, staging and ECS.

ARG PHP_VERSION=8.5
ARG NODE_VERSION=24

# ---------------------------------------------------------------------------
# Stage 1 — php-base: PHP-FPM plus exactly the extensions composer.lock needs.
#
# The official image already compiles and enables: mbstring, curl, dom, xml,
# xmlwriter, tokenizer, ctype, filter, hash, iconv, openssl, session, sodium,
# opcache, posix. Added here: pdo_mysql, bcmath, pcntl (required by Horizon)
# and the redis extension.
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS php-base

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath pcntl \
    && pecl install redis < /dev/null \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# ---------------------------------------------------------------------------
# Stage 2 — vendor: composer dependencies, dev packages excluded.
# ---------------------------------------------------------------------------
FROM php-base AS vendor

RUN apk add --no-cache unzip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Manifests first so the dependency layer stays cached across app changes.
COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --no-scripts \
        --no-autoloader

COPY . .

# Finishes the autoloader and runs the post-autoload-dump hook
# (artisan package:discover, which needs bootstrap/cache to exist).
RUN mkdir -p bootstrap/cache \
    && composer dump-autoload --no-dev --optimize --no-interaction

# ---------------------------------------------------------------------------
# Stage 3 — assets: Laravel's Vite bundle.
#
# resources/views/welcome.blade.php calls @vite(), so public/build must exist or
# that route throws a ViteException. The GraphQL API itself needs no assets, but
# building them keeps the image self-consistent.
# ---------------------------------------------------------------------------
FROM node:${NODE_VERSION}-alpine AS assets

WORKDIR /app

# No package-lock.json is committed for the backend, so `npm install`, not `npm ci`.
# .npmrc (ignore-scripts=true) is copied too so the build behaves like local dev.
COPY package.json .npmrc ./
RUN npm install --no-audit --no-fund

COPY . .
RUN npm run build

# ---------------------------------------------------------------------------
# Stage 4 — runtime: nginx + php-fpm under supervisord, listening on :8080.
# ---------------------------------------------------------------------------
FROM php-base AS runtime

RUN apk add --no-cache nginx supervisor

WORKDIR /var/www/html

COPY docker/php-prod.ini /usr/local/etc/php/conf.d/zz-prod.ini
COPY docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf

COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build

# Writable paths (excluded from the build context, so they are recreated here) and
# nginx's temp directories, which are absent because nginx.conf is replaced.
#
# The permission pass is not cosmetic: COPY preserves the source file modes, and a
# checkout can easily carry files that are unreadable to www-data (a 0600 file
# makes Laravel fail to load that config file at boot). Normalising here keeps the
# image identical no matter which machine or CI runner built it.
RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
        /var/lib/nginx/tmp/client_body \
        /var/lib/nginx/tmp/proxy \
        /var/lib/nginx/tmp/fastcgi \
        /var/lib/nginx/tmp/uwsgi \
        /var/lib/nginx/tmp/scgi \
    && chown -R root:root /var/www/html \
    && chmod -R a+rX /var/www/html \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache \
    && chown -R nginx:nginx /var/lib/nginx

EXPOSE 8080

# For `docker run` / compose. In ECS the ALB target group checks /up instead.
HEALTHCHECK --interval=30s --timeout=3s --start-period=20s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1:8080/up || exit 1

CMD ["supervisord", "-c", "/etc/supervisord.conf"]
