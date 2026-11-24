#!/bin/sh
set -e

# storage/ is a volume; recreate Laravel's skeleton in case it starts out empty.
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
    storage/framework/views storage/logs

# Only the web server (the image's default command) prepares the database and caches;
# queue, scheduler and one-off `docker compose run` commands start directly.
if [ "$1" = "frankenphp" ]; then
    # Laravel asks interactively before creating a missing SQLite file.
    [ "${DB_CONNECTION:-sqlite}" = "sqlite" ] && [ -n "$DB_DATABASE" ] && touch "$DB_DATABASE"
    php artisan migrate --force
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

exec "$@"
