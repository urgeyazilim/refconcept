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

# ---------- production ----------
#
# The base stage above is the development runtime: it carries composer and a git client and
# expects the application to arrive through a synced volume, which is the right shape for a
# machine where somebody is editing files and the wrong shape for a server.
#
# This stage is self-contained. The source is copied in, the dependencies are resolved at
# build time, and there is no volume to fill — so what CI built is exactly what runs, and a
# rollback is a redeploy of an older image rather than a re-sync of older files.
#
#   docker build -f infra/docker/php/Dockerfile --target production -t refconcept-api .

FROM base AS vendor

WORKDIR /var/www/html

# Manifests first, so a change to application code does not invalidate the dependency layer.
# A deploy that only touched a controller should not re-resolve every package.
COPY apps/api/composer.json apps/api/composer.lock ./

# --no-scripts because the scripts want an application that is not here yet; they run in the
# final stage once the source has arrived.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --prefer-dist

COPY apps/api ./

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && composer run-script post-autoload-dump --no-interaction

FROM php:8.3-fpm-alpine AS production

# Runtime libraries only. The build toolchain, composer and git stay behind in the vendor
# stage: an image that can compile things is an image an intruder can compile things in.
RUN apk add --no-cache \
        bash \
        icu-libs \
        libzip \
        libpng \
        libjpeg-turbo \
        freetype \
        libpq \
        oniguruma \
        postgresql-client \
        tzdata

COPY --from=base /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=base /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

COPY infra/docker/php/php.ini /usr/local/etc/php/conf.d/99-refconcept.ini
# Loaded last on purpose. PHP reads conf.d alphabetically and the later file wins, so the
# production overrides must sort after 99-refconcept.ini — a leading number would sort
# before it and silently lose every setting in it.
COPY infra/docker/php/php.production.ini /usr/local/etc/php/conf.d/zz-production.ini
COPY infra/docker/php/entrypoint.sh /usr/local/bin/refconcept-entrypoint

RUN chmod +x /usr/local/bin/refconcept-entrypoint \
    && adduser -D -u 1000 -g 1000 refconcept

WORKDIR /var/www/html

COPY --from=vendor --chown=refconcept:refconcept /var/www/html ./

# Laravel writes here at runtime and the image is otherwise read-only by intent.
RUN chown -R refconcept:refconcept storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

USER refconcept

EXPOSE 9000

ENTRYPOINT ["refconcept-entrypoint"]
CMD ["php-fpm"]

# ---------- web ----------
#
# nginx with the application's `public/` baked in.
#
# In development the two containers share a volume that `sync.sh` fills, which cannot work
# here: the production application lives inside its own image and nothing outside it can
# read the directory. So the web server gets the static half of the application at build
# time, from the same vendor stage the PHP container is built from — one build, two images,
# and no window where the assets and the code they belong to are different versions.

FROM nginx:1.27-alpine AS web

COPY infra/docker/nginx/production.conf /etc/nginx/conf.d/default.conf
COPY --from=vendor /var/www/html/public /var/www/html/public

EXPOSE 80
