# Task — PerfilAfiliadosCPV

## Header

- **Project:** PerfilAfiliadosCPV (+ repo hermano `perfilafiliados-mcp`, Cloudflare Worker)
- **Reconstruction date:** 2026-09-22 (contenido ampliado 2026-09-23)
- **Active development branch:** `feature/upgrade-filament-v3`
- **Production branch:** `main`
- **Current HEADs (2026-09-23):**
  - `main`: `73853cd` (2026-09-09)
  - `staging`: mergeada a `main`, sin trabajo propio
  - `feature/upgrade-filament-v3`: `46fb0a5` (más los commits de esta jornada, ver sección 0)
- **Reconstruction methodology:** el `task.md` original y `implementation_plan.md` se perdieron
  con el equipo de desarrollo anterior — ambos eran local-only (`.gitignore`) hasta el
  2026-09-23. Este documento se reconstruyó por arqueología de `git log --all` (no solo HEAD) en
  ambos repos, lectura de código línea por línea, inspección en vivo de Supabase (conteos reales,
  no docblocks), e inspección en vivo de la infraestructura desplegada (SSH al servidor de
  staging). **A partir de esta fecha, `task.md` e `implementation_plan.md` se versionan** — ver
  `docs/README.md` para la nota de cambio de convención.

## Vocabulario de estado usado en este documento

`DONE` · `VERIFIED` · `IMPLEMENTED` · `DEPLOYED` · `NOT DEPLOYED` · `PARTIAL` · `PENDING` ·
`BLOCKED` · `DECISION REQUIRED` · `UNKNOWN`

Código existente no implica `DONE`. Ver la nota de cada tarea para la evidencia concreta detrás
del estado.

---

# 0 — Current baseline

**Verificado 2026-09-22/23, en vivo (no de memoria ni de docblocks):**

- `taxonomy_term_cpv_relations`: 9.749 filas (protegidas, `TaxonomyAutoMapper` nunca las toca)
- `taxonomy_canonical_concepts`: 79 · `taxonomy_term_concepts`: 142
- `taxonomy_concept_relations`: 0 · `taxonomy_candidate_concept_links`: 0 (Phase 3, sin poblar)
- `taxonomy_concept_relation_types`: 5 (`RELATED_TO`, `PART_OF`↔`HAS_PART`, `SUPERSEDES`↔`SUPERSEDED_BY`) — catálogo SÍ seedeado
- `taxonomy_intent_types`: 9 (MAINTENANCE, RENTAL, INSPECTION, MANUFACTURING, INSTALLATION, REPAIR, SUPPLY, TRANSPORT, ENGINEERING) — catálogo SÍ seedeado
- `relation_type='concept_inherited'`: 0 filas vivas (feature dormida, no un bug)
- Servidor de staging (Contabo, `66.94.121.98`, `pruebas.camarapetrolera.app`): desplegado en
  `feature/upgrade-filament-v3` @ `46fb0a5`, Laravel 12.69.0, Filament 3.3.55.0, Livewire 3.8.7.0,
  `DB_CONNECTION=pgsql` activo, **0 migraciones pendientes** — el despliegue de
  `docs/migracion.md` (fechado 2026-09-02, que dejaba esto como pregunta abierta) **está
  completo**.
- Detalle completo de auditorías: `audit/pre_phase3_corrections.md`,
  `audit/phase3_completion_audit.md`, `audit/staging_deployment_status.md`,
  `audit/regression_2026-09-23.md`.

---

# 1 — Infrastructure recovery

**Status: DONE / VERIFIED**

Incidente real detectado y resuelto el 2026-09-22 en el VPS de Contabo
(`66.94.121.98`, `vmi3554091.contaboserver.net`), que aloja 3 sitios vía Docker + túnel de
Cloudflare (`perfilafiliados-pruebas`): `pruebas.camarapetrolera.app` (Laravel/CIRA),
`camarapetrolera.org` y `barriles.camarapetrolera.org` (WordPress).

