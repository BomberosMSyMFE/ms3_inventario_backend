#!/bin/sh
set -e

echo "=== INICIANDO MS-03 INVENTARIO Y SUMINISTROS (LARAVEL 11) ==="

# Generar APP_KEY si no existe en tiempo de ejecución
if [ -z "$APP_KEY" ]; then
    echo "Generando APP_KEY temporal..."
    php artisan key:generate --force
fi

# Optimizar caché de configuración si es producción
if [ "$APP_ENV" = "production" ]; then
    echo "Optimizando configuración para entorno de producción..."
    php artisan config:cache || true
    php artisan route:cache || true
    php artisan view:cache || true
fi

# Ejecutar migraciones automáticamente si se especifica la variable RUN_MIGRATIONS=true
if [ "$RUN_MIGRATIONS" = "true" ]; then
    echo "Ejecutando migraciones de base de datos..."
    php artisan migrate --force || true
    if [ "$SEED_DATASET" = "true" ]; then
        echo "Sembrando dataset maestro de 1,000 registros..."
        php artisan db:seed --class=DatasetMilRegistrosSeeder --force || true
    fi
fi

# Asignar puerto dinámico para compatibilidad con Cloud (Render, Railway, Heroku) o 8003 por defecto
HTTP_PORT=${PORT:-8003}
echo "MS-03 Inventario listo y escuchando en http://0.0.0.0:$HTTP_PORT"

exec php artisan serve --host=0.0.0.0 --port=$HTTP_PORT
