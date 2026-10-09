# CPV — Conocimiento operativo del proyecto

**Versión:** 1.0
**Fecha:** 2026-10-09
**Propósito:** permitir que un orquestador autónomo (o cualquier sesión nueva del agente de desarrollo)
adquiera el conocimiento técnico del proyecto sin releer el histórico completo de Issue #2 ni la
conversación de ChatGPT.

Este documento describe **qué existe y cómo funciona**.
Los accesos y su persistencia están en `docs/orquestador/ACCESS_BOOTSTRAP.md`.
El estado vivo y las tareas están en `docs/orquestador/SESSION_RESUME.md` y `current_task.md`.

> ## ⚠️ AVISO DE VISIBILIDAD
>
> **Los dos repositorios del proyecto son PÚBLICOS** (verificado el 2026-10-09 vía API de GitHub:
> `"visibility": "public"` en ambos). Por lo tanto este archivo, todo `docs/`, todo `audit/` y
> **todos los comentarios de Issue #2 son legibles por cualquiera en Internet**.
>
> Consecuencias que deben respetarse sin excepción:
> - aquí **no** va ningún secreto, contraseña, token, clave privada ni cadena de conexión completa;
> - los identificadores de infraestructura que ya están publicados en este repo se mantienen, pero
>   **no se añaden nuevos detalles** (usuarios de sistema, hosts de base de datos, rutas internas);
> - si se decide reducir esta exposición, ver `ACCESS_BOOTSTRAP.md` §7.

---

## 1. Qué es el sistema

Plataforma de **Perfil de Afiliados de la Cámara Petrolera de Venezuela (CPV)**: las empresas
afiliadas mantienen su perfil (datos, servicios, certificaciones, experiencia), y un **buscador
semántico** permite encontrar proveedores por lo que realmente hacen, no sólo por coincidencia literal
de texto.

El valor diferencial está en una **taxonomía gobernada** que traduce vocabulario real del sector
petrolero venezolano (*cabria*, *mechurrio*, *guaya fina*, *macolla*) a **categorías CPV** normalizadas.

Hay dos piezas de software, en dos repositorios:

| Pieza | Repo | Rol |
|---|---|---|
| Panel + perfil + gobierno de taxonomía | `estebanjvasquez/PerfilAfiliadosCPV` | Laravel 12 + Filament v3 |
| Buscador híbrido (motor de búsqueda) | `estebanjvasquez/perfilafiliados-mcp` | Cloudflare Worker, TypeScript |

**Dato clave de arquitectura:** la búsqueda **no** corre en Laravel. Vive en el Worker. Laravel
gobierna los datos y la taxonomía; el Worker los consulta y los expande. Un cambio de ranking no se
hace en Laravel.

---

## 2. Repositorios

### 2.1 `estebanjvasquez/PerfilAfiliadosCPV` (principal)

- Visibilidad: **pública**. Rama por defecto: `main`.
- **Rama de trabajo activa: `feature/upgrade-filament-v3`.** Es también la rama que despliega a staging.
- `main` está **fuera de alcance** (merge = GATE B).
- Hilo de gobierno: **Issue #2 — "CPV - Development Orchestration & Review"**.

Estructura relevante:

```
app/Console/Commands/          comandos artisan (taxonomía, crawler, embeddings, sync)
app/Filament/Resources/        pantallas del panel, incluida la revisión de taxonomía
docs/orquestador/              gobierno: SESSION_RESUME, AUTONOMOUS_DEV_LOOP, current_task, este archivo
docs/uat/                      paquete UAT cliente + guía del pasante (TASK-0008, cerrado)
docs/manual_usuario/           material de manual de usuario
audit/                         artefactos de auditoría + orchestrator_handoff.json
database/migrations/           esquema; subcarpeta pgsql/ para migraciones específicas de Postgres
docker/nginx/                  config de nginx para staging (bind-mounted, no va en la imagen)
.github/workflows/             deploy-contabo.yml
```

### 2.2 `estebanjvasquez/perfilafiliados-mcp` (Worker)

- Visibilidad: **pública**. Rama por defecto: `master`.
- `wrangler.toml`: `compatibility_flags = ["nodejs_compat"]` (postgres.js necesita sockets TCP crudos),
  binding `AI` (Workers AI) y binding `HYPERDRIVE`.