**Causa raíz (3 fallas independientes, encadenadas):**
1. `net.ipv4.ip_forward` nunca estaba persistido en `/etc/sysctl.d/` → al reiniciar el servidor
   (para recuperar acceso SSH, ver sección 2), volvió a `0` → Docker no pudo levantar su red
   `bridge` por defecto → los 3 stacks quedaron `Exited`.
2. `/opt/perfilafiliados/.env` tenía la contraseña vieja de Supabase (independiente del punto 1).
3. La app Laravel estaba en modo mantenimiento (`artisan down`) de un despliegue previo nunca
   revertido (independiente de los otros 2).

**Resolución (verificada desde afuera, no solo `curl` local):**
- `net.ipv4.ip_forward=1` persistido en `/etc/sysctl.d/99-docker-forward.conf`.
- Los 3 stacks Docker recreados limpios (`docker compose down --remove-orphans` + `up -d`).
- `.env` de `/opt/perfilafiliados` actualizado con la contraseña nueva de Supabase.
- `php artisan up` para sacar la app de mantenimiento.
- Bug de nginx encontrado en el camino: `/cira-test/` (ruta pelada, sin `index.html` explícito)
  devolvía 403 porque la directiva `index` de nginx solo listaba `index.php` — corregido
  (`index index.html index.php;`), commit `46fb0a5`, aplicado también en caliente en el servidor.
- Verificado 200 desde afuera: `pruebas.camarapetrolera.app`, `pruebas.camarapetrolera.app/cira-test/`,
  `camarapetrolera.org`, `barriles.camarapetrolera.org`.

**Acceptance:** [x] los 3 sitios responden 200 desde afuera [x] causa raíz documentada
[x] fix de nginx commiteado y pusheado.

**Remaining work:** ninguno conocido. Si el servidor se reinicia de nuevo, `ip_forward` ya está
persistido — no debería repetirse ese síntoma puntual.

---

# 2 — Credentials/security recovery

**Status: DONE, con 2 items de seguimiento (PENDING)**

- **Contraseña de Postgres/Supabase** (proyecto `mrquhxwvcrbwbuqafjee`) rotada el 2026-09-22 a
  pedido del usuario. Propagada a 3 lugares: `.env` local de esta máquina de desarrollo, binding
  Hyperdrive del Worker (`perfilafiliados-taxonomy`, id `f16a1ab0a9514504b80bd14138699d4c`, vía API
  de Cloudflare), y `.env` de `/opt/perfilafiliados` en el servidor de Contabo. **No** se tocó la
  otra config Hyperdrive de la cuenta (`talento-cpv-db`, proyecto Supabase no relacionado).
- **Acceso SSH al VPS de Contabo recuperado.** La clave `.ppk` original de PuTTY se perdió con el
  equipo anterior. Se generó una clave ed25519 nueva
  (`C:\Users\esteb\.ssh\pruebas_camarapetrolera_ed25519`, sin passphrase) y se inyectó vía consola
  VNC de Contabo (Customer Panel → el VPS → VNC → agregar SSH key). Conectar con:
  `ssh -i C:\Users\esteb\.ssh\pruebas_camarapetrolera_ed25519 root@66.94.121.98`.
- **Autenticación de GitHub persistente configurada.** Un Personal Access Token fine-grained (con
  acceso a todos los repos del usuario, generado por el usuario) quedó guardado en el credential
  manager de git/Windows vía `git credential approve` — `git push`/`pull` funcionan sin prompt
  desde esta máquina en cualquier repo, en adelante.

**PENDING (seguimiento de seguridad):**
- [ ] Confirmar que el token de API de Cloudflare usado hoy (scope Hyperdrive + Tunnels, creado
      específicamente para las tareas de esta sesión) fue revocado por el usuario — no confirmado.
- [ ] Revisar periódicamente la fecha de expiración del PAT de GitHub guardado y rotarlo antes de
      que venza.

