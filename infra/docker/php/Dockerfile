# RefConcept API runtime — PHP 8.3
# Stack lock: 19_TECH_STACK_LOCK.md (Laravel/PHP, PostgreSQL, Redis, S3-compatible storage)
FROM php:8.3-fpm-alpine AS base

RUN apk add --no-cache \
        bash \
        git \
        icu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        postgresql-dev \
        oniguruma-dev \
        linux-headers \
        supervisor \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        gd \
        zip \
        bcmath \
        intl \
        opcache \
        pcntl \
        sockets \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && pecl clear-cache \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY infra/docker/php/php.ini /usr/local/etc/php/conf.d/99-refconcept.ini

# Non-root uid/gid kept at 1000 so bind-mounted files stay writable on Linux CI.
RUN adduser -D -u 1000 -g 1000 refconcept 2>/dev/null || true

WORKDIR /var/www/html

EXPOSE 9000
CMD ["php-fpm"]
