#!/bin/bash
set -e

echo "Deployment started ..."

# Refuse to deploy dependencies with known security advisories. Checked on the incoming composer.lock before
# anything changes, so a failed audit leaves the running site as it was.
git fetch origin master
audit_dir=$(mktemp -d)
git show origin/master:composer.json > "$audit_dir/composer.json"
git show origin/master:composer.lock > "$audit_dir/composer.lock"
if ! composer audit --locked --no-dev --working-dir="$audit_dir"; then
    rm -rf "$audit_dir"
    echo "Deployment stopped: update the packages above (composer update <package>) and push again."
    exit 1
fi
rm -rf "$audit_dir"

# Enter maintenance mode or return true
# if already is in maintenance mode
(php artisan down) || true

# Pull the latest version of the app
git pull origin master

# Install composer dependencies
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Clear the old cache
php artisan clear-compiled

# Recreate cache
php artisan optimize

# Run database migrations
php artisan migrate --force

# Add any new settings and feature flags (existing values are never overwritten)
php artisan db:seed --class=SettingSeeder --force

# Exit maintenance mode
php artisan up

echo "Deployment finished!"