---

# 3 — Local development environment

**Status: DONE**

Esta máquina de desarrollo no tenía ninguna herramienta instalada. Se instalaron:
- PHP 8.2 (winget), con `intl`/`pdo_pgsql`/`pgsql`/`gd`/`zip`/`curl`/`mbstring`/`fileinfo`
  habilitadas a mano en `php.ini` (el `extension_dir` default de winget apunta mal — hay que
  corregirlo a la ruta real de instalación).
- Composer, vía `composer.phar` suelto en `C:\ProgramData\ComposerPhar\` (el instalador `.exe`
  oficial es interactivo/GUI y no sirve en este entorno no interactivo).
- Node.js LTS + Wrangler 4.x (global, vía npm).
- git: no hay instalación standalone en PATH — se usa el `git.exe` embebido de GitHub Desktop
  (`C:\Users\esteb\AppData\Local\GitHubDesktop\app-3.6.6\resources\app\git\cmd\git.exe`).
- `.env` local creado desde cero, apuntando a `DB_CONNECTION=pgsql` contra Supabase (no mysql
  local) — necesario para poder correr `artisan tinker`/`db:show` y verificar datos reales.

**Acceptance:** [x] `composer install` corre limpio [x] conexión a Supabase verificada desde esta
máquina [x] git push/pull funcionan sin prompt.

---

# 4 — Filament v3/PostgreSQL migration

**Status: DEPLOYED (staging) / NOT DEPLOYED (producción) / DECISION REQUIRED**

Ver `audit/staging_deployment_status.md` para el detalle completo verificado en vivo.

- Staging (`pruebas.camarapetrolera.app`): **DEPLOYED y VERIFIED** — Laravel 12.69.0, Filament
  3.3.55.0, Livewire 3.8.7.0, `pgsql` activo, 0 migraciones pendientes. Esto resuelve la pregunta
  abierta que dejaba `docs/migracion.md` (2026-09-02).
- Producción (`main`): sigue en Filament v2 + MySQL. **NOT DEPLOYED.**
- Checklist funcional completo de `docs/migracion.md` (login/Turnstile, alta de empresa, exports,
  perfil de Breezy) — **NOT VERIFIED** en esta pasada, solo se confirmaron versiones/conexión/
  migraciones.
- `shield:generate` contra pgsql — **VERIFIED (2026-09-23): la brecha sigue sin corregirse.**
  455 permisos totales en staging, `super_admin` tiene los 455 que existen, pero
  `page_completion_view`/`page_sectors_view`/`page_gerencia_dashboard` (3 páginas legacy, las
  clases siguen en el código) **siguen sin permiso generado** — invisibles en el menú incluso para
  `super_admin`. No se corrió `shield:generate` para corregirlo (aditivo y de bajo riesgo, pero se
  dejó pendiente de autorización explícita en vez de ejecutarlo de oficio). Ver
  `audit/phase3_runtime_validation.md` sección 7.

**DECISION REQUIRED (del cliente/negocio, no técnica):**
- [ ] ¿Producción pasa a pgsql/Supabase en el mismo despliegue que el upgrade de Filament v3, o se
      separan en dos eventos?
- [ ] ¿Se fusiona `feature/upgrade-filament-v3` con el trabajo de
      `feature/supplhi-postgres-buscador` antes de ir a producción, o una absorbe a la otra?

**Remaining work:** correr el checklist funcional completo en staging; actualizar `.cpanel.yml`
para producción (agregar `php artisan filament:assets` explícito); confirmar PHP 8.2+/`intl` en
producción; correr `shield:generate` en producción; backup antes de cualquier despliegue real.

---

# 5 — Taxonomy/CIRA Pre-Phase-3

**Status: DONE / VERIFIED**

Ver `audit/pre_phase3_corrections.md` (commit `386f3d2`, ya en `feature/upgrade-filament-v3`).
Un spec de correcciones "pre-Phase-3" fue auditado contra el código real de ambos repos y contra
la base de datos en vivo — **todas las correcciones que pedía ya estaban implementadas** en
commits previos a esta sesión (`cb6d2e8`, `f5f0d82`, `dfda1ad`, `b14401c`, `75d1cd7`). No se aplicó
ninguna corrección nueva porque no hacía falta.

**Acceptance:** [x] las 6 causas raíz del spec están corregidas y verificadas [x] `concept_inherited`
confirmado en 0 filas vivas, documentado, no activado [x] `taxonomy_candidate_terms` confirmado
como cola única (sin duplicación) [x] conteo de 9.727+ relaciones protegido, sin pérdida.

---

# 6 — Phase 3 Canonical Concept Builder

**Status: IMPLEMENTED (schema + motor de scoring) / NOT POPULATED (datos) / NOT STARTED (write-mode)**

Ver `audit/phase3_completion_audit.md` para el detalle completo, componente por componente.
Resumen:

- Se construyó en 3 tandas separadas (2026-09-18/19, commits `8cdcc19` → `b14401c` → `75d1cd7`),
  sin ningún revert.
- Catálogos de gobernanza **sí están seedeados**: 5 tipos de relación concepto↔concepto
  (`taxonomy_concept_relation_types`), 9 tipos de intención (`taxonomy_intent_types`).
- Tablas de instancias/datos reales están en 0: `taxonomy_concept_relations`,
  `taxonomy_candidate_concept_links`, `taxonomy_candidate_concept_relations`,
  `taxonomy_term_semantic_intents`, `taxonomy_term_semantic_analyses`.
- `dryRun()` (`CanonicalConceptBuilderService.php`) es real y funcional (nunca escribe), pero le
  faltan 2 de 8 campos esperados para una revisión humana completa: relación concepto↔concepto
  propuesta, y empresas afectadas predichas.
- `--apply` (modo escritura) **no existe como código** — es un `return self::FAILURE` sin ninguna
  rama de escritura detrás, no una versión parcial bloqueada.

**Este NO es un bug.** Es infraestructura desplegada deliberadamente sin poblar (el propio mensaje
del commit `b14401c` lo dice explícitamente: "Cero población... verificado con conteos
antes/después").

---

# 7 — Phase 3 safeguards

**Status: NOT STARTED (para `--apply`) / DONE (para el camino manual)**

El único camino de escritura real y funcional hoy es manual, vía UI
(`CandidateConceptApprovalService`, extraído en `75d1cd7`): transacción + `lockForUpdate()` +
idempotencia + audit log (`TaxonomyAuditLogger`) — completo y testeado
(`CandidateConceptApprovalServiceTest.php`). Pero opera sobre una cola vacía.

Para `--apply`, ninguna de las siguientes propiedades existe (no hay código de escritura al que
evaluárselas): transacciones, idempotencia, protección de duplicados, rollback, audit logging,
provenance, versionado. Deben diseñarse e implementarse desde cero antes de habilitar cualquier
escritura masiva.

**Gap adicional encontrado:** el camino "proponer concepto nuevo" (`suggested_concept_id === null`)
en `CandidateConceptApprovalService::approve()` retorna `NOT_SUPPORTED_PROPOSE_NEW_CONCEPT` — no
tiene implementación de aprobación todavía, aunque el esquema lo permite.

---

# 8 — concept_inherited audit

**Status: DONE / VERIFIED — clasificado como DORMANT FEATURE**

`TaxonomyAutoMapper::conceptInheritedMatch()` es la 2ª de 4 señales en un pipeline de prioridad
estricta (no ensemble) — la 1ª señal (`exactCategoryLabelMatch`, confidence 0.97) evidentemente
resuelve casi todos los casos antes de llegar a esta, lo que explica por qué tiene 0 filas vivas
pese a ser código alcanzable y correcto. No fue reemplazada por nada de Phase 3 (opera sobre la
capa TAXV3-2, independiente). No se activó ni se backfilleó nada.

**Acceptance:** [x] clasificación documentada con evidencia [x] no se tocó código ni datos.

---

# 9 — MCP regression suite

**Status: BLOCKED — APPLICATION AUTH TOKEN REQUIRED (causa exacta confirmada 2026-09-23)**

Ver `audit/regression_2026-09-23.md` (+ adenda) y `audit/phase3_runtime_validation.md`.
`perfilafiliados-mcp/scripts/regression-suite.mjs` necesita `--url` (se tiene) y `--token` =
`DEBUG_TOKEN`. **Se investigó antes de pedir un token de Cloudflare nuevo**: ni `DEBUG_TOKEN` ni
`MCP_TOKEN`/`MCP_EMBED_TOKEN` existen en el `.env` del servidor de staging (verificado, solo
existencia de clave) — la integración MCP↔Laravel que documenta `perfilafiliados-mcp/README.md`
nunca se configuró en ningún entorno accesible. `DEBUG_TOKEN` además está diseñado para nunca vivir
en un archivo (se tipea a mano). Esto **no** es un problema de permisos de Cloudflare — ningún
scope de token de Cloudflare permite leer el valor de un secreto de Worker ya seteado (por diseño
de la plataforma), solo rotarlo.

**Next action:** pedir a Esteban directamente el valor de `DEBUG_TOKEN` (si lo tiene guardado en
algún gestor de notas/contraseñas personal), o su autorización explícita para generar uno **nuevo**
vía `wrangler secret put` (esto sí requeriría un token de Cloudflare `Workers Scripts:Edit`, recién
en ese momento, e invalidaría cualquier valor anterior).

---

# 10 — MCP live authenticated verification

**Status: PARTIAL — health check VERIFIED, endpoints autenticados BLOCKED (misma causa que sección 9)**

- `GET https://perfilafiliados-mcp.sisteg.workers.dev/` → `200 {"ok":true,"service":"perfilafiliados-mcp"}` — **VERIFIED**.
- `/mcp` (necesita `MCP_TOKEN`) y `/debug-search` (necesita `DEBUG_TOKEN`) — **BLOCKED — APPLICATION
  AUTH TOKEN REQUIRED**, ver sección 9.

