FROM php:8.2-fpm

# Extensions + Composer — same as docker/apache.dockerfile
RUN apt-get update && \
    apt-get install -y --no-install-recommends zip unzip libzip-dev git curl libpq-dev && \
    rm -rf /var/lib/apt/lists/* && \
    docker-php-ext-install zip pdo_mysql

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Photo uploads for /v1/generation/create — default 2M/8M is too small for
# the "PNG/JPG до 20 МБ" the frontend promises. nginx needs its own
# client_max_body_size raised to match (see docker/nginx.conf) — PHP
# accepting a bigger body is not enough on its own.
RUN { \
        echo "upload_max_filesize = 25M"; \
        echo "post_max_size = 30M"; \
    } > /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html
