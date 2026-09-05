#!/usr/bin/env sh
set -eu

role="${CONTAINER_ROLE:-app}"

echo "[entrypoint] role=${role}"

run_as_www() {
    runuser -u www-data -- "$@"
}

# Ensure writable runtime directories exist (named volumes may start empty)
mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    storage/app/public \
    bootstrap/cache

# Refresh public tree when a volume overlays /var/www/html/public
if [ -d /opt/public-seed ] && [ -d public ]; then
    echo "[entrypoint] syncing public assets"
    rsync -a --delete --exclude='storage' /opt/public-seed/ public/
fi

chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache

# Wait for database when configured
if [ -n "${DB_HOST:-}" ]; then
    echo "[entrypoint] waiting for database ${DB_HOST}:${DB_PORT:-3306}..."
    i=0
    until php -r "
        \$driver = getenv('DB_CONNECTION') === 'pgsql' ? 'pgsql' : 'mysql';
        \$port = getenv('DB_PORT') ?: (\$driver === 'pgsql' ? '5432' : '3306');
        try {
            new PDO(
                sprintf('%s:host=%s;port=%s;dbname=%s', \$driver, getenv('DB_HOST'), \$port, getenv('DB_DATABASE')),
                getenv('DB_USERNAME'),
                getenv('DB_PASSWORD')
            );
            exit(0);
        } catch (Throwable \$e) {
            exit(1);
        }
    "; do
        i=$((i + 1))
        if [ "$i" -ge 60 ]; then
            echo "[entrypoint] database not ready after 60s" >&2
            exit 1
        fi
        sleep 1
    done
    echo "[entrypoint] database is ready"
fi

if [ "${RUN_MIGRATIONS:-false}" = "true" ] || [ "${role}" = "migrate" ]; then
    echo "[entrypoint] running migrations"
    run_as_www php artisan migrate --force
fi

if [ "${RUN_OPTIMIZE:-true}" = "true" ] && [ "${role}" != "migrate" ]; then
    run_as_www php artisan config:cache
    run_as_www php artisan route:cache
    run_as_www php artisan view:cache
    run_as_www php artisan event:cache 2>/dev/null || true
fi

if [ ! -L public/storage ]; then
    run_as_www php artisan storage:link 2>/dev/null || true
fi

case "${role}" in
    app)
        exec "$@"
        ;;
    queue)
        echo "[entrypoint] starting queue worker"
        exec runuser -u www-data -- php artisan queue:work \
            --sleep="${QUEUE_SLEEP:-3}" \
            --tries="${QUEUE_TRIES:-3}" \
            --timeout="${QUEUE_TIMEOUT:-90}" \
            --max-time="${QUEUE_MAX_TIME:-3600}" \
            --memory="${QUEUE_MEMORY:-256}"
        ;;
    scheduler)
        echo "[entrypoint] starting scheduler loop"
        while true; do
            runuser -u www-data -- php artisan schedule:run --verbose --no-interaction || true
            sleep 60
        done
        ;;
    migrate)
        echo "[entrypoint] migrate role complete"
        exit 0
        ;;
    *)
        echo "[entrypoint] unknown CONTAINER_ROLE=${role}" >&2
        exit 1
        ;;
esac
