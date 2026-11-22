#!/bin/sh
set -e

cd /var/www/html

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:" ]; then
  echo "Generating APP_KEY..."
  php artisan key:generate --force --show >/tmp/app_key.txt
  export APP_KEY="$(cat /tmp/app_key.txt)"
  rm -f /tmp/app_key.txt
fi

echo "Waiting for database..."
i=0
until php -r "
try {
  new PDO(
    'pgsql:host=' . getenv('DB_HOST') . ';port=' . (getenv('DB_PORT') ?: '5432') . ';dbname=' . getenv('DB_DATABASE'),
    getenv('DB_USERNAME'),
    getenv('DB_PASSWORD')
  );
  exit(0);
} catch (Throwable \$e) {
  exit(1);
}
" 2>/dev/null; do
  i=$((i + 1))
  if [ "$i" -ge 60 ]; then
    echo "Database not ready after 60s"
    exit 1
  fi
  sleep 1
done

if [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
  php artisan migrate --force
fi

exec "$@"