---

# 11 — Hybrid search target architecture

**Status: PARTIAL — la mayoría de las señales de retrieval existen, RRF/re-ranking implementado, Phase 3 (grafo de conceptos) es el eslabón que falta poblar**

Arquitectura objetivo (referencia, no reimplementar):

```
QUERY → Query Understanding → Canonical Concept Resolver
  → [Taxonomy | Lexical | Vector] → Evidence + Crawler
  → Candidate Union → RRF → Re-ranking → Company + Evidence
```

Estado real verificado (`perfilafiliados-mcp/src/hybrid-search.ts`): 10 listas de evidencia con
pesos RRF distintos, `DIRECT_EVIDENCE_LISTS` unificada, `classifyEvidence()` en 5 niveles de
fuerza, deduplicación real por `empresa_id`. El vector no es la fuente de verdad (peso más bajo
del conjunto, `canonical_related`: 0.4) — coincide con el principio del spec de que "vectors are a
retrieval signal, not the source of truth". El "Crawler" del diagrama **no existe todavía** (ver
sección 12).

---

# 12 — Crawler

**Status: NOT STARTED — planeado, no es tarea inmediata**

Existe infraestructura de crawler para **términos** (TAXV2-12, `taxonomy_candidate_terms`,
0 filas hoy) pero no un crawler de **contenido web de empresas** que alimente evidencia de
capacidad (el flujo `WEB CONTENT → CAPABILITY EVIDENCE → TERM/CONCEPT RESOLUTION` del diagrama
objetivo). No implementar hasta que Phase 3 (sección 6-7) tenga un camino de escritura real —
un crawler que alimente un pipeline sin `--apply` funcional no tendría a dónde escribir.

