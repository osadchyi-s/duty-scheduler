#!/bin/sh
# Production/staging entrypoint of the app and worker containers.
# The code is part of the image; settings come from the environment (env_file).
set -e

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY is not set. Generate one with: php artisan key:generate --show" >&2
    exit 1
fi

mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs bootstrap/cache

# Caches depend on the environment variables, so they are built at start, not at build time.
php artisan optimize
php artisan filament:optimize

exec "$@"
