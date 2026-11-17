FROM php:8.2-cli-alpine

RUN apk add --no-cache \
    git \
    unzip \
    bash \
    libzip-dev \
    libpq

# REQ-TEST-005: pdo_mysql / pdo_pgsql for the real-server suite (make test-with-db).
RUN apk add --no-cache --virtual .db-build-deps postgresql-dev \
    && docker-php-ext-install -j$(nproc) zip pdo_mysql pdo_pgsql \
    && apk del .db-build-deps

RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN git config --global --add safe.directory /app

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PATH="/app/vendor/bin:${PATH}"
