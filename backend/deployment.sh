#!/usr/bin/env bash
#
# deployment.sh — run this after pulling backend code.
#
# A plain `git pull` does NOT regenerate the Composer autoloader, rebuild
# Laravel's caches, or reload the running queue workers (they keep the OLD code
# in memory). This script does all three.
#
# Skipping the autoloader regen is exactly what caused the
#   "SubscribeToKlaviyo ... Failed to open stream: No such file or directory"
# error after a listener class was deleted — the optimized classmap still
# pointed at the missing file.
#
# Usage (from the backend repo root):
#   ./deployment.sh
#   PULL=1 ./deployment.sh          # `git pull` first, then deploy
#   MAINTENANCE=1 ./deployment.sh   # take the app offline during the deploy
#   SKIP_SCRIBE=1 ./deployment.sh   # skip API-doc regeneration
#
set -euo pipefail
cd "$(dirname "$0")"

# Laravel's file cache / logs / compiled views live under storage/. Any artisan
# command run here as a different user than PHP-FPM creates root- (or deploy-
# user-) owned cache subdirectories that the web process can then no longer
# write to:
#   "Exception subscribing ... to Kit: file_put_contents(storage/framework/cache/data/..): Permission denied"
# Simplest durable setup: the web user owns the WHOLE app directory and every
# deploy runs as that user. This script writes to vendor/composer (autoloader),
# bootstrap/cache, storage/, .scribe/, resources/views/scribe and public/vendor/
# scribe, so a one-off `chown -R <web-user>:<web-group> .` (from the backend
# root) then `sudo -u <web-user> ./deployment.sh` keeps every file writable by
# both the deploy and the running app. Mixed ownership shows up as
# "Permission denied" / "Unable to delete file" from whichever side lost.
#
# To make that foolproof the script re-executes itself as the owner of storage/
# whenever it is started as someone else (e.g. root), so an accidental
# `sudo ./deployment.sh` still runs every command as the web user.
STORAGE_OWNER="$(stat -c '%U' storage 2>/dev/null || stat -f '%Su' storage)"
if [ "$STORAGE_OWNER" != "$(id -un)" ]; then
  if command -v sudo >/dev/null 2>&1; then
    printf '\033[1;33m  ! storage/ is owned by %s but you are %s — re-running as %s.\033[0m\n' "$STORAGE_OWNER" "$(id -un)" "$STORAGE_OWNER"
    exec sudo -u "$STORAGE_OWNER" env \
      PULL="${PULL:-0}" MAINTENANCE="${MAINTENANCE:-0}" SKIP_SCRIBE="${SKIP_SCRIBE:-0}" \
      COMPOSER_CMD="${COMPOSER_CMD:-}" \
      COMPOSER_HOME="${COMPOSER_HOME:-/tmp/composer-$STORAGE_OWNER}" \
      bash "$PWD/$(basename "$0")" "$@"
  fi
  printf '\033[1;31m  ! storage/ is owned by %s but you are %s and sudo is unavailable — files written by this deploy would be unwritable by PHP-FPM (and vice versa).\033[0m\n' "$STORAGE_OWNER" "$(id -un)"
  printf '\033[1;31m    Fix once: chown -R %s:%s . (backend root), then deploy as that user: sudo -u %s ./deployment.sh\033[0m\n' "$STORAGE_OWNER" "$STORAGE_OWNER" "$STORAGE_OWNER"
  exit 1
fi

# ---- config (override via env vars) ---------------------------------------
PULL="${PULL:-0}"
MAINTENANCE="${MAINTENANCE:-0}"
SKIP_SCRIBE="${SKIP_SCRIBE:-0}"
# Regenerates the optimized autoloader (drops deleted/renamed classes from the
# classmap). If this pull changed composer.json/lock, use `composer install
# --optimize-autoloader` instead — dump-autoload alone won't install new deps.
COMPOSER_CMD="${COMPOSER_CMD:-}"
COMPOSER_CMD="${COMPOSER_CMD:-composer dump-autoload -o}"

say()  { printf '\n\033[1;36m▶ %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m  ! %s\033[0m\n' "$*"; }

# Always try to lift maintenance mode, even if a step below fails.
finish() { [ "$MAINTENANCE" = "1" ] && php artisan up >/dev/null 2>&1 || true; }
trap finish EXIT

# ---- 0. optional git pull -------------------------------------------------
if [ "$PULL" = "1" ]; then
  say "git pull"
  git pull --ff-only
fi

# ---- 1. maintenance mode (optional) ---------------------------------------
if [ "$MAINTENANCE" = "1" ]; then
  say "Maintenance mode ON"
  php artisan down --retry=15 || true
fi

# ---- 2. regenerate the autoloader (fixes stale/deleted classes) -----------
say "$COMPOSER_CMD"
$COMPOSER_CMD

# ---- 3. clear ALL stale caches: config, route, event, view, compiled, cache
say "artisan optimize:clear (config / route / event / view / cache)"
php artisan optimize:clear

# ---- 4. migrations --------------------------------------------------------
say "migrate"
php artisan migrate 

# ---- 5. plan prices from Stripe --------------------------------------------
# Stripe is the source of truth for what a plan costs; a price changed in the
# dashboard reaches /api/plans and Billing here (and daily via the scheduler).
say "plans:sync-stripe-prices"
php artisan plans:sync-stripe-prices || warn "plans:sync-stripe-prices failed — plan prices may be stale"


# ---- 6. rebuild caches ----------------------------------------------------
# Deliberately cache ONLY config. Events + routes stay on runtime discovery so a
# future listener/route change can't leave a stale cache pointing at a deleted
# class — the exact failure this script exists to prevent. Uncomment the extras
# for a little more boot speed (optimize:clear above already re-clears them).
say "config:cache"
php artisan config:cache
# php artisan route:cache
# php artisan event:cache

# ---- 7. API docs (agent-ready surface) ------------------------------------
if [ "$SKIP_SCRIBE" != "1" ]; then
  say "scribe:generate"
  php artisan scribe:generate || warn "scribe:generate failed — continuing"
fi

# ---- 8. tell the running queue workers to reload the new code -------------
# queue:restart signals the daemons to exit gracefully; your process manager
# (supervisor/systemd) brings them back on the new code. If you don't run one,
# restart the workers yourself here.
say "queue:restart"
php artisan queue:restart

# ---- 9. done (trap lifts maintenance mode) --------------------------------
say "Deploy complete ✅"
