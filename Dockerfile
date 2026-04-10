FROM php:8.2-apache

WORKDIR /var/www/html

RUN a2dismod mpm_event || true
RUN a2enmod mpm_prefork

COPY . /var/www/html

EXPOSE 80
