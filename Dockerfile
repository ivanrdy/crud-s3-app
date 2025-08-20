# Base: Apache + PHP 8.2
FROM php:8.2-apache

# Install extensions for MySQL/Postgres + zip
RUN apt-get update      && apt-get install -y --no-install-recommends libpq-dev libzip-dev unzip git      && docker-php-ext-install pdo_mysql pdo_pgsql zip      && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apache listen on 8080 (non-root, OpenShift-friendly)
RUN sed -ri 's!Listen 80!Listen 8080!g' /etc/apache2/ports.conf      && sed -ri 's!<VirtualHost \*:80>!<VirtualHost *:8080>!g' /etc/apache2/sites-available/000-default.conf

# Reasonable upload sizes
RUN printf "file_uploads=On\nupload_max_filesize=50M\npost_max_size=55M\n" > /usr/local/etc/php/conf.d/uploads.ini

# App deps first (better layer caching)
WORKDIR /var/www/html
COPY composer.json ./
RUN composer install --no-dev --prefer-dist --no-interaction

# App code
COPY index.php ./

# OpenShift: arbitrary UID support (group 0 writable)
RUN chown -R root:0 /var/www/html /var/run/apache2 /var/lock/apache2 /var/log/apache2      && chmod -R g+rwX /var/www/html /var/run/apache2 /var/lock/apache2 /var/log/apache2

EXPOSE 8080
CMD ["apache2-foreground"]
