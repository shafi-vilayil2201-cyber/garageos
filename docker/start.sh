#!/bin/sh
set -e

# Apache must listen on the port the platform assigns.
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf

# Same idempotent migration step deploy.sh runs on a VM — applies only
# what's pending, so every deploy brings the database schema up to date.
php /var/www/garageos/database/migrate.php

exec apache2-foreground
