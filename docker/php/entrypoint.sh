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
exec "$@"
#exec php-fpm
