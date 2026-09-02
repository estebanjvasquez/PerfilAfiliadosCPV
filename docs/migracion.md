# Migración a Laravel 12 / Filament v3 / Livewire 3 + conexión Supabase (pgsql)

**Fecha de redacción:** 2026-09-02 — sesión de trabajo con Claude Sonnet 5, a pedido de Esteban Vásquez.
**Estado:** 🟡 En despliegue al servidor de pruebas. No desplegado a producción todavía.

Este documento es la guía operativa de **dos cambios que se están desplegando juntos** al servidor
de pruebas, y sirve de referencia para cuando corresponda repetir esto en producción. No reemplaza
a `docs/PLAN_DESPLIEGUE_PRODUCCION.md` (ese cubre el despliegue de Filament v2 que ya está en
producción desde 2026-08-11) — este es el *próximo* despliegue, todavía no autorizado.

---

## Qué se está migrando

### A. Upgrade de framework (rama `feature/upgrade-filament-v3`)

Laravel 9.40.1 → 12.69.0, Filament v2.16.51 → v3.3.55, Livewire v2.10.7 → v3.8.7. Motivado por un
pedido de rendimiento del cliente (navegación instantánea, streaming, prefetch) que solo es posible
con Livewire 3. Ejecutado en 6 fases (A-F), todas cerradas y verificadas contra `mysql`:

- **Fase A** — limpieza de código muerto.
- **Fase B** — diagnóstico del fork de Shield (resultó ser boilerplate sin customización real).
- **Fase C** — bump de dependencias.
- **Fase D** — fixes estructurales (namespaces, RelationManager static→instancia, visibilidad,
  Heroicons v1→v2, Breezy Login/Register nativos de v3, `TextInput\Mask` eliminado, vista de perfil
  de Breezy con componente v2 inexistente en v3).
- **Fase E** — regresión completa + hallazgo real de N+1 (`ListEmpresas` tardaba ~90s/311 queries,
  heredado de `main` porque esta rama nace de *antes* de que se resolviera en
  `feature/supplhi-postgres-buscador`) — portado y corregido a ~2s/7 queries.
- **Fase F** — `wire:navigate` (SPA) + `deferLoading()` global en tablas (skeleton/streaming) +
  widgets ya lazy por defecto en Filament v3.

HEAD actual: `fa3c485` (Fase F) + `2035e96` (ver punto B).

### B. Conexión a Supabase/PostgreSQL portada a esta rama

`feature/upgrade-filament-v3` nace de `main`, de *antes* de que `feature/supplhi-postgres-buscador`
(iniciativa separada, pausada para hacer este upgrade) agregara la conexión `pgsql` a
`config/database.php`. Al intentar desplegar esta rama en el servidor de pruebas apuntando a
Supabase, se detectó que el bloque `pgsql` seguía leyendo las variables genéricas `DB_HOST`/
`DB_DATABASE`/etc. (las de mysql) en vez de las `DB_PGSQL_*` dedicadas — con `DB_CONNECTION=pgsql`
en el `.env`, esto intentaba hablar el protocolo de Postgres contra el host de mysql (puerto 3306),
error `SQLSTATE[08006] received invalid response to SSL negotiation`.

Se portó el bloque `pgsql` completo (commit `2035e96`) para que use `DB_PGSQL_HOST`,
`DB_PGSQL_PORT`, `DB_PGSQL_DATABASE`, `DB_PGSQL_USERNAME`, `DB_PGSQL_PASSWORD`,
`DB_PGSQL_SSLMODE` — mismo patrón ya probado en `feature/supplhi-postgres-buscador`.

**Verificado contra Supabase real** (`php artisan migrate:status --database=pgsql`, corrido desde
el entorno local que ya tenía las credenciales configuradas):

- Todas las tablas base (incluida `2026_09_01_130000_add_completion_percentage_to_empresas_table`,
  la migración nueva de la Fase E) ya estaban aplicadas ahí — quedaron desde el trabajo original de
  esa rama antes del pivot a este upgrade. **No hace falta volver a migrar el esquema.**
