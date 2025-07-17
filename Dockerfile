FROM trafex/php-nginx:3.4.0 AS php

USER root

RUN apk add --no-cache \
    git \
    php82-iconv \
    php82-pdo_mysql \
    php82-pdo_pgsql \
    php82-simplexml \
    php82-tokenizer \
    php82-xmlwriter \
    php82-fileinfo \
    php82-sodium \
    php82-xsl

ADD etc/php.ini /etc/php82/conf.d/custom.ini
ADD etc/nginx.conf /etc/nginx/conf.d/default.conf

USER 65534
ADD --chown=65534:65534 . /var/www/html


FROM php AS composer

ARG GIT_COMMIT
ENV COMPOSER_CACHE_DIR=/tmp/
ENV APP_ENV=prod
ENV APP_DEBUG=0

# RUN curl -o composer.phar https://getcomposer.org/download/2.7.2/composer.phar
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

RUN set -xe \
    && echo "APP_BUILD_NUMBER=$GIT_COMMIT" >> .env.local \
    && composer install --no-dev --optimize-autoloader \
    && composer dump-autoload --no-dev --classmap-authoritative \
    && composer dump-env prod


FROM alpine/openssl AS cert

RUN mkdir /jwt \
    && openssl genrsa -out /jwt/private.pem 2048 \
    && openssl rsa -in /jwt/private.pem -pubout -out /jwt/public.pem


FROM php

COPY --from=composer --chown=65534:65534 /var/www/html/vendor /var/www/html/vendor
COPY --from=composer --chown=65534:65534 /var/www/html/.env.local.php /var/www/html/.env.local.php
COPY --from=composer --chown=65534:65534 /var/www/html/public/bundles /var/www/html/public/bundles
COPY --from=cert --chown=65534:65534 /jwt /var/www/html/config/jwt
