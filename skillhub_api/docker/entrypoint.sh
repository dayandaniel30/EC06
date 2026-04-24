#!/usr/bin/env sh
set -e

DB_HOST="${DB_HOST:-mysql}"
DB_PORT="${DB_PORT:-3306}"

echo "[entrypoint] Waiting for MySQL at ${DB_HOST}:${DB_PORT}..."
i=0
until nc -z "$DB_HOST" "$DB_PORT" 2>/dev/null; do
  i=$((i + 1))
  if [ "$i" -gt 60 ]; then
    echo "[entrypoint] ERROR: MySQL did not become reachable after 120s" >&2
    exit 1
  fi
  sleep 2
done
echo "[entrypoint] MySQL port is open."

echo "[entrypoint] Running migrations..."
php artisan migrate --force || echo "[entrypoint] WARN: migrations failed or already applied"

echo "[entrypoint] Clearing caches..."
php artisan config:clear || true
php artisan route:clear || true

echo "[entrypoint] Starting: $*"
exec "$@"