- Las migraciones que figuran `Pending` son las vistas de sintaxis específica de MySQL (no
  portables) — comportamiento esperado, ya resuelto por el mecanismo de esa otra rama, no lo toca
  este cambio.
- **Los permisos de Filament Shield SÍ están incompletos en Supabase**: 163 permisos existentes ahí
  vs. 285 que genera esta rama contra mysql (Shield v3 es nuevo en esta rama, nunca se generó
  contra pgsql). `super_admin` tiene los 163 que existen, pero le faltan ~122 de recursos/páginas/
  widgets nuevos de Filament v3. **Acción pendiente:** correr `shield:generate` contra pgsql (ver
  paso 7 de la sección siguiente).

---

## Pasos ejecutados en el servidor de pruebas (orden real, con lo que salió mal)

1. `git fetch origin` + `git checkout feature/upgrade-filament-v3` — **falló la primera vez** por un
   cambio local sin commitear en `app/Policies/RolePolicy.php`; se resolvió solo (era un estado
   transitorio de un merge fallido), confirmado con `git status`/`git diff` limpios en el segundo
   intento.
2. `git pull origin feature/upgrade-filament-v3` — HEAD confirmado en `fa3c485`.
3. `composer install --no-dev --prefer-dist --optimize-autoloader` — **falló la primera vez** por
   falta de la extensión `intl` en `C:\xampp\php\php.ini` (mismo problema que ya habíamos resuelto
   en el entorno local). Se habilitó (`extension=intl` sin comentar) y se reintentó — corrió limpio,
   36 paquetes viejos de Filament v2 removidos, todo lo nuevo de Laravel 12/Filament v3 instalado.
4. `php artisan migrate --force` — **falló** porque el `.env` de ese servidor tenía
   `DB_CONNECTION=pgsql` de cuando se probó la otra rama ahí mismo, y el código de `config/
   database.php` de esta rama todavía no sabía usar `DB_PGSQL_*` (ver sección B arriba). Se portó el
   fix (commit `2035e96`) y quedó pendiente re-ejecutar este paso (ver checklist abajo).

## Pasos pendientes en el servidor de pruebas (para retomar)

```powershell
git pull origin feature/upgrade-filament-v3   # trae el commit 2035e96 (fix de config/database.php)
```

**Confirmar/completar las variables de Supabase en el `.env` de ese servidor** (si ya las tenía de
la otra rama, esto es solo verificación):

```powershell
Select-String -Path .env -Pattern "^DB_PGSQL_"
```

Debe haber `DB_PGSQL_HOST`, `DB_PGSQL_PORT`, `DB_PGSQL_DATABASE`, `DB_PGSQL_USERNAME`,
`DB_PGSQL_PASSWORD`, `DB_PGSQL_SSLMODE`. Si falta alguna, agregarla (mismos valores que usa el
entorno local — pedirlos si no los tenés a mano en ese servidor, no se repiten acá por no dejar la
contraseña de Supabase escrita en un documento del repo).

Confirmar que `DB_CONNECTION=pgsql` sigue así (es la intención de este despliegue):

```powershell
Select-String -Path .env -Pattern "^DB_CONNECTION"
php artisan config:clear
```

**Retomar la secuencia de despliegue:**

