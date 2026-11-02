FROM php:8.3-cli-alpine

# Instalar dependencias del sistema y extensiones de PostgreSQL
RUN apk add --no-cache postgresql-dev libzip-dev zip git \
    && docker-php-ext-install pdo pdo_pgsql zip

WORKDIR /var/www/html

# Copiar archivos del proyecto
COPY . .

# Copiar Composer desde imagen oficial
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Instalar dependencias de producción
RUN composer install --no-dev --optimize-autoloader --no-interaction

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
