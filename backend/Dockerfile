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
# Smoke:  docker run --rm -p 8080:8080 -e APP_KEY=base64:... \
#           -e SESSION_SECURE_COOKIE=true ai-language-coach/api
#         then: curl -i localhost:8080/up
#
#         SESSION_SECURE_COOKIE is required because a bare `docker run` has no
#         APP_ENV, so the image boots as production and ProductionSanityCheck
#         refuses the dev-default `false`. Any deployment supplies it too.
#
# Local development does NOT use this image: Laravel runs natively against the
# docker-compose MySQL/Redis (see README -> "Local setup"). This image exists for
# CI, staging and ECS.

# Pinned to the patched release for CVE-2026-109618 / CVE-2026-109619
# (PHP 8.5.12, 2026-10-21). Bump deliberately with security releases.
ARG PHP_VERSION=8.5.12
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
#
# APP_ENV=local is load-bearing, not cosmetic. With no APP_ENV and no .env in the
# build context, Laravel's default is `production`, and `AppServiceProvider::boot()`
# runs `ProductionSanityCheck` on every boot — including a build-time
# `package:discover`. The check therefore fails the *build* with
# "SESSION_SECURE_COOKIE must be true in production", which is a true statement
# about a deployment and a false one about compiling an autoloader. Marking this
# one invocation as what it is (a build step, with no runtime configuration at
# all) keeps the guard intact where it matters: the container's own `php artisan`
# commands still boot with whatever APP_ENV the deployment supplies, and refuse
# to start on development defaults.
RUN mkdir -p bootstrap/cache \
    && APP_ENV=local composer dump-autoload --no-dev --optimize --no-interaction

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
# Stage 4 — runtime-base: shared application filesystem for web and worker roles.
# ---------------------------------------------------------------------------
FROM php-base AS runtime-base

RUN apk add --no-cache nginx supervisor su-exec

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
#
# The entrypoint keeps `supervisord` (which spawns php-fpm workers as www-data and
# nginx workers as nginx) working as PID 1 while dropping privileges to `www-data`
# whenever the image is invoked directly for a worker or CLI command (`horizon`,
# `reverb:start`, `schedule:work`, `migrate`).
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
    && chown -R nginx:nginx /var/lib/nginx \
    && printf '%s\n' \
        '#!/bin/sh' \
        'set -e' \
        'if [ "$(id -u)" -eq 0 ] && [ "${1:-}" != "supervisord" ]; then' \
        '    exec su-exec www-data "$@"' \
        'fi' \
        'exec "$@"' \
        > /usr/local/bin/docker-entrypoint.sh \
    && chmod 755 /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]

# ---------------------------------------------------------------------------
# Dedicated non-root worker targets (`--target horizon|reverb|scheduler`).
# Placed before the final `runtime` stage so a default `docker build ./backend`
# continues to produce the web/API image while both targeted builds and
# single-image worker containers run as `www-data` instead of `root`.
# ---------------------------------------------------------------------------
FROM runtime-base AS horizon

USER www-data

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php artisan horizon:status || exit 1

CMD ["php", "artisan", "horizon"]

FROM runtime-base AS reverb

USER www-data

EXPOSE 8081

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD php -r 'exit(@fsockopen("127.0.0.1", 8081) ? 0 : 1);'

CMD ["php", "artisan", "reverb:start", "--host=0.0.0.0", "--port=8081"]

FROM runtime-base AS scheduler

USER www-data

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD pgrep -f 'artisan schedule:work' > /dev/null || exit 1

CMD ["php", "artisan", "schedule:work"]

# ---------------------------------------------------------------------------
# Stage 5 — runtime (default build target): nginx + php-fpm under supervisord
# on :8080, with automatic privilege drop to `www-data` for worker commands.
# ---------------------------------------------------------------------------
FROM runtime-base AS runtime

EXPOSE 8080

# For `docker run` / compose. In ECS the ALB target group checks /up instead.
HEALTHCHECK --interval=30s --timeout=3s --start-period=20s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1:8080/up || exit 1

CMD ["supervisord", "-c", "/etc/supervisord.conf"]
