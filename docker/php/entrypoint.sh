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

# Started as root (the app container): storage/ and bootstrap/cache belong to www-data, which php-fpm, the queue,
# the scheduler, Reverb and the deploy's artisan commands all run as.
if [ "$(id -u)" = "0" ]; then
    for dir in /var/www/storage /var/www/bootstrap/cache; do
        [ -d "$dir" ] || continue
        find "$dir" \( ! -user www-data -o ! -group www-data \) -exec chown www-data:www-data {} +
        chmod -R ug+rwX "$dir"
    done
fi

exec "$@"
#exec php-fpm