- Código fuente (`src/`): `index.ts`, `hybrid-search.ts`, `canonical-expansion.ts`, `debug-search.ts`,
  `empresa-tools.ts`, `intent-tools.ts`, `taxonomy-tools.ts`, `db.ts`.
- Suite de regresión congelada: `scripts/regression-suite.mjs` + `scripts/fixtures/regression-cases.json`.

---

## 3. Base de datos

- **PostgreSQL gestionado por Supabase.** Project id: `mrquhxwvcrbwbuqafjee`.
- Conexión Laravel: nombre `pgsql` en `config/database.php`, con variables **dedicadas** `DB_PGSQL_*`
  (deliberadamente **no** las `DB_HOST`/`DB_DATABASE` genéricas, que quedaron del MySQL legado):
  `DB_PGSQL_HOST`, `DB_PGSQL_PORT`, `DB_PGSQL_DATABASE`, `DB_PGSQL_USERNAME`, `DB_PGSQL_PASSWORD`,
  `DB_PGSQL_SSLMODE` (default `require`), opcional `DB_PGSQL_URL`.
- El acceso desde el Worker **no** es directo: pasa por **Cloudflare Hyperdrive**, que poolea
  conexiones desde el edge. Sin Hyperdrive, Workers no sostiene bien TCP contra el pooler de Supabase.
- `.env.example` del repo está **obsoleto** (describe MySQL). No usarlo como referencia de la conexión
  real; la referencia es `config/database.php`.

> ### 🔴 RIESGO ESTRUCTURAL MÁS IMPORTANTE DEL PROYECTO
>
> **La misma base de datos Supabase es usada por el entorno de desarrollo local y por staging.**
> No hay una base local separada.
>
> Por lo tanto: cualquier `artisan` que escriba, ejecutado desde una máquina de desarrollo, **muta
> datos compartidos reales**. De ahí vienen casi todas las restricciones de gobierno de este proyecto,
> y de ahí viene que `BATCH_EXECUTABLE_ENVIRONMENTS` esté limitado a `staging`: para que un entorno
> `local` no pueda ejecutar una publicación de taxonomía aunque técnicamente alcance los datos.

### 3.1 Tablas de taxonomía

Núcleo (ver `database/migrations/*taxonomy*`):

- `taxonomy_terms` — términos; incluye columnas de procedencia y `mapping_review_status`.
- `taxonomy_term_cpv_relations` — **TERM→CPV**, la relación que alimenta la búsqueda. Estados:
  `approved`, `candidate`, `needs_review`, `deprecated`. Lleva evidencia.
- `taxonomy_canonical_concepts` — conceptos canónicos bilingües (`canonical_name_es` / `_en`), con
  tipo y dominio.
- `taxonomy_term_concepts` — **TERM→CONCEPT**.
- `taxonomy_concept_relations` + `taxonomy_concept_relation_types` — relaciones entre conceptos.
  **No son leídas por el Worker** (dato verificado: `taxonomy_concept_relations` aparece 0 veces en
  `src/*.ts`).
- `taxonomy_candidate_concept_links` — candidatos a vincular, con `taxonomy_state_fingerprint`.
- `taxonomy_reviewed_proposals` — propuestas revisadas inmutables (contrato Fase C2).
- `taxonomy_audit_log` — bitácora; tiene columnas de actor y de contexto de ejecución.
- `taxonomy_term_embeddings`, `taxonomy_intent_markers`, `taxonomy_concept_types`.

### 3.2 Estado de datos aceptado (checkpoint 2026-10-08)

TERM→CPV: total **9.749** = 212 `approved` + 17 `candidate` + 9.282 `needs_review` + 238 `deprecated`.
Propuestas revisadas: 12 `APPLIED`, 3 `SUPERSEDED`, 0 `PENDING_APPLY`, 0 `ABORTED`.
Conceptos canónicos: 82. TERM→CONCEPT: 145. Último `taxonomy_audit_log.id`: **5260**.

Tras una pausa larga, confirmar estos valores con lectura de sólo consulta antes de asumir continuidad.

---

## 4. Contrato de gobierno de taxonomía (Fase C2)

Implementado en `ReviewedProposalService`. Es el mecanismo que impide que una propuesta se publique
sin autorización humana trazable.

Ciclo de vida: **`freeze()` → `confirm()` → `apply()` / `applyBatch()`**

Invariantes que no se debilitan nunca:

