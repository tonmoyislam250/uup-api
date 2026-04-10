#!/usr/bin/env bash
set -e

# Ensure Apache runs with a single MPM (prefork) to avoid AH00534.
rm -f /etc/apache2/mods-enabled/mpm_event.load \
      /etc/apache2/mods-enabled/mpm_event.conf \
      /etc/apache2/mods-enabled/mpm_worker.load \
      /etc/apache2/mods-enabled/mpm_worker.conf
ln -sf ../mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load
ln -sf ../mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf

mkdir -p /var/www/html/cache \
         /var/www/html/fileinfo/full \
         /var/www/html/fileinfo/metadata \
         /var/www/html/packs \
         /var/www/html/uuptmp \
         /var/www/html/tmp
chown -R www-data:www-data \
         /var/www/html/cache \
         /var/www/html/fileinfo \
         /var/www/html/packs \
         /var/www/html/uuptmp \
         /var/www/html/tmp

exec apache2-foreground
