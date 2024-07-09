ARG PHP_VERSION=8.0
FROM php:${PHP_VERSION}-zts

RUN apt update -qq && \
	apt install git unzip gdb -y --no-install-recommends

RUN docker-php-ext-install opcache \
 && docker-php-ext-enable opcache

RUN pecl install -o -f parallel-1.2.2 \
 && docker-php-ext-enable parallel

COPY --from=composer/composer:latest-bin /composer /usr/bin/composer

COPY php.ini        /usr/local/etc/php/php.ini
COPY src            /segfault/src
COPY tests          /segfault/tests
COPY composer.json  /segfault
COPY phpunit.xml    /segfault

WORKDIR /segfault

RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction

CMD ["gdb", "--args", "php", "vendor/bin/phpunit"]
