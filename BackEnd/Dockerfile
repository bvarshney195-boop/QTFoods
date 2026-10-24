FROM php:8.5-cli@sha256:9ebdf4c28ab12c02085e171c31e22ac5f7bbb6a9f6927e3bc3dfe7ee23df51e0

RUN apt-get update \
    && apt-get install -y --no-install-recommends $PHPIZE_DEPS libpq-dev libsqlite3-dev unzip \
    && docker-php-ext-install -j"$(nproc)" bcmath pdo_pgsql pdo_sqlite \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2@sha256:d8f6343d3fae98107426bc49163ccad46ef85aabd4a27d80a74401fab4aba332 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-progress

COPY . .
RUN mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && APP_ENV=build composer dump-autoload --no-interaction --optimize \
    && chown -R www-data:www-data bootstrap/cache storage

USER www-data

EXPOSE 8000

CMD ["sh", "-lc", "php artisan migrate --force && php artisan db:seed --force && php artisan serve --host=0.0.0.0 --port=8000"]
