#!/bin/sh
set -e

echo "=== SkillHub API démarrage ==="

# Génère APP_KEY si absent
if [ -z "$APP_KEY" ]; then
    echo "Génération APP_KEY..."
    php artisan key:generate --force
fi

# Génère JWT_SECRET si absent
if [ -z "$JWT_SECRET" ]; then
    echo "Génération JWT_SECRET..."
    php artisan jwt:secret --force
fi

# Attente MySQL avant migration
echo "Attente de MySQL..."
until php artisan migrate --force > /dev/null 2>&1; do
    echo "MySQL pas prêt, retry dans 5s..."
    sleep 5
done
echo "Migrations OK"

php artisan config:cache
php artisan route:cache

echo "=== Démarrage PHP-FPM ==="
exec php-fpm