# Docker image for the MEENT Webhook

ARG PHP_VERSION=8.2

# ==============================================================================
# Install PHP Dependencies
# ------------------------------------------------------------------------------
FROM php:${PHP_VERSION}-alpine AS builder
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer
COPY composer.json /app/composer.json
RUN composer install --prefer-dist --working-dir=/app
# ==============================================================================


# ==============================================================================
# Main machine
# ------------------------------------------------------------------------------
FROM php:${PHP_VERSION}-apache
SHELL ["/bin/bash", "-o", "pipefail", "-c"]

RUN export DEBIAN_FRONTEND=noninteractive \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        libonig-dev \
        pkg-config \
        ssl-cert \
        unzip \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install \
        bcmath \
        mbstring

RUN a2enmod headers rewrite ssl

COPY ./README.md /app/README.md
COPY ./docker/site.conf /etc/apache2/sites-enabled/000-default.conf
COPY ./docs /app/docs
COPY ./src /app/src/
COPY ./web /app/web/
COPY --from=builder /app/vendor /app/vendor

WORKDIR /app

EXPOSE 443
# ==============================================================================
