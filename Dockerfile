FROM php:8.5.12-fpm-alpine3.24 AS base-platform

# Set timezone
ENV TZ=UTC
RUN apk add --update --no-cache tzdata
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ >/etc/timezone
RUN printf '[PHP]\ndate.timezone = "%s"\n' $TZ >/usr/local/etc/php/conf.d/tzone.ini

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN install-php-extensions intl
RUN install-php-extensions pdo_pgsql
RUN install-php-extensions opcache
RUN install-php-extensions amqp
RUN install-php-extensions redis

ENV LOG_STREAM="php://stdout"

# Disabling fpm access logs since we already have them with nginx
RUN sed -i '/access.log/s/^/; /' /usr/local/etc/php-fpm.d/docker.conf

WORKDIR /app

## ==============================================
FROM base-platform AS dev
## ==============================================

RUN install-php-extensions xdebug-3.5.3

ENV APP_ENV=dev
COPY docker/php/php.dev.ini $PHP_INI_DIR/conf.d/31-php.ini
