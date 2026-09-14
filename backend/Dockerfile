FROM php:8.3-cli-alpine

# Устанавливаем системные утилиты и расширения PHP
RUN apk add --no-cache bash curl git libpng-dev libzip-dev zip unzip $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_mysql bcmath \
    && pecl install redis \
    && docker-php-ext-enable redis

# Копируем Composer для управления PHP-пакетами
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www