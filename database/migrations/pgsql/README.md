# Migraciones exclusivas de `pgsql`

Todo archivo en esta carpeta tiene `public $connection = 'pgsql';` y crea algo que **solo existe
en Postgres** (las 13 vistas reescritas de Fase 2, el esquema del catálogo SupplHi de Fase 4, y lo
que se agregue en fases futuras que no tenga equivalente en MySQL).

## Por qué están en una carpeta aparte

`php artisan migrate` (sin `--database`) escanea `database/migrations/*.php` de forma **no
recursiva** - los archivos acá dentro quedan invisibles para ese comando por diseño. Esto es
intencional y crítico:

- La conexión `default` de Laravel es `mysql`. Su tabla `migrations` nunca registra estos archivos.
- Si estuvieran en el nivel superior, `php artisan migrate --force` (default) los seguiría
  considerando "pendientes" para siempre (comprobado con `--pretend`) y trataría de volver a
  ejecutarlos - inofensivo para las vistas (`DROP VIEW IF EXISTS` + `CREATE VIEW`, idempotentes),
  pero **rompe con error real** en las migraciones de tablas nuevas (`CREATE TABLE` sin
  `IF NOT EXISTS`) porque ya existen en Postgres.

Mover el archivo de carpeta **no** afecta si Laravel ya lo considera "Ran" - el repositorio de
migraciones guarda solo el nombre del archivo, no su ruta.

## Cómo correrlas

```bash
php artisan migrate --database=pgsql --path=database/migrations/pgsql --force
php artisan migrate:status --database=pgsql --path=database/migrations/pgsql
```

Siempre con **ambos** flags (`--database=pgsql` decide contra qué motor corre; `--path=...` decide
qué archivos mirar). Cualquier migración nueva que solo aplique a Postgres va acá, con el mismo
`public $connection = 'pgsql';` de siempre.
