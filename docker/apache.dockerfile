FROM php:8.2-apache

RUN a2enmod rewrite && \
    echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf && \
    a2enconf servername

# Extensions + Composer
RUN apt-get update && \
    apt-get install -y --no-install-recommends zip unzip libzip-dev git curl libpq-dev && \
    rm -rf /var/lib/apt/lists/* && \
    docker-php-ext-install zip pdo_mysql

# Install Composer globally
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

RUN chmod 777 /var/www/html/logs
