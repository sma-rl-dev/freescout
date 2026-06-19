#!/bin/bash
set -e

# FreeScout entrypoint - waits for DB, initializes env, runs migrations,
# creates admin user if needed, then starts Apache.
#
# Expected env vars:
#   DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
#   APP_URL (optional, defaults to http://localhost)
#   ADMIN_EMAIL, ADMIN_PASS (optional, for admin creation on first run)

APP_URL="${APP_URL:-http://host.docker.internal:8042}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@tester-env.local}"
ADMIN_PASS="${ADMIN_PASS:-TesterEnv123!}"
ADMIN_FIRST="${ADMIN_FIRST:-Admin}"
ADMIN_LAST="${ADMIN_LAST:-User}"

# Ensure writable directories exist (explicit paths, no brace expansion)
mkdir -p /var/www/html/storage/framework/cache
mkdir -p /var/www/html/storage/framework/sessions
mkdir -p /var/www/html/storage/framework/testing
mkdir -p /var/www/html/storage/framework/views
mkdir -p /var/www/html/storage/framework/cache/data
mkdir -p /var/www/html/storage/app
mkdir -p /var/www/html/storage/tmp
mkdir -p /var/www/html/storage/logs
mkdir -p /var/www/html/storage/debugbar
mkdir -p /var/www/html/bootstrap/cache
mkdir -p /var/www/html/public/css/builds
mkdir -p /var/www/html/public/js/builds
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/public/css/builds /var/www/html/public/js/builds 2>/dev/null || true
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/public/css/builds /var/www/html/public/js/builds 2>/dev/null || true

echo "==> Waiting for database at ${DB_HOST}:${DB_PORT}..."
until mariadb-admin ping -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USERNAME}" -p"${DB_PASSWORD}" --silent 2>/dev/null; do
    echo "   DB not ready, retrying in 2s..."
    sleep 2
done
echo "==> Database is ready."

# Initialize .env if not already present
if [ ! -f .env ]; then
    echo "==> Creating .env from .env.example..."
    cp .env.example .env

    # Set APP_URL
    sed -i "s|^APP_URL=.*|APP_URL=${APP_URL}|" .env

    # Set APP_TRUSTED_HOSTS so both localhost and host.docker.internal work
    # (host.docker.internal is needed for browser MCP access from other containers)
    sed -i "s|^#APP_TRUSTED_HOSTS=.*||" .env 2>/dev/null || true
    if ! grep -q "^APP_TRUSTED_HOSTS=" .env; then
        echo "APP_TRUSTED_HOSTS=localhost,host.docker.internal" >> .env
    else
        sed -i "s|^APP_TRUSTED_HOSTS=.*|APP_TRUSTED_HOSTS=localhost,host.docker.internal|" .env
    fi

    # Set DB config
    sed -i "s|^DB_CONNECTION=.*|DB_CONNECTION=mysql|" .env
    sed -i "s|^DB_HOST=.*|DB_HOST=${DB_HOST}|" .env
    sed -i "s|^DB_PORT=.*|DB_PORT=${DB_PORT}|" .env
    sed -i "s|^DB_DATABASE=.*|DB_DATABASE=${DB_DATABASE}|" .env
    sed -i "s|^DB_USERNAME=.*|DB_USERNAME=${DB_USERNAME}|" .env
    sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD}|" .env

    # Clear any stale config cache before key generation
    php artisan config:clear --no-interaction 2>/dev/null || true

    # Generate APP_KEY (use --show + sed for reliability)
    echo "==> Generating APP_KEY..."
    APP_KEY_VALUE=$(php artisan key:generate --show --no-interaction 2>/dev/null)
    if [ -n "$APP_KEY_VALUE" ]; then
        sed -i "s|^APP_KEY=.*|APP_KEY=${APP_KEY_VALUE}|" .env
        echo "   Key: ${APP_KEY_VALUE} written to .env"
    else
        # Fallback: try normal key:generate
        php artisan key:generate --force --no-interaction
    fi

    # Clear config cache so DB settings take effect
    php artisan config:clear --no-interaction 2>/dev/null || true

    # Run migrations
    echo "==> Running migrations..."
    php artisan migrate --force --no-interaction

    # Create storage symlink (public/storage -> storage/app/public)
    # First, fix storage/app/public if it became a broken self-referencing symlink
    # (Docker volume initialization can sometimes create this)
    STORAGE_PUBLIC="/var/www/html/storage/app/public"
    if [ -L "$STORAGE_PUBLIC" ] && [ ! -e "$STORAGE_PUBLIC" ]; then
        echo "==> Fixing broken storage/app/public symlink..."
        rm -f "$STORAGE_PUBLIC"
    fi
    if [ -L "$STORAGE_PUBLIC" ]; then
        echo "==> Removing unexpected storage/app/public symlink..."
        rm -f "$STORAGE_PUBLIC"
    fi
    mkdir -p "$STORAGE_PUBLIC"

    # Remove stale public/storage symlink (persists across resets on bind mount)
    rm -f /var/www/html/public/storage

    php artisan storage:link --no-interaction 2>/dev/null || true

    # Create admin user
    echo "==> Creating admin user (${ADMIN_EMAIL})..."
    php artisan freescout:create-user \
        --role=admin \
        --firstName="${ADMIN_FIRST}" \
        --lastName="${ADMIN_LAST}" \
        --email="${ADMIN_EMAIL}" \
        --password="${ADMIN_PASS}" \
        --no-interaction || echo "   (user may already exist, continuing)"

    # Clear cache
    php artisan freescout:clear-cache --no-interaction || true

    # Mark as installed (the web installer creates this; we do it manually)
    echo "Installed at $(date)" > /var/www/html/storage/.installed

    echo "==> FreeScout initialized."
else
    echo "==> .env already exists, updating settings..."
    # Fix APP_URL if it was previously localhost-based (needed for browser MCP access)
    sed -i "s|^APP_URL=.*|APP_URL=${APP_URL}|" .env
    # Ensure APP_TRUSTED_HOSTS is set (backward compat for older deploys)
    if ! grep -q "^APP_TRUSTED_HOSTS=" .env; then
        echo "APP_TRUSTED_HOSTS=localhost,host.docker.internal" >> .env
    else
        sed -i "s|^APP_TRUSTED_HOSTS=.*|APP_TRUSTED_HOSTS=localhost,host.docker.internal|" .env
    fi
    # Still clear caches to ensure consistency
    php artisan config:clear --no-interaction 2>/dev/null || true
    php artisan freescout:clear-cache --no-interaction 2>/dev/null || true

    # Ensure storage symlink exists (public/storage -> storage/app/public)
    STORAGE_PUBLIC="/var/www/html/storage/app/public"
    if [ -L "$STORAGE_PUBLIC" ] && [ ! -e "$STORAGE_PUBLIC" ]; then
        echo "==> Fixing broken storage/app/public symlink..."
        rm -f "$STORAGE_PUBLIC"
    fi
    if [ -L "$STORAGE_PUBLIC" ]; then
        echo "==> Removing unexpected storage/app/public symlink..."
        rm -f "$STORAGE_PUBLIC"
    fi
    mkdir -p "$STORAGE_PUBLIC"

    # Remove stale public/storage symlink (persists across resets on bind mount)
    rm -f /var/www/html/public/storage

    php artisan storage:link --no-interaction 2>/dev/null || true
fi

# Fix ownership: php artisan commands run as root and create cache files
# that Apache (www-data) cannot write to. Re-own everything before starting.
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/public/css/builds /var/www/html/public/js/builds 2>/dev/null || true

echo "==> Starting Apache..."
exec "$@"