# syntax=docker/dockerfile:1
FROM php:8.5-fpm-alpine AS base
COPY --from=mlocati/php-extension-installer:2.12 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions intl pdo_pgsql pgsql gd bcmath zip \
    && apk add --no-cache tzdata xz
ENV TZ=Europe/Bratislava
COPY docker/php/conf.d/app.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY docker/php/fpm/zz-www.conf /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/php/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint
WORKDIR /var/www/html
ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm"]

FROM base AS dev
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN apk add --no-cache git unzip poppler-utils fcgi postgresql18-client \
    && cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY docker/php/conf.d/opcache-dev.ini $PHP_INI_DIR/conf.d/zz-opcache.ini

FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative

FROM base AS prod
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/conf.d/opcache-prod.ini $PHP_INI_DIR/conf.d/zz-opcache.ini
COPY --from=vendor /var/www/html /var/www/html
