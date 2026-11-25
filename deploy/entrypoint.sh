#!/bin/sh
set -eu

PORT="${PORT:-10000}"

# Render's persistent disk should be mounted at /var/data.
mkdir -p /var/data/data
if [ -d /var/www/html/data ] && [ ! -L /var/www/html/data ]; then
    cp -a /var/www/html/data/. /var/data/data/ 2>/dev/null || true
    rm -rf /var/www/html/data
fi
ln -sfn /var/data/data /var/www/html/data

# The app encrypts stored credentials with includes/secret.php. Keep that key
# on the persistent disk alongside the SQLite database.
if [ ! -s /var/data/secret.php ]; then
    SECRET="$(openssl rand -hex 32)"
    printf "<?php\\nreturn '%s';\\n" "$SECRET" > /var/data/secret.php
fi
chmod 600 /var/data/secret.php
ln -sfn /var/data/secret.php /var/www/html/includes/secret.php

chown -R www-data:www-data /var/data /var/www/html

# Render assigns the listening port through PORT.
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
