#!/bin/bash
# Production deploy, run on the app server from the project folder (make deploy, or CI over ssh).
# Everything PHP runs in the app's own image with .env.production: the host needs git and Docker, not PHP.
# Started with sh (CI, older docs): re-run with bash
[ -n "${BASH_VERSION:-}" ] || exec bash "$0" "$@"
set -euo pipefail
cd "$(dirname "$0")/.."

ENV_FILE=.env.production
BRANCH="${DEPLOY_BRANCH:-master}"
COMPOSE="docker compose --env-file $ENV_FILE -f docker-compose.prod.yml"
IMAGE=hrms_app

# artisan in the running app container, as www-data like php-fpm and the queue
# (stdin closed: over ssh, docker exec would otherwise read the rest of the session)
artisan() { $COMPOSE exec -T -u www-data app php artisan "$@" < /dev/null; }
# composer in a one-off container of the app image (same PHP and extensions), writing the code as its owner
composer() { docker run --rm -u "$(stat -c '%u:%g' .)" -e COMPOSER_HOME=/tmp/composer -v "$PWD":/var/www -w /var/www --entrypoint composer "$IMAGE" "$@"; }

echo "Deployment started ..."

sh ./.scripts/check-prod-env.sh "$ENV_FILE"

if ! $COMPOSE ps --status running --services | grep -qx app; then
    echo "The production stack isn't running: start it with make build-prod first."
    exit 1
fi

# Refuse to deploy dependencies with known security advisories. Checked on the incoming composer.lock before
# anything changes, so a failed audit leaves the running site as it was.
git fetch origin "$BRANCH"
audit_dir=$(mktemp -d)
trap 'rm -rf "$audit_dir"' EXIT
git show "origin/$BRANCH:composer.json" > "$audit_dir/composer.json"
git show "origin/$BRANCH:composer.lock" > "$audit_dir/composer.lock"
if ! docker run --rm -v "$audit_dir":/app -w /app --entrypoint composer "$IMAGE" audit --locked --no-dev; then
    echo "Deployment stopped: update the packages above (composer update <package>) and push again."
    exit 1
fi

# Enter maintenance mode or return true if already is in maintenance mode
artisan down || true

# Pull the latest version of the app
git pull origin "$BRANCH"

# Install composer dependencies; the package discovery it would run goes through artisan below
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

# Files written since the containers started (by root or the code's owner) go back to www-data
$COMPOSE exec -T app chown -R www-data:www-data storage bootstrap/cache < /dev/null

# Rebuild the caches, inside the container so they hold its paths (/var/www) and .env.production's values
artisan clear-compiled
artisan package:discover
artisan optimize

# Run database migrations
artisan migrate --force

# Add any new settings and feature flags (existing values are never overwritten)
artisan db:seed --class=SettingSeeder --force

# Long-running workers pick up the new code and config
artisan queue:restart
artisan reverb:restart

# Exit maintenance mode
artisan up

echo "Deployment finished!"