- `payload_fingerprint` — congela el contenido de la propuesta. No se recalcula para hacer pasar una fila.
- `taxonomy_state_fingerprint` — congela el estado del grafo en el momento de la revisión. Si el grafo
  cambió, la propuesta queda obsoleta (`STALE_TAXONOMY_STATE`) y debe revisarse de nuevo.
- La línea base de taxonomía la calcula `CanonicalConceptBuilderService::dryRunInputFingerprint()`.
- **Por qué el lote corre en UNA transacción contra UNA línea base:** la primera escritura que cambia
  el grafo invalida por obsolescencia al resto del conjunto revisado. Aplicar en secuencia rompería el
  conjunto; aplicar atómicamente lo preserva.
- `BATCH_EXECUTABLE_ENVIRONMENTS = ['staging']`; `production` está prohibido para ejecución de lote.
- `BATCH_MANIFEST_REQUIRED` en cualquier entorno operativo; el manifiesto liga ids, decisiones,
  fingerprints, unidades de ejecución y forma de la cola bajo un `manifest_fingerprint` determinista.
- `authorizationFingerprintBlocker()` es el **único** validador de autorización, llamado por el CLI
  antes del banner destructivo y de nuevo por el servicio antes de abrir la transacción.
- `--id` está **prohibido** junto con `--execute` (evita que un manifiesto completo ejecute un subconjunto).
- `--authorized-by` debe citar un comentario real de Issue #2; queda escrito de forma permanente en las
  filas y en el log de auditoría.

### 4.1 Comandos artisan de taxonomía

```
taxonomy:preflight-reviewed-proposals        # sólo lectura, snapshot de aplicabilidad
taxonomy:generate-reviewed-batch-manifest    # genera manifiesto + fingerprint
taxonomy:apply-reviewed-proposal             # unitario
taxonomy:apply-reviewed-proposal-batch       # lote atómico; modo por defecto = preflight
taxonomy:supersede-stale-reviewed-proposal
```

(Los nombres de clase están en `app/Console/Commands/`; `php artisan list taxonomy` da la firma exacta.)

Resto del inventario de comandos: importación de taxonomía y diccionarios, auto-mapeo
(`AutoMapTaxonomyTerms`), construcción de conceptos canónicos, embeddings (términos y servicios),
crawler (`CrawlCompanyWebsite`, `CrawlTaxonomySource`), homologación, y sincronizaciones de empresa.

### 4.2 UI real de revisión (verificada en código)

`app/Filament/Resources/Concerns/ManagesTaxonomyRelationReview.php` expone **exactamente dos** acciones
de decisión:

- **`Aprobar`** — requiere permiso `taxonomy_publish`. **Publica la relación a la búsqueda en vivo.**
- **`Rechazar`** — requiere permiso `taxonomy_edit_relations`.

Ambas exigen confirmación y un **`Motivo` obligatorio** que se escribe en `taxonomy_audit_log`.
Existen acciones masivas `Aprobar seleccionadas` / `Rechazar seleccionadas`.

No existen botones para `NEEDS_CONTEXT`, `ESCALATE` ni `POSSIBLE_NEW_CATEGORY`: esas decisiones se
registran sólo en planilla. Usar `Editar` para cambiar el estado a mano **evita** la confirmación y el
motivo obligatorio, y deja la auditoría incompleta.

---

## 5. Buscador (Worker) — cómo funciona realmente

`canonical-expansion.ts` implementa expansión por niveles **L0–L3**. El hecho operativo más importante,
porque explica resultados que de otro modo parecen fallos:

> La rama de **herencia por concepto** exige `taxonomy_term_cpv_relations.status = 'approved'` en un
> término **hermano** dentro del mismo concepto canónico.

Consecuencia medida en vivo (TASK-0007): publicar mappings TERM→CONCEPT **no** añadió ninguna expansión
CPV, porque las relaciones CPV de esos términos seguían en `candidate`/`needs_review`. Las consultas
`pipeline`, `refinery` y `refinería` devuelven `canonical_concepts: []` y `cpv_relations: []` y, aun
así, devuelven candidatos por `LITERAL_MATCH` y `SEMANTIC_INFERENCE`: la búsqueda funciona, la capa de
taxonomía simplemente aún no aporta para esos términos.

**No "arreglar" esto aprobando relaciones en masa** (GATE C) ni tocando pesos/ranking para mejorar una
consulta aislada.

