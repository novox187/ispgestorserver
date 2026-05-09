# ─── Stage 1: Dependencias de Composer ──────────────────────────────────────
FROM composer:2.8 AS composer-stage

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --ignore-platform-reqs

COPY . .
RUN composer dump-autoload --optimize --no-dev

# ─── Stage 2: Imagen de producción ───────────────────────────────────────────
FROM php:8.3-fpm-alpine

LABEL maintainer="ISP Gestor"

# Instalar dependencias del sistema
RUN apk add --no-cache \
        nginx \
        supervisor \
        curl \
        libpng-dev \
        libjpeg-turbo-dev \
        libwebp-dev \
        freetype-dev \
        libzip-dev \
        oniguruma-dev \
        icu-dev \
        openssl-dev \
        pcre-dev \
        linux-headers \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
    && docker-php-ext-install \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
        opcache \
        sockets

# Directorios de logs para supervisor
RUN mkdir -p /var/log/supervisor /run/nginx

# Copiar configuraciones
COPY docker/nginx.conf       /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/php.ini          /usr/local/etc/php/conf.d/app.ini

# Copiar código de la aplicación
WORKDIR /var/www/html

COPY --from=composer-stage /app .

# Permisos correctos para Laravel
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage \
    && chmod -R 755 /var/www/html/bootstrap/cache

EXPOSE 80

# Supervisor como punto de entrada — gestiona nginx, php-fpm, workers y scheduler
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
