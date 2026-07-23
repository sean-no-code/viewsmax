#!/usr/bin/env bash
# First-run setup for the backend container.
#
# The compose file bind-mounts the project directory over /var/www/html so code
# edits are live. That mount hides whatever the image built at that path, and
# vendor/ is gitignored — so on a fresh clone there is no autoloader unless we
# install one here.
set -euo pipefail

cd /var/www/html

if [ ! -f vendor/autoload.php ]; then
  echo "==> Installing PHP dependencies (first run, this takes a minute)..."
  composer install --no-interaction --prefer-dist --no-progress
fi

if [ ! -f .env ]; then
  echo "==> Creating .env from .env.example"
  cp .env.example .env

  # Point .env at the compose services. Writing these into the file rather
  # than relying on the container environment matters: `artisan serve` does
  # not pass the shell environment through to its request workers, so a web
  # request would otherwise fall back to .env's defaults (sqlite) even though
  # the CLI correctly sees pgsql.
  set_env() {
    if grep -qE "^${1}=" .env; then
      sed -i "s|^${1}=.*|${1}=${2}|" .env
    else
      printf '%s=%s\n' "$1" "$2" >> .env
    fi
  }

  set_env APP_URL          "http://localhost:8000"
  set_env DB_CONNECTION    "${DB_CONNECTION:-pgsql}"
  set_env DB_HOST          "${DB_HOST:-postgres}"
  set_env DB_PORT          "${DB_PORT:-5432}"
  set_env DB_DATABASE      "${DB_DATABASE:-viewsmax}"
  set_env DB_USERNAME      "${DB_USERNAME:-postgres}"
  set_env DB_PASSWORD      "${DB_PASSWORD:-postgres}"
  set_env DB_OUTLIERS_DRIVER   "${DB_OUTLIERS_DRIVER:-pgsql}"
  set_env DB_OUTLIERS_HOST     "${DB_OUTLIERS_HOST:-postgres}"
  set_env DB_OUTLIERS_PORT     "${DB_OUTLIERS_PORT:-5432}"
  set_env DB_OUTLIERS_DATABASE "${DB_OUTLIERS_DATABASE:-viewsmax_search}"
  set_env DB_OUTLIERS_USERNAME "${DB_OUTLIERS_USERNAME:-postgres}"
  set_env DB_OUTLIERS_PASSWORD "${DB_OUTLIERS_PASSWORD:-postgres}"
  set_env QUEUE_CONNECTION "${QUEUE_CONNECTION:-database}"
  set_env CORS_ALLOWED_ORIGINS "${CORS_ALLOWED_ORIGINS:-http://localhost:8080}"
fi

if ! grep -qE '^APP_KEY=.+' .env; then
  echo "==> Generating application key"
  php artisan key:generate --force
fi

echo "==> Waiting for postgres..."
until php -r 'exit(@fsockopen(getenv("DB_HOST") ?: "postgres", (int)(getenv("DB_PORT") ?: 5432)) ? 0 : 1);'; do
  sleep 1
done

echo "==> Running migrations"
php artisan migrate --force

# Passport signs OAuth tokens with an RSA keypair kept in storage/. Those files
# are gitignored (they are secrets), so a fresh clone has none and anything
# touching OAuth or the MCP server fails with "Invalid key supplied".
if [ ! -f storage/oauth-private.key ]; then
  echo "==> Generating Passport keys"
  php artisan passport:keys --force
fi

# Seed only once. The marker lives on the storage volume, so re-creating the
# container does not re-seed, but wiping volumes gives you a clean start.
if [ ! -f storage/.seeded ]; then
  echo "==> Seeding database"
  php artisan db:seed --force && touch storage/.seeded
fi

php artisan storage:link 2>/dev/null || true

echo "==> Ready."
exec "$@"
