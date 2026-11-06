FROM php:8.3-cli-alpine

WORKDIR /var/www/html

# Instalar dependencias del sistema y extensiones de PostgreSQL
RUN apk add --no-cache \
    postgresql-dev \
    icu-dev \
    oniguruma-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    curl \
    postgresql-client

RUN docker-php-ext-install pdo pdo_pgsql intl opcache bcmath

# Instalar Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# Copiar el código del proyecto respetando .dockerignore
COPY . .

# Instalar dependencias PHP optimizadas
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# Permisos de almacenamiento
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod +x /var/www/html/docker-entrypoint.sh

EXPOSE 8003

ENTRYPOINT ["/var/www/html/docker-entrypoint.sh"]
