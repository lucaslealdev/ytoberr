#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# Discard any config/route/view cache left over from a previous boot of this same
# container so the fresh environment below is what actually gets cached again later.
php artisan optimize:clear >/dev/null 2>&1 || true

echo "[entrypoint] Ensuring runtime directories exist..."
mkdir -p \
    bin \
    database \
    storage/app/public/channels \
    storage/app/public/downloads \
    storage/app/private \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# Recreate the public disk symlink in case /var/www/html/public was replaced by a volume mount.
ln -sfn /var/www/html/storage/app/public /var/www/html/public/storage

if [ ! -x bin/yt-dlp ] || [ ! -x bin/ffmpeg ] || [ ! -x bin/ffprobe ]; then
    echo "[entrypoint] yt-dlp/ffmpeg/ffprobe missing from bin/, downloading via 'make setup-bins'..."
    make setup-bins
fi

touch .env

# Laravel's dotenv loader never overrides an already-set OS environment variable
# (even an empty one), so the key must be exported here rather than left for
# `php artisan key:generate` to write into .env alone. A copy is still kept in
# .env so the same key survives a `docker restart` of this same container.
if [ -z "${APP_KEY:-}" ]; then
    existing_key="$(grep '^APP_KEY=' .env 2>/dev/null | head -n1 | cut -d= -f2- || true)"
    if [ -n "$existing_key" ]; then
        export APP_KEY="$existing_key"
    else
        echo "[entrypoint] No APP_KEY configured, generating one..."
        generated_key="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
        if grep -q '^APP_KEY=' .env 2>/dev/null; then
            sed -i "s#^APP_KEY=.*#APP_KEY=${generated_key}#" .env
        else
            echo "APP_KEY=${generated_key}" >> .env
        fi
        export APP_KEY="$generated_key"
    fi
fi

if [ "${DB_CONNECTION:-sqlite}" = "sqlite" ]; then
    touch "${DB_DATABASE:-database/database.sqlite}"
fi

echo "[entrypoint] Running database migrations..."
php artisan migrate --force

echo "[entrypoint] Caching configuration, routes and views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# busybox crond spawns jobs without reliably forwarding the container's environment,
# so snapshot it here and have the crontab source it before running artisan.
: > /etc/container-env.sh
for name in $(compgen -e); do
    printf 'export %q=%q\n' "$name" "${!name}" >> /etc/container-env.sh
done
chmod 600 /etc/container-env.sh

echo "[entrypoint] Boot checks complete, starting services..."
exec "$@"
