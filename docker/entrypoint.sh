#!/bin/sh
set -e

echo "=== SkillHub API démarrage ==="

if [ -z "$APP_KEY" ]; then
    php artisan key:generate --force
fi

if [ -z "$JWT_SECRET" ]; then
    php artisan jwt:secret --force
fi

# Attente MySQL
echo "Attente de MySQL..."
RETRIES=0
MAX_RETRIES=30
until php -r "new PDO('mysql:host=${DB_HOST};port=${DB_PORT}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
    RETRIES=$((RETRIES + 1))
    if [ "$RETRIES" -ge "$MAX_RETRIES" ]; then
        echo "ERREUR: MySQL non disponible"
        exit 1
    fi
    echo "MySQL pas prêt ($RETRIES/$MAX_RETRIES)..."
    sleep 5
done

php artisan migrate --force
php artisan config:cache
php artisan route:cache

mkdir -p /var/www/html/public/images/profils
chown -R www-data:www-data /var/www/html/public/images

echo "=== Démarrage PHP-FPM ==="
exec "$@"
