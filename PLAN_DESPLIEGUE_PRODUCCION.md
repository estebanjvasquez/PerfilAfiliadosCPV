# Plan de Despliegue a Producción — `staging` → `main`

**Fecha de redacción:** 2026-08-04
**Preparado por:** sesión de trabajo con Claude Sonnet 5, a pedido de Esteban Vásquez.
**Estado del plan:** guía de referencia — **no ejecutar todavía**, ver [Fase del proyecto](#fase-del-proyecto--qué-falta-antes-de-fijar-fecha) abajo. Este documento sí se sube al repo (a diferencia de `task.md`, que es solo notas locales) porque es la guía operativa del despliegue real.

> Este documento reemplaza en la práctica a `LECTURA_PRIMERO.md` / `ESTADO_ANALISIS_JUNIO_2026.md` /
> `PROXIMO_PASOS.md` / `SNAPSHOT_SISTEMA_JUNIO_2026.md`, que describen un estado del proyecto de
> junio-julio 2026 (solo la Fase de "2 sectores + No Aplica") ya superado por todo lo acumulado en
> `staging` desde entonces (Fases 2-4, Tablero Gerencial, Sector Principal, máscaras). No se
> borraron esos archivos por si hace falta el historial, pero **no son la referencia vigente para
> el despliegue** — esta sí lo es.

---

## Fase del proyecto — qué falta antes de fijar fecha

**Ningún commit de todo este trabajo llegó a `main` todavía.** `main` está congelado desde el
merge de login/CAPTCHA; todo lo que sigue vive acumulado en `staging` (~40 commits, 107 archivos
de diferencia, 3 migraciones nuevas), en distintos grados de QA:

| Fase / cambio | Implementado | QA del cliente |
|---|---|---|
| Fase 1 — Rediseño de pestañas (Recursos/Gestión/Experiencia/Presencia) | ✅ | ✅ **Aprobada** |
| Fase 2 — "No Aplica" por tipo + % completitud + reportes Completitud/Sectores | ✅ | ✅ **Aprobada** (2026-08-04: Presencia Internacional, Experiencia Relevante y "% Perfil" confirmados por el cliente — QA 100% cerrado) |
| Fase 3 — Tablero de Métricas Gerenciales | ✅ | 🟡 El cliente confirmó que **se veía bien visualmente**, pero eso fue *antes* de las 3 rondas de fixes del PDF (el render pasó de SVG a GD) — el PDF actual en `staging` **todavía no lo vio el cliente** |
| Fase 4 — Acciones masivas + filtros en Empresas | ✅ | 🔴 Sin QA todavía (roles, modal "BORRAR", filtros combinados) |
| Sector Principal solo editable por CPV (reunión 30 jul, punto 1) | ✅ | 🟡 Sin confirmación explícita (probar con usuario no-admin y con `super_admin`) |
| Normalización de textos + reporte "Estatus de Perfiles" | ✅ | 🟡 Sin confirmación explícita |
| Máscaras de Teléfono/RIF (2026-08-03/04) | ✅ | 🔴 En curso — se reportó que la máscara "no funciona", pendiente confirmar si es porque el entorno de pruebas no tenía el `git pull` del commit `a9f3855`, o si hay un bug real |
| Fase 5 — "No Aplica" granular en reportes (2026-08-04) | ✅ implementada, **sin commitear/pushear todavía** | 🔴 Sin QA — ver `task.md` 1.13, falta probar contra el XAMPP del cliente |
| 6 puntos restantes de la reunión del 30 de julio | ❌ No implementado | — (fuera de alcance de este despliegue) |

**Recomendación:** antes de fijar fecha de despliegue, cerrar la lista de QA de la tabla de arriba
con el cliente **ítem por ítem** (no un "se ve bien" informal) — es un despliegue grande y
acumulado, con cambios de datos manuales de por medio (ver abajo), así que conviene tratarlo como
un solo paquete revisado a conciencia, no ir empujando fixes sueltos a producción. En particular:
1. Resolver primero el reporte de la máscara de teléfono (¿era el `git pull` faltante, o un bug
   real?) — bloquea el ítem más reciente de QA.
2. Pedir al cliente que confirme explícitamente los puntos 🟡/🔴 de la tabla.
3. Recién ahí, agendar una ventana de despliegue siguiendo este documento.

---

## Alcance de este despliegue

Todo lo commiteado en `staging` desde el último merge a `main` (login/CAPTCHA). Ver
`git log main..staging --oneline` para el detalle completo (~40 commits) — resumen por fase en la
tabla de arriba y en `task.md`.

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
[Pasos de despliegue](#pasos-de-despliegue)) — estas 3 migraciones nuevas en `staging` se aplican
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

No hace falta ninguna acción manual para estas 3 — solo confirmar que corrieron
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
**Ya se corrió en el XAMPP de pruebas y quedó confirmado por el usuario.**

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

**Ya se corrió en el XAMPP de pruebas y quedó confirmado por el usuario.**

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

- [ ] **Aprobación explícita del cliente** sobre todos los ítems 🟡/🔴 de la tabla de
  [Fase del proyecto](#fase-del-proyecto--qué-falta-antes-de-fijar-fecha).
- [ ] **Extensión GD habilitada en el PHP de producción** (`ea-php82` según `.cpanel.yml`) — es
  requisito para que los 14 gráficos del PDF del Tablero Gerencial se vean (si falta, el PDF se
  genera igual pero con placeholders de texto en vez de gráficos, sin error visible). Verificar
  **antes** del deploy vía cPanel → "MultiPHP INI Editor" → pestaña de extensiones, o
  `php -m | grep gd` por SSH si hay acceso. Ver `task.md` punto 8 para el detalle técnico completo
  (por qué se necesita GD y no alcanza con SVG, dompdf 2.0.1 no soporta `<svg>`).
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
php artisan migrate --force   # ← acá se aplican las 3 migraciones de la sección A
php artisan config:cache
php artisan view:cache
```

Revisar el log de deploy de cPanel al terminar — confirmar que las 8 tareas corrieron sin error
(en particular `composer install` y `migrate --force`).

### Paso 5 — Correr los 2 SQL manuales de la sección B

**En este orden, en phpMyAdmin de producción, uno a la vez:**
1. Sección B.1 (`status_id`) — un solo `UPDATE`, directo.
2. Sección B.2 (RIF) — Paso 1 (chequeo) → Paso 2 (preview) → Paso 3 (`UPDATE`), **solo si el
   chequeo de colisiones del Paso 1 no devolvió filas**.

### Paso 6 — Registrar el permiso del Tablero Gerencial en Filament Shield

La página `GerenciaDashboard` (Fase 3) usa `HasPageShield` con un permiso propio
(`page_GerenciaDashboard`) que todavía no existe en la tabla `permissions` de producción — sin
este paso, nadie va a poder asignarlo a un rol gerencial desde el panel de Shield:

```bash
php artisan shield:generate
```

Después, desde el panel de Filament Shield (Roles), asignar `page_GerenciaDashboard` a los roles
que deban ver el Tablero Gerencial.

---

## Verificación post-despliegue

- [ ] `php artisan migrate:status` — confirmar que las 3 migraciones de la sección A figuran como
  `Ran`.
- [ ] Login normal (`/admin/login`) funciona, CAPTCHA/Turnstile sigue activo.
- [ ] Crear una empresa de prueba: RIF se autoconvierte a mayúsculas sin guion, rechaza formato
  inválido; teléfono aplica la máscara `+58-XXX-XXXXXXX` al tipear.
- [ ] Editar una empresa existente: teléfono con máscara, RIF sigue deshabilitado.
- [ ] Listado de Empresas: columna "Activo" refleja bien las empresas que tenían `status_id NULL`
  (deberían aparecer como activas tras el UPDATE del Paso 5.1).
- [ ] Buscar alguna empresa que antes tuviera RIF con guion — confirmar que ahora se ve sin
  guion/símbolos en el listado y al editar.
- [ ] Tablero Gerencial (`/admin/gerencia-dashboard` o la ruta que corresponda): los 15 widgets
  cargan, los filtros (Sector/Cámara/Estado) no se revierten solos a los ~5s.
- [ ] Botón "Descargar PDF" del Tablero Gerencial: el PDF descarga (no falla en silencio) y
  **los 14 gráficos aparecen a color** (si salen placeholders de texto, GD no está habilitado —
  volver al pre-requisito de la sección anterior).
- [ ] Reporte "Estatus de Perfiles": columna "Usuario principal" con datos correctos.
- [ ] Acciones masivas en Empresas: con un usuario `super_admin` ver
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

Las 3 migraciones tienen `down()` funcional (`php artisan migrate:rollback`) si se prefiere
revertir solo el esquema sin restaurar el backup completo — pero dado que hay UPDATEs manuales de
por medio (sección B), restaurar desde backup es más seguro que confiar solo en los rollbacks de
Laravel.

**Nota:** un `git push --force` a `main` es una acción destructiva sobre una rama compartida —
pedir confirmación explícita antes de ejecutarlo, no es algo para correr automáticamente ante el
primer error.

---

## Checklist final (para copiar/pegar al coordinar la ventana de despliegue)

```
Antes de la ventana:
[ ] Cliente aprobó explícitamente cada ítem 🟡/🔴 de la tabla de Fase del proyecto
[ ] Se resolvió el reporte de la máscara de teléfono (pull faltante vs. bug real)
[ ] GD confirmado habilitado en PHP de producción
[ ] Acceso a phpMyAdmin/SSH de producción confirmado

Durante la ventana:
[ ] Paso 1 — Backup de BD tomado y descargado
[ ] Paso 2 — Merge staging→main (fast-forward)
[ ] Paso 3 — Push a main
[ ] Paso 4 — Deploy vía cPanel, log revisado sin errores
[ ] Paso 5.1 — UPDATE status_id corrido
[ ] Paso 5.2 — SQL de RIF corrido (chequeo de colisiones → preview → update)
[ ] Paso 6 — shield:generate corrido + permiso asignado a roles gerenciales

Después de la ventana:
[ ] Checklist de "Verificación post-despliegue" completo
[ ] Cliente notificado de que producción está actualizada
```