### 5.1 Endpoint de depuración

`POST /debug-search` está protegido por el secreto `DEBUG_TOKEN` del Worker. Por diseño el token **no
va embebido** en la página servida: el administrador escribe `/debug-on <key>` y el valor vive en el
`sessionStorage` **de ese navegador**. Por eso el único plaintext está en el almacén de secretos de
Cloudflare, que no es recuperable por API.

### 5.2 Regresión congelada

- Suite: `perfilafiliados-mcp/scripts/regression-suite.mjs`
- Casos: `scripts/fixtures/regression-cases.json` — **32 consultas**
- Comparación: `diffSnapshot()` sobre **nueve campos** estructurales (incluye `top_empresa_ids`)
- Línea base: `audit/regression_baseline_2026-09-23.json` en el repo principal — **NO TOCAR**
- Un resultado sin cambios son 32 consultas × 9 campos = **288 campos, 0 diffs**
- Requiere `--token` (el `DEBUG_TOKEN`)

**Regla permanente:** toda corrección de ranking o búsqueda debe cerrar con una nueva corrida de
regresión contra esta línea base, guardada como artefacto **separado**.

---

## 6. Staging (Contabo + Cloudflare)

- App: `https://pruebas.camarapetrolera.app`
- Página de UAT del buscador: `https://pruebas.camarapetrolera.app/cira-test/`
- Worker: `https://perfilafiliados-mcp.sisteg.workers.dev`
- VPS: `66.94.121.98`, ruta de despliegue `/opt/perfilafiliados`
- Hyperdrive: config `perfilafiliados-taxonomy`, id `f16a1ab0a9514504b80bd14138699d4c`
  - **No tocar** la config no relacionada `talento-cpv-db`.

### 6.1 Topología Docker

`docker-compose.yml` levanta sólo la app (la base es externa):

- servicio `app` (build local desde `Dockerfile`) y servicio `nginx` (`nginx:stable-alpine`)
- nginx publica en `127.0.0.1:8080`; **`cloudflared` corre como servicio systemd propio en el host**,
  no en compose, para sobrevivir a reinicios del stack
- **el código de la app va horneado en la imagen**; los únicos bind mounts son
  `./storage`, `./bootstrap/cache` y `./public`
- `./public` se monta a propósito: `php artisan filament:assets` corre en un contenedor efímero
  (`docker compose run --rm`) y sin el mount escribiría los assets en el filesystem del contenedor,
  que se destruye al salir → panel de Filament sin estilos
- `docker/nginx` se monta como **directorio**, no como archivo: un bind mount de archivo suelto queda
  pegado al inodo viejo y nginx seguiría sirviendo la config anterior para siempre
- la imagen se construye con `composer install --no-dev` → **no hay phpunit en staging**

### 6.2 Despliegue

`.github/workflows/deploy-contabo.yml`:

- Dispara en **push a `feature/upgrade-filament-v3`**, más `workflow_dispatch`.
- `paths-ignore: ['docs/**', 'audit/**', '**.md']`.
  **Semántica todo-o-nada sobre el push completo:** se salta sólo si *todos* los archivos del push
  matchean. Un commit mixto docs+código **sí** despliega. Conservador por diseño.
- Secretos de repositorio usados: `CONTABO_HOST`, `CONTABO_USER`, `CONTABO_SSH_KEY`.
- El script remoto hace: `git fetch` + `git reset --hard origin/<rama>`, `chown -R 33:33 storage
  bootstrap/cache`, `docker compose build app`, **un solo** `docker compose run` encadenando
  `package:discover`, `db:skip-mysql-only-view-migrations`, `migrate --force`, `filament:assets`,
  `config:cache`, `view:cache`, luego `up -d`, `nginx -s reload` y `artisan up`.
- `command_timeout: 20m` porque el disco del VPS tiene ~25% de I/O wait: cada `docker compose run`
  arranca un contenedor nuevo y ese boot de Laravel tarda ~28s (de ahí el encadenado en un solo `run`).
- El `chown 33:33` es obligatorio: el `git reset --hard` corre como root y deja `storage` root:root,
  y PHP-FPM corre como `www-data` (uid 33) → sin eso, 500 genérico en cada request.

### 6.3 n8n / CIRA

`public/cira-test/index.html` **no** es un buscador con caja y lista de resultados: es un **chat**
("CIRA - Asistente CPV") que postea a un **webhook de n8n**, con botones de prompt rápido y un botón
"Nueva conversación".