```powershell
php artisan filament:assets
php artisan optimize:clear
php artisan db:skip-mysql-only-view-migrations   # ver nota abajo, correr ANTES de migrate
php artisan migrate --force                       # deberia mostrar "Nothing to migrate"
php artisan migrate:status                        # confirmar visualmente
php artisan empresas:refresh-completion   # backfill/reconciliacion, seguro repetirlo
php artisan shield:generate --all --panel=admin --ignore-existing-policies --no-interaction
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> **Nota real encontrada en este despliegue**: la primera vez que se corrio `migrate --force` contra
> pgsql (sin el paso `db:skip-mysql-only-view-migrations`), explotó en
> `2022_10_19_213831_create_catalogo_view` con `SQLSTATE[42601] syntax error ... SEPARATOR` —
> `GROUP_CONCAT(...SEPARATOR...)` es sintaxis exclusiva de MySQL (Postgres usa `STRING_AGG`). Las 13
> vistas reales ya existen en Supabase (creadas por `feature/supplhi-postgres-buscador`, confirmado
> con `information_schema.views`), pero Laravel las seguía viendo "Pending" para siempre en pgsql.
> Se agregó el comando `db:skip-mysql-only-view-migrations` (commit `808410d`, mismo patrón que
> `SkipVendorPgsqlOnlyMigrations` de la otra rama pero en sentido inverso) que las marca como ya
> corridas — correrlo **una vez por cada base pgsql nueva** (test, producción) **antes** de
> `migrate --force`, si no cualquier migración futura fechada después de esas 14 va a fallar en el
> mismo punto antes de llegar a la nueva.

## Verificación post-despliegue (servidor de pruebas)

- Login + Turnstile (sigue activo, no se desactivó para este despliegue).
- Listado de Empresas carga rápido y con datos reales de Supabase (columna "Ciudad" viene del query
  optimizado de la Fase E — confirma que el query funciona igual contra pgsql).
- Navegar entre páginas del panel: sin parpadeo de recarga completa (`wire:navigate`/SPA activo).
- Un usuario `super_admin` ve **todos** los recursos/reportes en el menú (si falta alguno, el
  `shield:generate` de arriba no llegó a correr o falló — revisar).
- Alta/edición de Empresa, attach/detach de servicios y contactos, exports (xlsx/csv/pdf), perfil de
  Breezy — misma batería que ya se verificó contra mysql en la Fase E de esta rama.
- `tail`/revisar `storage/logs/laravel.log` durante las pruebas, sin excepciones nuevas.

---

## Qué falta antes de llevar esto a producción

Este despliegue al servidor de pruebas **no autoriza** tocar producción todavía. Antes de siquiera
plantear una fecha:

- [ ] QA del cliente sobre el upgrade completo (Fases A-F) — todavía no se pidió ni se hizo.
- [ ] Decisión explícita del cliente: ¿producción pasa a `pgsql`/Supabase en este mismo despliegue,
      o se queda en `mysql` y la migración de base de datos es un paso posterior separado? Este
      documento asume que se prueban juntos en el servidor de pruebas, pero **no** que se despliegan
      juntos a producción sin una decisión explícita — son dos cambios independientes (upgrade de
      framework vs. cambio de motor de base de datos) que se pueden separar si el cliente lo prefiere.
- [ ] Fusionar `feature/upgrade-filament-v3` con `feature/supplhi-postgres-buscador` (o decidir cuál
      de las dos absorbe a la otra) — hoy son ramas independientes, ninguna tiene el trabajo completo
      de la otra.
- [ ] Actualizar `.cpanel.yml`: el pipeline actual corre `composer install` (no `update`), que **no**
      dispara `post-update-cmd` — hay que agregar `php artisan filament:assets` explícito como paso
      del deploy, si no el panel queda sin estilos/JS en producción.
- [ ] Confirmar PHP **8.2+** en el hosting de producción (Laravel 12 lo exige; `.cpanel.yml` ya usa
      `ea-php82`, así que probablemente ya está cubierto, pero confirmar) y extensión `intl`
      habilitada (no estaba en el checklist del despliegue anterior porque Filament v2 no la
      necesitaba).
- [ ] Correr `shield:generate --all --panel=admin --ignore-existing-policies --no-interaction` contra
      la base de datos real de producción como parte del pipeline (paso manual, igual que se hizo acá
      y que ya se documentó como necesario en `docs/PLAN_DESPLIEGUE_PRODUCCION.md` Paso 6 para el
      despliegue anterior — ahí era Shield v1, acá es v3, mismo tipo de paso).
- [ ] Backup completo de base de datos antes de tocar nada (estándar, igual que el despliegue
      anterior).

---

## Rollback (servidor de pruebas)

```powershell
git checkout <commit-o-rama-anterior>
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan filament:assets
php artisan optimize:clear
```

Si se volvió a `mysql` como conexión por defecto, recordar `DB_CONNECTION=mysql` en el `.env` y
`php artisan config:clear`. Ningún dato se pierde: los cambios de esquema en Supabase (la columna
`completion_percentage`) ya estaban aplicados desde antes de este despliegue, y `shield:generate`
solo agrega permisos, nunca borra los existentes.
