FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libzip-dev \
    libxml2-dev \
    p7zip-full \
    unzip \
    ca-certificates \
    && docker-php-ext-install zip \
    && (a2dismod mpm_event mpm_worker || true) \
    && a2enmod mpm_prefork \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . /var/www/html

RUN mkdir -p /var/www/html/cache \
    /var/www/html/fileinfo/full \
    /var/www/html/fileinfo/metadata \
    /var/www/html/packs \
    /var/www/html/uuptmp \
    /var/www/html/tmp \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80
