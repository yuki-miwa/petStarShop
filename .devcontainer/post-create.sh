#!/usr/bin/env bash
set -euo pipefail

echo "[post-create] Ensure composer deps"
if [ -f composer.json ]; then
  composer install --no-interaction || true
fi

if [ ! -f .env ]; then
  cp .env.example .env
fi

php artisan key:generate --force || true

echo "[post-create] Waiting for Postgres..."
until php -r 'pg_connect("host=postgres port=5432 dbname=petstar_dev user=petstar password=petstar") or exit(1);'; do
  sleep 1
done
echo "Postgres up."

php artisan migrate || true
echo "[post-create] Done."