# One image, three roles on Railway: web (Octane on FrankenPHP), worker (queues), scheduler.
# The role comes from the APP_ROLE variable each Railway service sets. See docs/runbooks/deploy-railway.md.

FROM dunglas/frankenphp:1-php8.4-bookworm

RUN install-php-extensions \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        redis \
        zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

ENV APP_ENV=production \
    APP_DEBUG=false \
    OCTANE_SERVER=frankenphp \
    COMPOSER_ALLOW_SUPERUSER=1

# Dependencies first, so a code-only change reuses this layer.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .

RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-upsell.ini"
COPY docker/entrypoint.sh /usr/local/bin/upsell-entrypoint
RUN chmod +x /usr/local/bin/upsell-entrypoint

EXPOSE 8080

ENTRYPOINT ["upsell-entrypoint"]
