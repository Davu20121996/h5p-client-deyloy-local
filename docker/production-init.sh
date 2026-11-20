#!/bin/sh
set -eu
cd /var/www/app
mkdir -p storage/app/public storage/app/private storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage
php artisan migrate --force --no-interaction
php artisan db:seed --class=RoleSeeder --force --no-interaction
php artisan lti:rotate-key --if-missing --no-interaction
php /usr/local/bin/bootstrap-admin.php
echo 'Production initialization completed.'
