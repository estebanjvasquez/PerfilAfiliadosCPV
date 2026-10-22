# Imagen para el entorno de pruebas/producción en Contabo (ver docs/guia_contabo_entorno_pruebas.md
# y docs/arquitectura_final_contabo_cloudflare.md). PHP 8.2 para paridad con ea-php82 de producción.
#
# Extensiones: intl (paso real ya visto en migracion.md - composer install falla sin esto),
# pdo_pgsql (Supabase/Postgres), zip/gd (maatwebsite/excel, mpdf), bcmath (casts decimales de
# Laravel), el resto (mbstring/xml/curl/etc.) ya vienen en la imagen base php:8.2-fpm.

# Base fijada por digest a PHP 8.2.35 (CVE-2026-109618 / CVE-2026-109619, ver contabo-admin Issue #1): una
# etiqueta mutable no prueba qué PHP queda construido. Al subir de parche, cambiar versión y digest juntos.
FROM php:8.2.35-fpm@sha256:a58479410c94b3f0f7dfacc27fba8befc122290743fd0eb8c88a99ff2a0b1ba1 AS base

RUN apt-get update && apt-get install -y \
        libpq-dev \
        libicu-dev \
        libzip-dev \
        libpng-dev \
        libonig-dev \
        libxml2-dev \
        git \
        unzip \
    && docker-php-ext-install -j$(nproc) \
        pdo_pgsql \
        intl \
        zip \
        gd \
        bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --optimize-autoloader --no-scripts --no-interaction

COPY . .
RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
