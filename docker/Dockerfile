# Development / test image for ingot-forms.
# All quality gates (composer, phpunit, phpstan, php-cs-fixer) run inside this image — see Makefile.
FROM php:8.4-cli-alpine

# pdo_pgsql for the application database, pcov for fast code coverage in tests.
# gd is what turns an image in a PDF record into a picture rather than a line
# naming the file: dompdf hands a PNG with an alpha channel — which is what a
# browser's canvas produces, so every drawn signature — to GD to split. It is
# **optional**, and the record says so by naming the file instead when it is
# missing; this image has it so that the default is the good one.
RUN apk add --no-cache postgresql-dev libpng-dev libjpeg-turbo-dev freetype-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install pdo_pgsql gd \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del .build-deps

# Chromium and its driver: the browser tests drive a real one, because what a
# page does in a browser is the only thing that proves the page works. Headless,
# and only ever started by Panther during those tests.
# zip is what the webdriver client needs; the browser is what the tests drive.
RUN apk add --no-cache chromium chromium-chromedriver libzip-dev \
    && docker-php-ext-install zip

ENV PANTHER_NO_SANDBOX=1 \
    PANTHER_CHROME_BINARY=/usr/bin/chromium-browser \
    PANTHER_CHROMEDRIVER_BINARY=/usr/bin/chromedriver

# Uploads need more than the 2M default, and the limit belongs to the image
# rather than to whoever remembers to set it.
COPY php.ini /usr/local/etc/php/conf.d/ingot-forms.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_CACHE_DIR=/app/.cache/composer
WORKDIR /app
