#!/bin/sh
set -e

export COMPOSER_MEMORY_LIMIT=-1

if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

if [ ! -f .env ]; then
    cp .env.example .env
fi

# Generate the key only once: regenerating it on every start would log everyone
# out and make previously encrypted data unreadable.
if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force
fi
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache || true

exec "$@"
