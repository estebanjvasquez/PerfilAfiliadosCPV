# Plan de Despliegue a Producción — `staging` → `main`

**Fecha de redacción:** 2026-08-04 — **última revisión: 2026-08-11**
**Preparado por:** sesión de trabajo con Claude Sonnet 5, a pedido de Esteban Vásquez.
**Estado del plan:** ✅ **DESPLEGADO A PRODUCCIÓN (2026-08-11).** El cliente aprobó explícitamente
todos los ítems de la tabla de [Fase del proyecto](#fase-del-proyecto--qué-falta-antes-de-fijar-fecha),
incluyendo el último 🟡 pendiente (PDF "Reporte por Empresa"), y el despliegue se ejecutó siguiendo
los pasos de este documento — con un incidente real durante el Paso 4 (`FinanceView` caída por un
bug de `ONLY_FULL_GROUP_BY`, ver nota en el [Checklist final](#checklist-final-para-copiarpegar-al-coordinar-la-ventana-de-despliegue))
diagnosticado y resuelto en la misma ventana. La checklist de Verificación post-despliegue está
completa. Este documento sí se sube al repo (a diferencia de `task.md`, que es solo notas locales)
porque es la guía operativa del despliegue real — queda como referencia histórica de cómo se hizo.

> Este documento reemplaza en la práctica a `LECTURA_PRIMERO.md` / `ESTADO_ANALISIS_JUNIO_2026.md` /
> `PROXIMO_PASOS.md` / `SNAPSHOT_SISTEMA_JUNIO_2026.md`, que describen un estado del proyecto de
> junio-julio 2026 (solo la Fase de "2 sectores + No Aplica") ya superado por todo lo acumulado en
> `staging` desde entonces (Fases 2-4, Tablero Gerencial, Sector Principal, máscaras). No se
> borraron esos archivos por si hace falta el historial, pero **no son la referencia vigente para
> el despliegue** — esta sí lo es.

---

## Fase del proyecto — qué falta antes de fijar fecha

**Ningún commit de todo este trabajo llegó a `main` todavía.** `main` está congelado desde el
merge de login/CAPTCHA; todo lo que sigue vive acumulado en `staging` (61 commits, 121 archivos de
diferencia, 4 migraciones nuevas), **con QA 100% cerrado y aprobado por el cliente**:

| Fase / cambio                                                                                        | Implementado                                       | QA del cliente                                                                                                                                                                                                                                                                                                                                                                    |
| ---------------------------------------------------------------------------------------------------- | -------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Fase 1 — Rediseño de pestañas (Recursos/Gestión/Experiencia/Presencia)                               | ✅                                                 | ✅ **Aprobada**                                                                                                                                                                                                                                                                                                                                                                   |
| Fase 2 — "No Aplica" por tipo + % completitud + reportes Completitud/Sectores                        | ✅                                                 | ✅ **Aprobada** (2026-08-04: Presencia Internacional, Experiencia Relevante y "% Perfil" confirmados por el cliente — QA 100% cerrado)                                                                                                                                                                                                                                            |
| Fase 3 — Tablero de Métricas Gerenciales                                                             | ✅                                                 | ✅ **Confirmado** (2026-08-10)                                                                                                                                                                                                                                                                                                                                                    |
| Fase 4 — Acciones masivas + filtros en Empresas                                                      | ✅                                                 | ✅ **Confirmado** (2026-08-10) — filtros (País/Ciudad/Sector) y botones disponibles para `super_admin` confirmados. Botón masivo "Editar" no aparece para usuario regular con 1 sola seleccionada — investigado, sin causa encontrada en el código; **decisión del cliente: no bloqueante**, el usuario regular ya puede editar haciendo clic directo sobre la fila de la empresa |
| Sector Principal solo editable por CPV (reunión 30 jul, punto 1)                                     | ✅                                                 | ✅ **Confirmado por el cliente** (2026-08-10)                                                                                                                                                                                                                                                                                                                                     |
| Normalización de textos + reporte "Estatus de Perfiles"                                              | ✅                                                 | ✅ **Confirmado en staging** (2026-08-10) — normalización de textos y columna "Usuario principal" ambas confirmadas                                                                                                                                                                                                                                                               |
| Máscaras de Teléfono/RIF (2026-08-03/04)                                                             | ✅                                                 | ✅ **Confirmado** (2026-08-10) — máscara de Teléfono funcionando en staging (era el `git pull` faltante, no un bug real); SQL de normalización de RIF y `UPDATE` de `status_id` (B.1) ambos aplicados en producción                                                                                                                                                               |
| Fase 5 — "No Aplica" granular en reportes (2026-08-04)                                               | ✅ commiteada y pusheada (`a542fe7`)               | ✅ **Confirmado** (2026-08-10) — evidencia en captura de reporte, empresa con varios módulos marcados "No Aplica" mostrando el texto correcto de forma consistente                                                                                                                                                                                                                |
| Layout del PDF "Reporte por Empresa" — saltos de página + Experiencia Relevante (ver `task.md` 1.14) | ✅ commiteada y pusheada                           | ✅ **Confirmación final del cliente (2026-08-11)**                                                                                                                                                                                                                                                                                                                                |
| Bug de valores asumidos en `NULL` — Facturación y 4 vistas más (ver `task.md` 1.15)                  | ✅ commiteada y pusheada (incluye nueva migración) | ✅ **Confirmado en las 5 vistas** (2026-08-10): Facturación, Experiencia, Maquinaria, Inventario e Instalaciones — campos sin llenar muestran blanco en vez del rango más alto                                                                                                                                                                                                    |
| 6 puntos restantes de la reunión del 30 de julio                                                     | ❌ No implementado                                 | — (fuera de alcance de este despliegue)                                                                                                                                                                                                                                                                                                                                           |

**Estado al 2026-08-11: el cliente aprobó explícitamente todos los ítems de la tabla, incluido el
último 🟡 pendiente (PDF "Reporte por Empresa").** No queda ningún ítem de QA abierto. La tabla
completa está en ✅ — corresponde coordinar la ventana de despliegue y ejecutar el resto de este
documento (pre-requisitos, backup, pasos 1-6).

---

## Alcance de este despliegue

Todo lo commiteado en `staging` desde el último merge a `main` (login/CAPTCHA). Ver
`git log main..staging --oneline` para el detalle completo (61 commits, 121 archivos, +8308/-2078
líneas) — resumen por fase en la tabla de arriba y en `task.md`.

**Buena noticia de integración:** `main` es ancestro directo de `staging`
(`git merge-base --is-ancestor main staging` ⇒ true) — o sea que **no hay commits en `main` que no
estén ya en `staging`**. El merge va a ser un **fast-forward limpio**, sin conflictos posibles.
Igual de bueno: `.cpanel.yml`, `composer.json` y `composer.lock` son **idénticos** entre ambas
ramas (ya reconciliados en sesiones anteriores) — no hay sorpresas de dependencias ni de pipeline
de deploy.

---

## Cambios de base de datos

Dos categorías completamente distintas — **no confundirlas**, porque se aplican de forma
diferente:

### A. Migraciones (se aplican solas, automáticamente, al desplegar)

`.cpanel.yml` corre `php artisan migrate --force` como parte del pipeline de deploy (ver
[Pasos de despliegue](#pasos-de-despliegue)) — estas 4 migraciones nuevas en `staging` se aplican
sin que haga falta tocar nada a mano:

1. **`2026_06_12_000001_add_sector_principal_secundario_to_empresas_table.php`**
   Agrega `sector_principal_id` y `sector_secundario_id` (FK nullable a `sectors`) a `empresas`.
   Reversible (`down()` las borra).

2. **`2026_06_12_000002_create_empresa_module_status_table.php`**
   Crea la tabla `empresa_module_status` (`empresa_id`, `module`, `no_aplica`, unique
   `[empresa_id, module]`). Reversible (`down()` la dropea).

3. **`2026_07_24_000001_add_sub_type_to_empresa_module_status_table.php`**
   Agrega la columna `sub_type` a `empresa_module_status` + reemplaza el unique key por uno que
   incluye `sub_type`. **Ya está escrita para ser idempotente** (chequea `Schema::hasColumn()` /
   `information_schema.statistics` antes de cada paso, y crea el índice nuevo antes de borrar el
   viejo para no romper la FK de `empresa_id` — ver comentario dentro del archivo) — si el deploy
   se corta a mitad de camino, un segundo `php artisan migrate` la retoma sin error.

4. **`2026_08_04_000001_fix_null_bucket_defaults_in_report_views.php`**
   Recrea (`DROP VIEW` + `CREATE VIEW`) `FinanceView`, `ExperienceView`, `MachineryView`,
   `InventoryView` y `FacilityView` para corregir el bug de `task.md` 1.15: el `CASE` de cada vista
   asumía el rango numérico más alto (ej. "> 10.000.001 USD") cuando la empresa nunca había
   seleccionado nada en ese campo, en vez de mostrar vacío. Son vistas de solo consulta — **no
   toca ninguna fila de datos**, solo redefine las 5 vistas con un `ELSE NULL` explícito. No tiene
   `down()` reversible a la definición anterior (recrearía el bug); si hiciera falta revertir, ver
   [Rollback](#rollback).

No hace falta ninguna acción manual para estas 4 — solo confirmar que corrieron
(ver [Verificación post-despliegue](#verificación-post-despliegue)).

### B. Cambios de datos manuales (NO están en ninguna migración — hay que replicarlos a mano)

Estos dos cambios se probaron y ya se corrieron **solo en el XAMPP de pruebas** (una copia de la
BD en una máquina distinta a producción). El pipeline de deploy **no los toca** — si no se corren
a mano en la BD real después del deploy, producción queda con datos inconsistentes aunque el
código ya esté actualizado. Correr ambos en phpMyAdmin de producción, **después** de que termine
el deploy del código (para que las columnas/estructura ya existan), y **con un respaldo de las
tablas involucradas tomado antes de tocar nada** (ver [Paso 1](#paso-1--backup-de-la-base-de-datos)).

#### B.1 — `status_id` obligatorio en Empresas (backfill de "activas")

Contexto completo en `task.md` punto 1.8: `status_id` es `nullable` sin default; una empresa
creada sin tocar ese select quedaba en `NULL`, lo que la sacaba tanto del filtro "Activa" (`= 1`)
como de "Inactiva" (`= 0`). El fix de código (forms `->required()->default('1')`) ya está en
`staging`; falta corregir los datos que ya estaban en `NULL` antes del fix.

```sql
UPDATE empresas SET status_id = 1 WHERE status_id IS NULL;
```

Seguro — no toca ninguna fila que ya tenga `0` explícito, solo las que están en `NULL`.
**Ya se corrió en el XAMPP de pruebas y quedó confirmado por el usuario.** **Actualización
2026-08-10: también se corrió ya en la base de datos de producción**, junto con el de B.2 (mismo
adelanto respecto al orden documentado en el [Paso 5](#paso-5--correr-los-2-sql-manuales-de-la-sección-b) — seguro por el mismo motivo, no depende de ninguna migración nueva).

#### B.2 — Normalizar RIF sin guiones/símbolos

Contexto completo en `task.md` punto 1.12: el código ahora exige `letra(V/E/J/P/G) + 9 dígitos`
sin guiones; los RIF cargados antes de este cambio pueden tener `-`, espacios u otros símbolos.

**Paso 1 — chequeo de colisiones** (dos RIF distintos con guion podrían normalizar al mismo valor;
`rif` tiene constraint `unique`):

```sql
SELECT UPPER(REPLACE(REPLACE(REPLACE(TRIM(rif), '-', ''), ' ', ''), '.', '')) AS rif_normalizado,
       COUNT(*) AS cantidad, GROUP_CONCAT(id) AS ids
FROM empresas
GROUP BY rif_normalizado
HAVING COUNT(*) > 1;
```

Si devuelve filas, **parar y resolver manualmente** esas empresas puntuales antes de seguir (no
debería pasar en la práctica, es la señal de seguridad antes de tocar datos reales).

**Paso 2 — vista previa** (no modifica nada):

```sql
SELECT id, rif AS rif_actual,
       UPPER(REPLACE(REPLACE(REPLACE(TRIM(rif), '-', ''), ' ', ''), '.', '')) AS rif_normalizado
FROM empresas
WHERE rif <> UPPER(REPLACE(REPLACE(REPLACE(TRIM(rif), '-', ''), ' ', ''), '.', ''));
```

**Paso 3 — aplicar** (solo si el Paso 1 no mostró colisiones):

```sql
UPDATE empresas
SET rif = UPPER(REPLACE(REPLACE(REPLACE(TRIM(rif), '-', ''), ' ', ''), '.', ''))
WHERE rif <> UPPER(REPLACE(REPLACE(REPLACE(TRIM(rif), '-', ''), ' ', ''), '.', ''));
```

**Ya se corrió en el XAMPP de pruebas y quedó confirmado por el usuario.** **Actualización
2026-08-10: también se corrió ya en la base de datos de producción** (junto con el `UPDATE` de
`status_id` de B.1), adelantado respecto al orden documentado en el
[Paso 5](#paso-5--correr-los-2-sql-manuales-de-la-sección-b) (que lo ubica _después_ del
merge/deploy de código) — es seguro haberlo adelantado porque ninguno de los dos `UPDATE` depende
de ninguna columna ni migración nueva, solo normalizan datos ya existentes. **Los 2 SQL manuales
de la sección B quedan resueltos** — el Paso 5 durante la ventana de despliegue pasa a ser
verificación, no ejecución. Al llegar al cutover, igual conviene re-correr el chequeo de
colisiones del Paso 1 por si se cargó alguna empresa nueva con RIF sin normalizar entre el
2026-08-10 y la fecha real del despliegue.

#### B.3 — Límite de 2 sectores por empresa + Sector Principal/Secundario

Este es el único ítem de "sectores" documentado: **no es un cambio de datos manual**, es la
migración A.1 de arriba (agrega las columnas) + la lógica de límite de 2 sectores que ya vive en
el código de `staging` desde hace varias fases (`task.md` punto 1.1, aprobado por el cliente). Se
menciona acá para que quede explícito en esta guía, tal como pidió el cliente, pero **no requiere
ningún SQL manual aparte** — se resuelve solo con la migración A.1 + el código ya en `staging`.
Dato de contexto (de sesiones anteriores): al momento de ese análisis, 34 empresas tenían
servicios en más de 2 sectores distintos y quedarán bloqueadas al editar hasta que ajusten sus
sectores — comunicarlo al cliente como parte de la ventana de despliegue.

---

## Pre-requisitos antes de desplegar

- [x] **Aprobación explícita del cliente** sobre todos los ítems 🟡/🔴 de la tabla de
      [Fase del proyecto](#fase-del-proyecto--qué-falta-antes-de-fijar-fecha) — **confirmado
      2026-08-11**, incluido el último pendiente (PDF "Reporte por Empresa").
- [x] **Extensión GD habilitada en el PHP de producción** — **confirmado (2026-08-10)** vía WHM
      (Software → Module Manager): `php82-php-gd`, `php83-php-gd`, `php84-php-gd` y `php85-php-gd`
      aparecen los 4 como `Installed`, incluyendo `ea-php82` (la versión que usa `.cpanel.yml`). Sin
      riesgo de que los 14 gráficos del PDF del Tablero Gerencial caigan a placeholders de texto. Ver
      `task.md` punto 8 para el detalle técnico de por qué hace falta GD (dompdf 2.0.1 no soporta
      `<svg>`).
- [ ] Acceso confirmado a phpMyAdmin (o SSH+mysql) de producción, para el backup y los 2 SQL
      manuales de la sección anterior.
- [ ] Ventana de mantenimiento acordada con el cliente (aunque el deploy en sí es rápido, conviene
      avisar por las migraciones + los 2 UPDATE manuales).

### Opcional — no bloqueante, pero misma ventana tiene sentido

- [ ] `SESSION_SECURE_COOKIE=true` en el `.env` real de producción (tarea pausada #2 de `task.md`,
      hardening de la cookie `remember_token` para que viaje con el atributo `Secure`) — independiente
      de este despliegue, pero como igual hay que correr `config:cache` durante el deploy, es un buen
      momento para aplicarlo si el cliente lo autoriza.

---

## Pasos de despliegue

### Paso 1 — Backup de la base de datos

**No saltear este paso.** Desde phpMyAdmin de producción: exportar la base completa (o al mínimo
las tablas `empresas`, `empresa_module_status`, `contacts`) antes de tocar nada. Si hay acceso SSH:

```bash
mysqldump -u <user> -p <database> > backup_pre_despliegue_$(date +%Y%m%d_%H%M%S).sql
```

Guardar el backup fuera del servidor (descarga local) antes de continuar.

### Paso 2 — Merge `staging` → `main` (fast-forward, sin conflictos esperados)

```bash
git checkout main
git pull origin main
git merge --ff-only staging
```

Si por algún motivo `--ff-only` rechaza el merge (señal de que `main` recibió commits nuevos que
no vimos en el análisis de este documento — recorrer la sección
[Alcance de este despliegue](#alcance-de-este-despliegue) de nuevo antes de forzar nada), **parar
y volver a evaluar** en vez de usar `git merge` a secas o `--no-ff` sin entender por qué divergió.

### Paso 3 — Push a `main`

```bash
git push origin main
```

### Paso 4 — Disparar el deploy en cPanel

Vía cPanel → Git Version Control → repositorio de este proyecto → "Update from Remote" (trae el
nuevo HEAD) → "Deploy HEAD Commit" (corre `.cpanel.yml`). Esto ejecuta automáticamente, en orden:

```yaml
rsync (código nuevo a /home/campetapp/public_html)
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan config:clear
php artisan view:clear
php artisan cache:clear
php artisan migrate --force   # ← acá se aplican las 4 migraciones de la sección A
php artisan config:cache
php artisan view:cache
```

Revisar el log de deploy de cPanel al terminar — confirmar que las 8 tareas corrieron sin error
(en particular `composer install` y `migrate --force`).

### Paso 5 — Verificar los 2 SQL manuales de la sección B

**Ya se corrieron ambos en producción el 2026-08-10, adelantados a este paso** (ver B.1/B.2) — este
paso pasa de "ejecutar" a "verificar que sigan aplicados":

1. Sección B.1 (`status_id`) — confirmar `SELECT COUNT(*) FROM empresas WHERE status_id IS NULL;`
   devuelve 0.
2. Sección B.2 (RIF) — re-correr el chequeo de colisiones del Paso 1 (por si se cargó alguna
   empresa nueva con RIF sin normalizar entre el 08-10 y la fecha real del despliegue) y confirmar
   `SELECT * FROM empresas WHERE rif REGEXP '[^A-Za-z0-9]';` devuelve 0 filas.

### Paso 6 — Registrar los permisos de página nuevos en Filament Shield

**No es solo el Tablero Gerencial.** Las 3 páginas agregadas en Fase 2/3 usan el trait
`HasPageShield`, cuyo `canView()` hace `Auth::user()->can('page_xxx')` **sin ningún bypass
automático para `super_admin`** (verificado en `vendor/bezhansalleh/filament-shield` — no hay
`Gate::before` en ningún lado del proyecto ni del paquete). Si el permiso no existe en la tabla
`permissions`, la página **no aparece en el menú para nadie, ni siquiera para `super_admin`** — no
es un bug de código, es un paso de aprovisionamiento que falta:

- `page_completion_view` — página "Estatus de Perfiles" (`CompletionView.php`)
- `page_sectors_view` — página "Sectores por Empresa" (`SectorsView.php`)
- `page_gerencia_dashboard` — página "Tablero de Métricas Gerenciales" (`GerenciaDashboard.php`)

Ninguno de los 3 existe todavía en la tabla `permissions` de producción. Un solo comando los crea
los 3 de una vez **y ya deja `super_admin`/`filament_user` con acceso automático** (ver
`FilamentShield::giveSuperAdminPermission()` — se llama sola dentro de `generateForPage()`, no
requiere un paso manual aparte para esos 2 roles):

```bash
php artisan shield:generate
```

Si además existe algún rol gerencial custom (no `super_admin`/`filament_user`) que deba ver el
Tablero, asignarle `page_gerencia_dashboard` manualmente desde el panel de Filament Shield (Roles)
— ese es el único paso manual restante.

**Mismo requisito aplica al servidor de pruebas** (no solo a producción) — si en el entorno de QA
del cliente/usuario no aparecen "Estatus de Perfiles", "Sectores por Empresa" ni el Tablero
Gerencial en el menú, es casi seguro este mismo paso sin correr ahí, no un bug de código.

---

## Verificación post-despliegue

- [x ] `php artisan migrate:status` — confirmar que las 4 migraciones de la sección A figuran como
  `Ran`.
- [x ] Login normal (`/admin/login`) funciona, CAPTCHA/Turnstile sigue activo.
- [x ] Crear una empresa de prueba: RIF se autoconvierte a mayúsculas sin guion, rechaza formato
  inválido; teléfono aplica la máscara `+58-XXX-XXXXXXX` al tipear.
- [x ] Editar una empresa existente: teléfono con máscara, RIF sigue deshabilitado.
- [x ] Listado de Empresas: columna "Activo" refleja bien las empresas que tenían `status_id NULL`
  (deberían aparecer como activas tras el UPDATE del Paso 5.1).
- [x ] Buscar alguna empresa que antes tuviera RIF con guion — confirmar que ahora se ve sin
  guion/símbolos en el listado y al editar.
- [ x] "Estatus de Perfiles" y "Sectores por Empresa" aparecen en el menú "Reportes", y el Tablero
  Gerencial aparece en el menú "Gerencia" (si falta alguno, ver Paso 6 — `shield:generate`).
- [x ] Tablero Gerencial (`/admin/gerencia-dashboard` o la ruta que corresponda): los 15 widgets
  cargan, los filtros (Sector/Cámara/Estado) no se revierten solos a los ~5s.
- [x ] Botón "Descargar PDF" del Tablero Gerencial: el PDF descarga (no falla en silencio) y
  **los 14 gráficos aparecen a color** (si salen placeholders de texto, GD no está habilitado —
  volver al pre-requisito de la sección anterior).
- [x ] Reporte "Estatus de Perfiles": columna "Usuario principal" con datos correctos.
- [x ] Reportes de Facturación/Experiencia/Maquinaria/Inventario/Instalaciones: una empresa sin
  datos cargados en el campo correspondiente muestra el valor en blanco, no el rango más alto
  (confirma que la migración de la sección A.4 recreó bien las 5 vistas).
- [x ] Descargar el PDF "Reporte por Empresa" de una empresa con varias experiencias (ej. GEOHIDRA)
  y confirmar visualmente: sin texto superpuesto en "Experiencia Relevante", sin saltos de página
  innecesarios, franja amarilla solo como encabezado de página.
- [x ] Acciones masivas en Empresas: con un usuario `super_admin` ver
  Editar/Activar/Desactivar/Eliminar; con un usuario normal, solo "Editar". Modal de Eliminar
  exige escribir `BORRAR` y borra en cascada sin error de FK.
- [ ] `tail -f storage/logs/laravel.log` durante las pruebas de arriba — sin excepciones nuevas.

---

## Rollback

Si algo falla durante o después del deploy:

```bash
# 1. Revertir el código
git checkout main
git reset --hard <commit-anterior-al-merge>   # el HEAD de main antes del Paso 2
git push origin main --force-with-lease        # ⚠️ requiere aprobación explícita antes de forzar

# 2. Re-desplegar esa versión anterior vía cPanel (Update from Remote + Deploy HEAD Commit)

# 3. Restaurar la BD desde el backup del Paso 1 (si los SQL manuales o las migraciones
#    ya corrieron y dejaron datos inconsistentes)
mysql -u <user> -p <database> < backup_pre_despliegue_YYYYMMDD_HHMMSS.sql
```

Las primeras 3 migraciones tienen `down()` funcional (`php artisan migrate:rollback`) si se
prefiere revertir solo el esquema sin restaurar el backup completo. La 4ª (`fix_null_bucket_defaults...`)
tiene `down()` intencionalmente vacío — no hay una versión "anterior" útil a la que volver, ya que
recrearía el bug de datos que corrige — así que un rollback de esa migración puntual no hace nada
por diseño. Dado que además hay UPDATEs manuales de por medio (sección B), restaurar desde backup
es más seguro que confiar solo en los rollbacks de Laravel.

**Nota:** un `git push --force` a `main` es una acción destructiva sobre una rama compartida —
pedir confirmación explícita antes de ejecutarlo, no es algo para correr automáticamente ante el
primer error.

---

## Checklist final (para copiar/pegar al coordinar la ventana de despliegue)

```
Antes de la ventana:
[x] Cliente aprobó explícitamente cada ítem 🟡/🔴 de la tabla de Fase del proyecto (2026-08-11,
    incluido el último pendiente: PDF "Reporte por Empresa")
[x] Se resolvió el reporte de la máscara de teléfono — era el `git pull` faltante, no un bug
    real (2026-08-10, confirmado funcionando en staging)
[x] GD confirmado habilitado en PHP de producción (2026-08-10, ver Pre-requisitos)
[ ] Acceso a phpMyAdmin/SSH de producción confirmado

Durante la ventana:
[x] Paso 1 — Backup de BD tomado y descargado (2026-08-11: respaldo de la BD + del directorio
    home completo)
[x] Paso 2 — Merge staging→main (fast-forward, `3e4b7ac`)
[x] Paso 3 — Push a main
[x] Paso 4 — Deploy vía cPanel — **incidente real durante este paso, ver nota abajo**; resuelto y
    re-desplegado con éxito (2026-08-11)
[x] Paso 5.1 — UPDATE status_id corrido (adelantado a producción el 2026-08-10, ver B.1 — solo
    re-verificar en la ventana)
[x] Paso 5.2 — SQL de RIF corrido (adelantado a producción el 2026-08-10, ver B.2 — re-correr el
    chequeo de colisiones por las dudas antes de dar por cerrado)
[x] Paso 6 — shield:generate corrido (confirmado indirectamente: los 3 items nuevos del menú
    aparecen en la Verificación post-despliegue)

Después de la ventana:
[x] Checklist de "Verificación post-despliegue" completo (2026-08-11 — único ítem sin marcar:
    revisión de `laravel.log`, no bloqueante)
[ ] Cliente notificado de que producción está actualizada
```

### Incidente durante el Paso 4 (2026-08-11) — `FinanceView` caída, resuelto

El primer intento de deploy se cortó durante `migrate --force`: la migración de la sección A.4
(`fix_null_bucket_defaults_in_report_views`) ejecutó el `DROP VIEW FinanceView` pero falló en el
`CREATE VIEW` siguiente con `SQLSTATE[42000]` (`ONLY_FULL_GROUP_BY`) — las 4 columnas derivadas
(`BILLING`/`ESTADO`/`CAPITAL`/`ORIGEN`) no estaban envueltas en `MAX(...)` como sí lo están el
resto de vistas del mismo archivo, mismo tipo de bug ya corregido antes en `2b6a923`. Esto dejó
`FinanceView` inexistente en producción (reporte de Capacidad Financiera caído) hasta diagnosticar
la causa (se descartó lock/timeout vía `SHOW FULL PROCESSLIST` — la base estaba libre) y aplicar el
fix (commit `c996e9a`, pusheado a `main`/`staging`, redeploy vía cPanel). Confirmado resuelto: las
5 vistas existen y la Verificación post-despliegue está completa.
