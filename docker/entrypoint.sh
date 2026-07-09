#!/usr/bin/env bash
set -e

cd /var/www/html

if [ ! -d vendor ]; then
    echo "[entrypoint] vendor/ missing, running composer install..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

if [ ! -f .env ]; then
    echo "[entrypoint] .env missing, copying from .env.example..."
    cp .env.example .env
fi

if ! grep -q "^APP_KEY=base64" .env; then
    echo "[entrypoint] Generating APP_KEY..."
    php artisan key:generate --force
fi

echo "[entrypoint] Waiting for database..."
until php artisan db:show >/dev/null 2>&1; do
    sleep 2
done

# Migrations are idempotent (Laravel tracks applied migrations in its own
# table), so running this on every container start is safe even when
# app/queue/scheduler all boot at the same time - only one will do actual work.
echo "[entrypoint] Running migrations..."
php artisan migrate --force --isolated

php artisan storage:link >/dev/null 2>&1 || true

echo "[entrypoint] Starting: $*"
exec "$@"
