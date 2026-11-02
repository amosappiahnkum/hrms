#!/bin/sh

set -e

# Wait until MySQL is ready
#until php -r "
#try {
#    \$pdo = new PDO(
#        'mysql:host=${DB_HOST};port=${DB_PORT:-3306};dbname=${DB_DATABASE}',
#        '${DB_USERNAME}',
#        '${DB_PASSWORD}',
#        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
#    );
#    echo \"DB ready\n\";
#    exit(0);
#} catch (Exception \$e) {
#    fwrite(STDERR, \"Waiting for DB... (\" . \$e->getMessage() . \")\n\");
#    exit(1);
#}
#"; do
#    sleep 3
#done

#php artisan migrate --force

#php artisan app:setup

# Started as root (the app container): storage/ belongs to www-data, which php-fpm, the queue, the scheduler and
# Reverb all run as. Group-writable so a deploy user in the www-data group can write logs and caches too.
if [ "$(id -u)" = "0" ] && [ -d /var/www/storage ]; then
    find /var/www/storage \( ! -user www-data -o ! -group www-data \) -exec chown www-data:www-data {} +
    chmod -R ug+rwX /var/www/storage
fi

exec "$@"
#exec php-fpm
