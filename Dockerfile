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

# Solo código fuente: .dockerignore deja fuera .env y variantes, .git, credenciales, logs, volcados y
# estado generado. La configuración llega en ejecución por env_file (docker-compose.yml), nunca en la imagen.
COPY . .

# Defensa en profundidad: si .dockerignore cambia o falla, la build se detiene aquí antes de producir una
# imagen. Cubre las mismas clases sensibles que .dockerignore, a cualquier profundidad: metadatos de git,
# .env y variantes, credenciales y llaves, volcados y respaldos (también comprimidos), sufijos de editor y
# logs. Las clases *credential* y *secret* se comparan sin distinguir mayúsculas, así que la guarda es más
# estricta que .dockerignore. Solo lista nombres de archivo, nunca su contenido. vendor/ lo instala composer
# dentro de la imagen y no viene del contexto.
RUN set -eu; \
    bad="$(find . -path ./vendor -prune -o \( \
          -name .git \
          -o -name .env -o \( -name '.env.*' ! -name .env.example \) \
          -o -name auth.json -o -name '*.key' -o -name '*.pem' -o -name '*.p12' -o -name '*.pfx' -o -name '*.ppk' \
          -o -name 'id_rsa*' -o -name 'id_dsa*' -o -name 'id_ecdsa*' -o -name 'id_ed25519*' \
          -o -iname '*credential*' -o -iname '*secret*' \
          -o -name '*.sql' -o -name '*.sql.*' -o -name '*.dump' -o -name '*.dump.*' \
          -o -name '*.bak' -o -name '*.backup' -o -name '*.orig' -o -name '*.swp' -o -name '*~' \
          -o -name 'composer.json-*' -o -name 'composer.lock-*' -o -name '*.js-bk' \
          -o -name '*.log' -o -name error_log \
        \) -print)"; \
    if [ -n "$bad" ]; then echo 'Archivos prohibidos en el contexto de build:'; echo "$bad"; exit 1; fi; \
    if [ -n "$(find bootstrap/cache storage -type f ! -name .gitignore ! -path 'storage/fonts/*' ! -name 'titulos*.json')" ]; then \
        echo 'Estado generado en bootstrap/cache o storage dentro del contexto de build'; exit 1; fi

# Estructura escribible de Laravel (los .gitignore la conservan, esto la garantiza). En ejecución
# storage/ y bootstrap/cache/ se montan desde el host y tapan este contenido.
RUN mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
        storage/framework/testing storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 9000
CMD ["php-fpm"]