**Dependencia:** sección 7 (safeguards de escritura) debe resolverse antes de diseñar ingestión de
crawler hacia Phase 3.

---

# 13 — Missing docs/taxonomia documentation

**Status: UNKNOWN (ubicación real) / DONE (inventario de lo referenciado)**

Confirmado: `docs/taxonomia/` existió/existe localmente en algún checkout (está en `.gitignore:24`
como `/docs/taxonomia/`) pero nunca se subió a git en ningún repo/rama
(`git log --all -- docs/taxonomia` vacío). Inventario completo de archivos referenciados por el
código (qué archivo, dónde se cita, para qué sirve) — ver el reporte de arqueología de esta sesión,
resumido en `audit/phase3_completion_audit.md`. Los más citados:
`plan_mcp_cira.md` (plan maestro de toda la iniciativa MCP/CIRA),
`INSTRUCCIONES_TAXONOMIA_CPV_CRAWLER_ADMIN_V2.md`, `MIGRACION_TAXONOMIA_CPV_V2_A_V3.md`,
`intent_eval_cases.md`, más varios JSON/XLSX/PDF de datos e insumos.

**Next action:** preguntarle a Esteban dónde vive esto ahora (sospecha fundada: Notion, dado que
el proyecto ya usa skills `/cerrar-proyecto`/`/retomar-proyecto` con sincronización a Notion). **No
recrear el contenido original** — si se reconstruye algo, etiquetarlo explícitamente como
`RECONSTRUIDO DESDE CÓDIGO/BASE DE DATOS/AUDITORÍAS`.

