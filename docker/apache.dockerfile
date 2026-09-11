FROM php:8.2-apache

RUN a2enmod rewrite headers && \
    echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf && \
    a2enconf servername

# Extensions + Composer
RUN apt-get update && \
    apt-get install -y --no-install-recommends zip unzip libzip-dev git curl libpq-dev && \
    rm -rf /var/lib/apt/lists/* && \
    docker-php-ext-install zip pdo_mysql

# Install Composer globally
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Photo uploads for /v1/generation/create — default 2M/8M is too small for
# the "PNG/JPG до 20 МБ" the frontend promises.
RUN { \
        echo "upload_max_filesize = 25M"; \
        echo "post_max_size = 30M"; \
    } > /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html
