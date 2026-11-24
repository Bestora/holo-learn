# Build: docker compose build (context = repo root)

# pdo_sqlite, mbstring and opcache ship with the image; intl is required by composer.json,
# pcntl lets queue:work enforce job timeouts.
FROM dunglas/frankenphp:1-php8.4-trixie AS base
RUN install-php-extensions intl pcntl \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
WORKDIR /app
# storage/ is excluded from the build context, Laravel needs its skeleton.
RUN mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache

# The wayfinder Vite plugin runs `php artisan wayfinder:generate`, so the asset build
# needs PHP and vendor/ next to Node.
FROM base AS build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node:22-trixie-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-trixie-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/*
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY package.json package-lock.json .npmrc ./
RUN npm ci
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && npm run build \
    && rm -rf node_modules

FROM base
COPY --from=build /app /app
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/entrypoint
# Runs unprivileged; /db holds the SQLite file (volume), /data and /config are FrankenPHP/psysh state.
RUN mkdir /db && chown -R www-data:www-data /db /app/storage /app/bootstrap/cache /data /config
USER www-data
# Plain HTTP on :8080; the host's Caddy terminates TLS.
ENV SERVER_NAME=:8080 CADDY_GLOBAL_OPTIONS="auto_https off"
EXPOSE 8080
ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