---

# 14 — Global safeguards

Vigentes para cualquier trabajo futuro sobre taxonomía/Phase 3 (resumen operativo — la lista
completa de 25 ítems vive en el spec que motivó esta auditoría, conservada en el historial de
conversación de la sesión que la escribió):

- No migración destructiva de taxonomía. No `DELETE`-all/rebuild-all.
- No hardcoding específico de query (los casos de regresión como "cabrias"/"mechurrio" son
  fixtures de test, nunca lógica de negocio).
- Contar relaciones antes/después de cualquier escritura futura sobre taxonomía — no asumir que
  9.749 es una cifra fija, es una línea base de regresión, no una regla de esquema.
- No mergear `feature/upgrade-filament-v3` a `main` sin decisión explícita del cliente (sección 4).
- No poblar `taxonomy_concept_relations`/`taxonomy_candidate_concept_links` sin autorización
  explícita separada — Phase 3 sigue en NOT STARTED para escritura real.
- No comprometer secretos en ningún archivo del repo.

---

# 15 — Execution order (recomendado para la próxima sesión)

Ver "Orden de ejecución recomendado" en `audit/phase3_completion_audit.md` — resumen:
1. Completar diseño de dry-run (2 campos faltantes).
2. Resolver el camino de aprobación de "proponer concepto nuevo".
3. Obtener `DEBUG_TOKEN`/`MCP_TOKEN` y correr regresión + verificación MCP real ANTES de tocar
   escritura.
4. Diseñar e implementar `--apply` con toda la batería de seguridad.
5. Población controlada (dry-run revisado por humano → aprobación manual, nunca bulk-apply).

---

# 16 — Immediate next action

**Pedirle a Esteban directamente el valor de `DEBUG_TOKEN` (guardado en algún gestor de notas
personal, si existe) — confirmado el 2026-09-23 que no vive en ningún `.env` desplegado ni es
recuperable vía permisos de Cloudflare (la plataforma nunca expone valores de secrets ya seteados,
solo permite rotarlos). Es el único bloqueador real que impide cerrar la verificación completa de
Phase 3 antes de decidir los próximos pasos de escritura.**

**Secundario, de menor prioridad:** decidir si correr `php artisan shield:generate` en staging
para las 3 páginas legacy que siguen invisibles en el menú (sección 4/9 arriba) — cambio aditivo,
bajo riesgo, pendiente de autorización explícita.
