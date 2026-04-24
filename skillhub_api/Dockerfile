# syntax=docker/dockerfile:1.7

FROM php:8.2-cli-alpine AS base

RUN apk add --no-cache \
        git \
        unzip \
        libzip-dev \
        libpng-dev \
        oniguruma-dev \
        libxml2-dev \
        mysql-client \
        mariadb-connector-c-dev \
        curl \
        bash \
        icu-dev \
        autoconf \
        g++ \
        make \
 && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        zip \
        bcmath \
 && apk del autoconf g++ make

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --no-dev --prefer-dist

COPY . .

RUN composer dump-autoload --optimize --no-dev \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chmod -R 775 storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
