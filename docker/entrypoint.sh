#!/bin/bash
set -e

echo "[paypal-txwatch] Copying assets to nginx volume..."
cp -a /var/www/html/public/. /public-export/

echo "[paypal-txwatch] Waiting for database..."
until php artisan db:show >/dev/null 2>&1; do
    sleep 2
done

# Migrations are idempotent (Laravel tracks applied migrations in its own
# table) and --isolated takes a cache lock, so this is safe even though
# app/queue/scheduler could in theory race on first boot.
echo "[paypal-txwatch] Running migrations..."
php artisan migrate --force --isolated

echo "[paypal-txwatch] Seeding roles/permissions (non-fatal if already present)..."
php artisan db:seed --class=RolesAndPermissionsSeeder --force || echo "[paypal-txwatch] Roles already seeded"

php artisan storage:link >/dev/null 2>&1 || true

echo "[paypal-txwatch] Clearing stale caches (storage/ is a persistent volume and can still hold compiled config/routes/views from a previous image)..."
php artisan view:clear
php artisan config:clear
php artisan route:clear

echo "[paypal-txwatch] Warming caches..."
php artisan config:cache
php artisan route:cache
# Filament's documented production optimizations: precompile all Blade views,
# cache the icon manifest (icon discovery scans thousands of SVGs otherwise)
# and Filament's component manifest. Together with opcache.validate_timestamps=0
# (see docker/php.ini) this is where most of the panel's render time goes.
php artisan view:cache
php artisan icons:cache
php artisan filament:cache-components

echo "[paypal-txwatch] Handing off to PHP-FPM..."
exec php-fpm
