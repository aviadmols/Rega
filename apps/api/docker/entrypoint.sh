#!/bin/sh
# Starts the container in the role Railway asks for.
#   APP_ROLE=web        Octane on FrankenPHP, listens on $PORT
#   APP_ROLE=worker     queue worker ($QUEUE_NAMES, default "default")
#   APP_ROLE=scheduler  Laravel scheduler
set -eu

cd /app

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

role="${APP_ROLE:-web}"

case "$role" in
  web)
    # Only the web service migrates, and only when told to, so three services starting at
    # once never race on the same migration.
    if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
      php artisan migrate --force
    fi
    exec php artisan octane:frankenphp \
      --host=0.0.0.0 \
      --port="${PORT:-8080}" \
      --max-requests="${OCTANE_MAX_REQUESTS:-500}"
    ;;
  worker)
    exec php artisan queue:work \
      --queue="${QUEUE_NAMES:-default}" \
      --sleep=1 \
      --tries=3 \
      --max-time=3600
    ;;
  scheduler)
    exec php artisan schedule:work
    ;;
  *)
    echo "Unknown APP_ROLE: $role (expected web, worker or scheduler)" >&2
    exit 1
    ;;
esac
