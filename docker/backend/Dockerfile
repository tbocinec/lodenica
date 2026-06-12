# Lodenica backend dev image.
#
# Slim PHP 8.3 with the extensions Laravel + Postgres + Sanctum need.
# Composer is brought in from its official image. Application code is
# bind-mounted by docker-compose; `vendor/` lives in a named volume so
# the container's Linux binaries don't clash with the developer's host
# (where `composer install` may have left macOS or non-musl artefacts).
#
# The entrypoint runs `composer install` on first boot, generates
# .env + APP_KEY when missing, and applies migrations + the idempotent
# seeder before handing off to `php artisan serve`.

FROM php:8.3-cli-alpine

# Build deps + runtime libs. `oniguruma-dev` is required by mbstring,
# `postgresql-dev` by pdo_pgsql/pgsql, `libzip-dev` by zip.
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        postgresql-dev \
        libzip-dev \
        oniguruma-dev \
        icu-dev \
 && apk add --no-cache \
        postgresql-libs \
        libzip \
        oniguruma \
        icu-libs \
        git \
        bash \
 && docker-php-ext-install \
        pdo_pgsql pgsql zip mbstring opcache \
 && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY docker/backend/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
