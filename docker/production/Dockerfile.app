FROM node:12-bullseye AS assets

WORKDIR /app

COPY package.json package-lock.json webpack.mix.js ./
COPY resources ./resources

RUN npm ci && npm run prod

FROM php:7.4-fpm-bullseye AS runtime

ARG APP_UID=1000
ARG APP_GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        default-mysql-client \
        ffmpeg \
        git \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
        procps \
        unzip \
        zip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd intl opcache pdo_mysql zip \
    && groupadd --gid "${APP_GID}" app \
    && useradd --uid "${APP_UID}" --gid app --home-dir /var/www/html --shell /bin/bash app \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY --chown=app:app . .
COPY --from=assets --chown=app:app /app/public/js ./public/js
COPY --from=assets --chown=app:app /app/public/css ./public/css
COPY docker/production/app-entrypoint.sh /usr/local/bin/app-entrypoint

RUN chmod +x /usr/local/bin/app-entrypoint \
    && mkdir -p storage/app/public/uploaded storage/app/public/converted storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/framework/views bootstrap/cache \
    && composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts \
    && php artisan package:discover --ansi \
    && chown -R app:app storage bootstrap/cache

USER app

ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]
