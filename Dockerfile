ARG PHP_VERSION=8.0
FROM php:${PHP_VERSION}-zts

RUN apt update -qq && \
	apt install -y --no-install-recommends \
    build-essential gdb git unzip

RUN docker-php-ext-install opcache \
 && docker-php-ext-enable opcache

RUN git clone -b florian/fix-316 https://github.com/krakjoe/parallel.git /tmp/parallel \
 && (cd /tmp/parallel && phpize && ./configure && make && make install) \
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