Dos consecuencias operativas:
1. El chat **arrastra contexto**: hay que reiniciar la conversación antes de cada consulta de prueba.
2. Si el flujo de n8n está detenido, **todas** las consultas fallan igual y una UAT entera parecería un
   fallo catastrófico del buscador. **Verificar que el workflow esté activo antes de cada sesión de UAT.**

---

## 7. Limitaciones conocidas del entorno de desarrollo (Windows)

Esto ahorra horas a una sesión nueva:

- **`php.exe` está bloqueado por Application Control** en esta máquina → PHPUnit no es ejecutable
  localmente. Y en staging no hay phpunit (`--no-dev`). Es un límite declarado, no una omisión.
- **Falta `ext-intl`** → las páginas de Filament dan 500 en tests.
- **PowerShell 5.1 no conserva estado entre llamadas.** Hay que fijar `PATH` en cada comando
  (`bootstrap_access.ps1` lo resuelve, ver `ACCESS_BOOTSTRAP.md`).
- **PowerShell elimina las comillas internas** al pasar argumentos a ejecutables nativos. Esto ya
  provocó un rechazo real de APPLY: `--authorized-by` llegó sin dígitos porque `sh` remoto leyó
  `#2 comment ...` como comentario. Para scripts remotos: **pipe por stdin**
  (`$script | ssh ... "bash -s"`) con here-string de comillas simples.
- **Un script pipeado a `ssh ... bash -s` recibe un BOM UTF-8** que rompe la línea 1
  (`bash: line 1: ﻿set: command not found`, y `set -e` nunca se activa). Usar una primera línea
  descartable y rutas absolutas.
- **`gh` CLI no está instalado.** Los comentarios de Issue #2 se leen sin autenticación vía
  `curl.exe` + API REST (el repo es público).
- `route:list --columns` **no existe** en esta versión de Laravel.
- Hay worktrees de git en uso para trabajo paralelo: `C:\Proyectos\GitHub\wt-task-0010a` y `wt-task-0010b`.

---

## 8. Reglas de gobierno permanentes

Vigentes hasta nueva autorización explícita del propietario:

- no merge a `main`; no deploy a producción;
- no otro APPLY/replay de taxonomía; no mutación adicional de taxonomía;
- no aprobación masiva de TERM→CPV;
- no cambios de ranking/pesos para "mejorar" una consulta aislada;
- **ningún cambio ni rotación de secretos**;
- **no persistir ningún token, contraseña o clave en repo, docs o logs**; no imprimir, ecoar,
  commitear ni exponer un valor de token; no pedir que se pegue uno en el chat;
- no intentar leer un secreto del Worker por una API que sólo expone metadatos;
- no conceder al pasante permiso operativo de publicación ni acceso directo a la base;
- no tocar `audit/regression_baseline_2026-09-23.json`;
- no tocar la config Hyperdrive `talento-cpv-db`;
- no editar los 12 payloads/fingerprints congelados; no tocar las propuestas #420/#421/#422;
- no debilitar `confirm()`, no sobreescribir `taxonomy_state_fingerprint`, no recalcular
  `payload_fingerprint` para hacer pasar filas, no crear flag de force-stale-apply, no eludir
  `STALE_TAXONOMY_STATE`;
- no migraciones destructivas; no borrar ni recrear historia;
- **no adivinar decisiones ambiguas — reportarlas.**

El protocolo de autonomía y los gates A–F están en `docs/orquestador/AUTONOMOUS_DEV_LOOP.md`.

---

## 9. Orden de lectura al reanudar

1. `docs/orquestador/SESSION_RESUME.md` — estado y punto de reanudación maestro
2. `docs/orquestador/AUTONOMOUS_DEV_LOOP.md` — protocolo del loop y gates
3. `docs/orquestador/current_task.md` — encabezado con la tarea activa
4. `audit/orchestrator_handoff.json` — campos `current_*` y `review_*`
5. último comentario del orquestador en Issue #2
6. **este archivo** — cuando haga falta conocimiento técnico, no estado
7. `docs/orquestador/ACCESS_BOOTSTRAP.md` — cuando haga falta un acceso

Si algo aquí contradice GitHub o la base viva, **prevalece la evidencia viva** y la discrepancia debe
registrarse antes de seguir.
