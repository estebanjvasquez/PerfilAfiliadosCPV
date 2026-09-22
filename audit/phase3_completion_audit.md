# Auditoría de completitud — Phase 3 (Canonical Concept Builder)

**Fecha:** 2026-09-22
**Alcance:** determinar, con evidencia verificable, qué tan completo/operativo está el "Phase 3"
del motor de Taxonomía CPV/CIRA — no asumir que código existente equivale a funcionalidad
desplegada, ni que tablas desplegadas equivalen a datos poblados.
**Repos auditados:** `PerfilAfiliadosCPV` (`feature/upgrade-filament-v3`, HEAD `46fb0a5`) y
`perfilafiliados-mcp` (Cloudflare Worker).
**Metodología:** arqueología de `git log --all` (no solo HEAD) en ambos repos + lectura de código
línea por línea + verificación en vivo contra Supabase (conteos reales, no docblocks) +
verificación en vivo del servidor de staging (Contabo, vía SSH).

---

## Executive Summary

Phase 3 se construyó en **3 tandas separadas** el 18-19 de septiembre de 2026 (`8cdcc19` →
`b14401c` → `75d1cd7`), sin ningún revert. El esquema, los catálogos de gobernanza (tipos de
relación, tipos de concepto, marcadores de intención) y el motor de scoring (`CanonicalConceptBuilderService`)
están completos e implementados. **Pero la infraestructura de escritura real (`--apply`) no existe
en absoluto** — no es que esté bloqueada por un flag con lógica detrás; es un `return self::FAILURE`
sin ningún código de escritura que ejecutar. El único camino de escritura real que funciona hoy es
manual, vía UI (`CandidateConceptApprovalService`), y opera sobre una cola que está vacía porque
nada la llena todavía. Verdicto por componente en la sección final.

---

## Repositorios y commits inspeccionados

| Repo | Commits clave | Rango de fechas |
|---|---|---|
| `PerfilAfiliadosCPV` | `8cdcc19`, `b14401c`, `75d1cd7`, `386f3d2`, `46fb0a5` | 2026-09-18 a 2026-09-22 |
| `perfilafiliados-mcp` | `cb6d2e8` (Fase 24) y commits posteriores no numerados individualmente | 2026-09-19 en adelante |

`git log --all --oneline --grep="revert" -i` en `PerfilAfiliadosCPV`: solo 2 resultados, ninguno
relacionado a taxonomía/Phase 3. **No hubo reverts.**

---

## Cronología real de Phase 3 (no es una sola tanda)

| Commit | Fecha | Introduce |
|---|---|---|
| `8cdcc19` (TAXV3-2) | 2026-09-18 17:36 | `taxonomy_canonical_concepts`, `taxonomy_term_concepts`. Aún NO existe `CanonicalConceptBuilderService` ni las tablas tipadas. |
| `b14401c` ("Phase 3") | 2026-09-19 10:57 | `taxonomy_concept_relations`, `taxonomy_candidate_concept_links`, `taxonomy_concept_relation_types`, `taxonomy_concept_types`, `taxonomy_intent_markers`, `taxonomy_term_embeddings`. Nace `CanonicalConceptBuilderService.php` (704 líneas), `IntentContaminationDetector.php`, comando `taxonomy:build-canonical-concepts`, los 2 Resources de Filament. Mensaje del commit: "Cero población... verificado con conteos antes/después". `CandidateConceptApprovalService` **NO existe todavía** — el approve/reject vivía inline en el Resource. |
| `75d1cd7` ("Phase 3.1") | 2026-09-19 12:11 (~75 min después) | Perf (elimina N+1) + nace `CandidateConceptApprovalService.php` (extraído del Resource, ahora con transacción/lock/audit log) + `Phase3PermissionsSeeder.php` (los permisos Shield de Phase 3 nunca se habían sembrado — el panel era inaccesible incluso para `super_admin`). |

Sin commits de Phase 3 después de `75d1cd7` hasta hoy (`386f3d2` es el audit anterior, `46fb0a5` es
el fix de nginx — ninguno toca lógica de Phase 3).

---

## Base de datos — conteos en vivo (Supabase, verificado 2026-09-22, vía SSH + `php artisan tinker` desde el servidor de staging)

| Tabla | Filas | Notas |
|---|---|---|
| `taxonomy_term_cpv_relations` | 9.749 | Protegida — `TaxonomyAutoMapper` nunca la toca, solo agrega a términos sin relación previa |
| `taxonomy_canonical_concepts` | 79 | Sin cambios desde antes de Phase 3 (TAXV3-2 no tocado por Phase 3) |
| `taxonomy_term_concepts` | 142 | Ídem |
| `taxonomy_candidate_terms` (crawler) | 0 | Cola vacía, mecanismo independiente |
| **`taxonomy_concept_relations`** | **0** | **Phase 3, tabla de relaciones tipadas — sin poblar** |
| **`taxonomy_candidate_concept_links`** | **0** | **Phase 3, cola de revisión — sin poblar** |
| **`taxonomy_concept_relation_types`** | **5** | **Catálogo de gobernanza SÍ seedeado**: `RELATED_TO`, `PART_OF`↔`HAS_PART`, `SUPERSEDES`↔`SUPERSEDED_BY` |
| **`taxonomy_intent_types`** | **9** | **Catálogo SÍ seedeado**: MAINTENANCE, RENTAL, INSPECTION, MANUFACTURING, INSTALLATION, REPAIR, SUPPLY, TRANSPORT, ENGINEERING |
| `taxonomy_candidate_concept_relations` | 0 | Tabla adicional de Phase 3 (relaciones concepto↔concepto candidatas) — sin poblar |
| `taxonomy_term_semantic_intents` | 0 | Sin poblar |
| `taxonomy_term_semantic_analyses` | 0 | Sin poblar |
| `relation_type='concept_inherited'` (dentro de `taxonomy_term_cpv_relations`) | 0 filas vivas | Ver sección dedicada abajo |
| `taxonomy_term_source_bindings` | 117 | No es de Phase 3 (TAXV3-1), incluido como referencia cruzada |

**Hallazgo importante no reportado en la auditoría anterior:** los catálogos de gobernanza (tipos
de relación, tipos de intención) **sí están seedeados** — no es que TODO Phase 3 esté en cero, es
específicamente la capa de *instancias/datos* (relaciones reales, candidatos reales, análisis
semánticos reales) la que está en cero. El vocabulario/esquema de gobernanza está completo y listo
para usarse.

---

## Phase 3 — Matriz de componentes

| # | Componente | Código existe | Schema existe | Datos existen | Test existe | Desplegado | Operativo | Trabajo pendiente |
|---|---|---|---|---|---|---|---|---|
| 1 | Conceptos canónicos | ✅ (TAXV3-2, previo a Phase 3) | ✅ | ✅ (79 filas) | ✅ (`CanonicalConceptBuilderServiceTest.php`) | ✅ (staging, pgsql) | ✅ | Ninguno — es capa previa, ya operativa |
| 2 | Término → concepto | ✅ | ✅ | ✅ (142 filas) | Parcial | ✅ | ✅ | Ninguno |
| 3 | Concepto → concepto tipado | ✅ (`CanonicalConceptBuilderService`, motor de scoring) | ✅ (`taxonomy_concept_relations`) | ❌ (0 filas) | Sin test dedicado de escritura (no hay escritura que testear) | ✅ (schema) | ❌ | Falta TODO el camino de escritura — ver "write mode" abajo |
| 4 | Candidate concept links (cola de revisión) | ✅ | ✅ | ❌ (0 filas) | ✅ (`CandidateConceptApprovalServiceTest.php`, testea approve/reject) | ✅ | Parcial (el approve/reject funciona, pero no hay candidatos que revisar) | Falta el proceso que genere candidatos (dry-run no los persiste) |
| 5 | Terminología regional | ✅ (`region`, `term_type` propagados, corregido en `cb6d2e8`) | ✅ | ✅ (datos reales en `taxonomy_terms`) | ✅ (fixture `regression-cases.json`, grupo `B_regional`) | ✅ | ✅ | Ninguno |
| 6 | Resolución con contexto | ✅ (`IntentContaminationDetector`) | ✅ (`taxonomy_intent_markers`, `taxonomy_intent_types` — 9 seedeados) | Parcial (catálogo sí, análisis reales no — `taxonomy_term_semantic_intents`=0) | ✅ (parte del corpus de validación) | ✅ | Parcial | Falta correr el análisis real sobre términos existentes |
| 7 | Traversal de relaciones | Diseñado (`max_depth` en el catálogo), sin consumidor todavía | ✅ | ❌ (nada que atravesar, 0 relaciones) | — | — | ❌ | Depende de que 3 tenga datos primero |
| 8 | Integración con `TaxonomyAutoMapper` | ✅ (`concept_inherited` como una de 4 señales) | ✅ | Ver sección dedicada | ✅ | ✅ | Parcial — ver abajo | Ver sección `concept_inherited` |
| 9 | Cola de revisión (general) | ✅ | ✅ | ❌ | ✅ | ✅ | Parcial | Sin candidatos que llegaron por el flujo automático |
| 10 | Umbrales de confianza | ✅ (`AUTO_ACCEPT`/`AUTO_ACCEPT_CONSERVATIVE`/`REVIEW`/`REJECT`, configurables) | ✅ | — | ✅ (`ValidationCorpusTest.php`, 12 casos sintéticos) | ✅ | ✅ (el cálculo funciona) | Ninguno en el cálculo; falta que alimente escritura real |
| 11 | Provenance | Parcial (`source_evidence` es un booleano de solapamiento, no una cita/fuente explícita) | Parcial | — | — | — | Parcial | Diseño incompleto — ver sección dry-run |
| 12 | Propagación de evidencia | ✅ (TS: `relationType`, `mappingRelationType` preservado end-to-end, corregido en `cb6d2e8`) | — | — | ✅ (fixture de regresión) | ✅ | ✅ | Ninguno |
| 13 | Propagación de `mapping_relation_type` | ✅ (ver arriba, es el mismo punto) | — | — | — | ✅ | ✅ | Ninguno |
| 14 | Diagnóstico/observabilidad | ✅ (`debugCanonicalSearch()`, `query_count_by_stage`/`time_ms_by_stage` en el Builder) | — | — | — | ✅ | ✅ | Ninguno |
| 15 | Cobertura de regresión | ✅ (fixture con 32 queries reales, incluye "cabrias" etc.) | — | — | ✅ | Parcial — ver sección Regression | **No se pudo ejecutar hoy (BLOCKED)** | Ver `audit/regression_2026-09-22.md` |
| 16 | Capacidad de dry-run | ✅ pero incompleta — ver comparación de campos abajo | — | — | — | ✅ | Parcial | Faltan 2 de 8 campos esperados (relación concepto↔concepto propuesta, empresas afectadas predichas) |
| 17 | Capacidad de write-mode (`--apply`) | ❌ **No existe** — guard clause que retorna `FAILURE`, sin código de escritura detrás | ✅ (las tablas destino sí existen) | ❌ | — | ❌ | ❌ | Es el bloqueador real de todo Phase 3 — ver sección dedicada |
| 18 | Rollback/reversibilidad | N/A para `--apply` (no hay nada que escribir, nada que revertir). Sí existe para el camino manual (transacciones + audit log en `CandidateConceptApprovalService`) | — | — | — | ✅ (camino manual) | ✅ (camino manual) | Diseñar rollback para `--apply` cuando se implemente |

---

## Modelo conceptual: TÉRMINO vs CONCEPTO CANÓNICO

Verificado: el modelo **sí distingue** superficie (término) de concepto canónico — no es un
aliasing plano.

- Múltiples términos → un concepto: sí, vía `taxonomy_term_concepts` (N:M real, 142 enlaces sobre
  79 conceptos → promedio ~1.8 términos por concepto).
- `region`/`term_type`/`language` se preservan end-to-end (corregido en `cb6d2e8`, Fase 24) —
  confirmado en la auditoría previa y no modificado desde entonces.
- `provenance`: existe como conjunto de columnas (`origin_type`, `display_source`,
  `primary_source_id`, `candidate_sources`) + tabla `taxonomy_term_source_bindings` (117 filas) —
  pero **no llega como campo explícito a los candidatos de Phase 3** (ver dry-run abajo).
- Contexto (`REQUIRES_CONTEXT` conceptual): implementado como `IntentContaminationDetector` +
  catálogo `taxonomy_intent_types` (9 marcadores reales, seedeados), no como un enum genérico
  `REQUIRES_CONTEXT` en `taxonomy_concept_relation_types` — el catálogo de tipos de relación
  (sección siguiente) es un mecanismo separado del de intención.

**No hay gaps de diseño en este punto** — el modelo conceptual está completo e implementado.

---

## Grafo de relaciones tipadas — catálogo real (no asumido)

`taxonomy_concept_relation_types`, seedeado por `SeedTaxonomyConceptVocabulary` (comando manual,
`insertOrIgnore`, no corre automáticamente en ningún boot/scheduler):

| Código | Direccional | Inverso | Peso default |
|---|---|---|---|
| `RELATED_TO` | No | — | 0.50 |
| `PART_OF` | Sí | `HAS_PART` | 0.70 |
| `HAS_PART` | Sí | `PART_OF` | 0.70 |
| `SUPERSEDES` | Sí | `SUPERSEDED_BY` | 0.60 |
| `SUPERSEDED_BY` | Sí | `SUPERSEDES` | 0.60 |

**Esto NO coincide con el vocabulario genérico EXACT/SYNONYM/BROADER/NARROWER/RELATED que un plan
genérico podría asumir.** Es un vocabulario más pequeño y más operacional (mereológico +
versionado), sin conceptos de sinonimia/jerarquía semántica clásica — esos ya se resuelven en la
capa de `taxonomy_term_concepts` (identidad léxica/traducción/regionalismo), no en esta capa de
relaciones concepto↔concepto.

`max_depth` existe como columna (control de profundidad de traversal) pero **no hay ningún
consumidor de código que lo lea todavía** — diseñado, no usado. Marcado como **DESIGN-READY, NOT
CONSUMED**, no como gap.

Con 0 filas en `taxonomy_concept_relations`, no hay ninguna instancia real que clasificar por
semántica/direccionalidad/traversal — el catálogo está listo, el grafo está vacío.

---

## `TaxonomyAutoMapper` — integración con Phase 3

Sin cambios respecto a la auditoría previa: `TaxonomyAutoMapper` usa `concept_inherited` como su
2ª señal de 4 (después de `exactCategoryLabelMatch`, antes de `lexicalSimilarityMatch` y
`embeddingSimilarityMatch`) — ×0.9 de descuento de confianza cuando un término hermano en el mismo
concepto canónico ya tiene relación CPV aprobada. Esta integración **es independiente de Phase 3
(el Canonical Concept Builder)** — usa la capa TAXV3-2 (`taxonomy_term_concepts`, 142 filas), no
las tablas nuevas de Phase 3. Por eso `concept_inherited` podría en teoría generar filas sin que
Phase 3 tenga ninguna dato — y sin embargo tiene 0 filas vivas (ver sección siguiente).

---

## `concept_inherited` — investigación (obligatoria)

**Clasificación: DORMANT FEATURE (código completo y alcanzable, precondiciones nunca se cumplieron
en la práctica).**

- Código: `TaxonomyAutoMapper::conceptInheritedMatch()` (líneas 97-131), alcanzable — es la 2ª señal
  evaluada para CUALQUIER término sin relación CPV propia.
- Precondición real: el término debe pertenecer a un concepto canónico (`taxonomy_term_concepts`)
  Y otro término del mismo concepto debe tener ya una relación CPV con `status='approved'`.
- Por qué 0 filas vivas pese a que el código es alcanzable: la 1ª señal (`exactCategoryLabelMatch`,
  confidence 0.97) evidentemente resuelve casi todos los 277 términos candidatos (225 V2 + 52 V3)
  antes de que la ejecución llegue a la 2ª señal — el pipeline es de prioridad estricta, no
  ensemble, así que la 1ª señal que supera el piso de confianza gana y las siguientes ni se
  evalúan para ESE término. No hay evidencia de que `conceptInheritedMatch()` se haya ejecutado y
  fallado — es más probable que rara vez llegue a evaluarse porque la 1ª señal ya resuelve el caso.
- Tests: no hay test unitario dedicado exclusivamente a `conceptInheritedMatch()` en aislamiento
  (los tests de `AutoMapTaxonomyTerms` cubren el comando completo, no esta señal en particular).
- ¿Superseded? No — nada en Phase 3 lo reemplaza; de hecho Phase 3 (relaciones concepto↔concepto)
  es una capa completamente distinta.
- Riesgo de sobre-expansión si se activa manualmente: bajo, dado el descuento ×0.9 y que ya pasa
  por el mismo piso de confianza configurable que las otras 3 señales — pero no hay urgencia de
  "activarlo", ya está activo por diseño, solo no ha tenido ocasión de disparar como caso ganador.

**No se populó ni se backfilleó nada — cumple la instrucción de no tocarlo.**

---

## Candidate / Review pipeline — ciclo de vida real

Confirmado con evidencia de esquema y de código (no solo de nombre) que son **3 mecanismos
independientes**:

1. **`taxonomy_candidate_terms`** (crawler, TAXV2-12): términos nuevos. Ciclo:
   `candidate → needs_review → approved/rejected → published`. 0 filas.
2. **`taxonomy_candidate_concept_links`** (Phase 3): vínculos término→concepto. Ciclo real
   (verificado en `CandidateConceptApprovalService.php`): `pending → published` o
   `pending → rejected` — el estado intermedio `approved` existe como constante del modelo pero
   **nunca se usa en la práctica** (el servicio pasa directo a `published`). El camino
   "proponer concepto nuevo" (`suggested_concept_id === null`) **no tiene implementación de
   aprobación** — retorna `NOT_SUPPORTED_PROPOSE_NEW_CONCEPT`. 0 filas — y aunque hubiera filas,
   la mitad de los casos posibles (proponer concepto nuevo) no se puede aprobar hoy.
3. **`TaxonomyExceptionsPage`** (TAXV3-4): dashboard agregador de solo lectura, sin tabla propia,
   sin ciclo de vida — no es una etapa de pipeline.

**El principio "automatizar casos normales, revisar excepciones" está diseñado pero no alimentado**:
el único generador de candidatos sería `dryRun()`, y `dryRun()` no persiste nada (confirmado en el
propio docblock de la migración). Nadie llena la cola hoy.

---

## Capacidad de dry-run — comparación campo por campo

`CanonicalConceptBuilderService::dryRun()` (líneas 382-517) SÍ es un dry-run real y funcional
(nunca ejecuta INSERT/UPDATE/DELETE, confirmado por código, no por promesa). Por candidato expone:
`term_id`, `term`, `concept_id`, `concept_name`, `signals` (11 señales individuales), `score`,
`corroborating_signal_count`, `tier`.

| Campo esperado (spec) | Estado | Detalle |
|---|---|---|
| Concepto propuesto | ✅ Existe | `concept_id`/`concept_name` |
| Mapeo de término propuesto | ✅ Existe | par (`term_id`, `concept_id`) implícito |
| Confidence | ✅ Existe, como `score` | no se llama `confidence` en memoria (sí es el nombre de columna en la tabla destino) |
| **Relación de concepto propuesta** (concepto↔concepto) | ❌ **Falta** | el Builder solo propone término→concepto, nunca concepto↔concepto — el catálogo de 5 tipos de relación no lo consume nadie todavía |
| Fuente/provenance | ⚠️ Parcial | solo un booleano `source_evidence` (solapamiento de fuentes verificadas), no una cita/fuente explícita |
| Conflicto | ⚠️ Solo en `auditExisting()`, no en `dryRun()` | los flags de conflicto existen para auditar conceptos YA existentes, no para candidatos nuevos |
| Requiere revisión | ⚠️ Implícito vía `tier` | no hay booleano dedicado |
| Empresas afectadas predichas | ❌ **Falta** | ningún cálculo de impacto sobre `empresa_id` en el Builder de Laravel (esto sí existe del lado del motor de búsqueda TS, pero no está conectado al Builder) |

**Veredicto de esta sección: PHASE 3 BLOCKER real** — antes de habilitar cualquier escritura, el
dry-run debería exponer al menos "conflicto" y "empresas afectadas predichas" explícitamente para
que una revisión humana tenga información suficiente, y decidir si el modelo de "relación
concepto↔concepto propuesta" se necesita en este ciclo o se pospone.

---

## Write-mode — seguridad (obligatorio, NO se activó nada)

`--apply` en `BuildTaxonomyCanonicalConcepts.php`: guard clause literal, sin ninguna rama de código
de escritura detrás:

```php
if ($this->option('apply')) {
    $this->error('WRITE MODE BLOQUEADO...');
    return self::FAILURE;
}
```

Por lo tanto, para TODO ítem de seguridad de escritura pedido, la respuesta es **no existe, porque
no hay código de escritura al que evaluarle nada**: transacciones, idempotencia, protección de
duplicados, rollback, audit logging, provenance, versionado — todos **NO EXISTEN para `--apply`**.

El único camino de escritura real y funcional en todo el sistema de Phase 3 es el manual
(`CandidateConceptApprovalService`), que si cumple: transacción + `lockForUpdate()`, idempotencia,
audit log (`TaxonomyAuditLogger`) — pero opera sobre una cola vacía.

**No se implementó ningún código de escritura como parte de esta auditoría.**

---

## Regression Suite — resultado: BLOCKED

`perfilafiliados-mcp/scripts/regression-suite.mjs` requiere `--url` y `--token` (el `DEBUG_TOKEN`
del Worker) como argumentos CLI obligatorios, y pega contra `POST {url}/debug-search`.
`DEBUG_TOKEN` es un secreto de Cloudflare Worker, no vive en ningún archivo trackeado de ningún
repo, y no se pudo obtener en esta sesión (un token de API de Cloudflare con scope
Hyperdrive+Tunnels no tiene permiso sobre `workers/scripts/*/secrets`, confirmado con un intento
real que devolvió "No access to the specified resource").

**Clasificación: BLOCKED — AUTH CREDENTIAL REQUIRED, no ENGINE FAILURE.** Ver
`audit/regression_2026-09-22.md`.

---

## MCP Runtime — resultado: BLOCKED (parcial)

- Health check público (`GET /`, sin auth): **200 OK**, `{"ok":true,"service":"perfilafiliados-mcp"}`
  — confirma que el Worker está desplegado y responde.
- `/mcp` y `/debug-search` requieren `MCP_TOKEN`/`DEBUG_TOKEN` respectivamente — no disponibles en
  esta sesión, mismo motivo que la regresión.
- El Hyperdrive del Worker (`perfilafiliados-taxonomy`) sí se actualizó hoy con la contraseña nueva
  de Supabase (ver `docs/task.md`) — es razonable, no confirmado con una llamada real, que el
  camino Worker→Hyperdrive→Supabase funcione.

**Clasificación: BLOCKED — AUTH CREDENTIAL REQUIRED** para los 2 endpoints autenticados.

---

## Staging Deployment — ver documento dedicado

Ver `audit/staging_deployment_status.md` — resumen: el despliegue de `docs/migracion.md`
(Laravel 12 / Filament v3 / Livewire 3 / Supabase-pgsql) **sí se completó**, verificado en vivo
(0 migraciones pendientes, versiones exactas confirmadas, conexión pgsql activa).

---

## Documentación faltante — `docs/taxonomia/`

Confirmado vía `.gitignore:24` (`/docs/taxonomia/`) que el directorio existió/existe localmente en
algún checkout pero está deliberadamente excluido de git — coincide con que
`git log --all -- docs/taxonomia` no devuelve nada. Inventario completo de archivos referenciados
por el código (nombre, dónde se cita, para qué sirve según el comentario) en el reporte de la
auditoría de arqueología de esta sesión — los más citados: `plan_mcp_cira.md` (el plan maestro de
toda la iniciativa MCP/CIRA, citado en decenas de archivos de ambos repos),
`INSTRUCCIONES_TAXONOMIA_CPV_CRAWLER_ADMIN_V2.md` (spec del crawler/admin V2),
`MIGRACION_TAXONOMIA_CPV_V2_A_V3.md`, `intent_eval_cases.md`, más varios JSON/XLSX/PDF de datos e
insumos. Ninguno recuperable desde este checkout — vive fuera de git (posiblemente Notion, dado que
el proyecto usa skills `/cerrar-proyecto`/`/retomar-proyecto` con sincronización a Notion).

**No se recreó ningún contenido original.** Si en el futuro se reconstruye algo de esto, debe
etiquetarse explícitamente como `RECONSTRUIDO DESDE CÓDIGO/BASE DE DATOS/AUDITORÍAS`, nunca como el
original.

---

## Riesgos / clasificación

| Riesgo | Severidad | Nota |
|---|---|---|
| `docs/taxonomia/` inaccesible | Media | Bloquea entender el "por qué" de decisiones de diseño; no bloquea el código en sí |
| `--apply` no implementado | Alta (para avanzar Phase 3), Nula (para el estado actual) | Es un bloqueador de progreso, no un riesgo de producción — nada se escribe por accidente |
| Camino "proponer concepto nuevo" sin aprobación | Media | Si algún día `dryRun()` empieza a persistir candidatos, la mitad de los casos (concepto nuevo) quedarían atascados en `pending` para siempre sin una función de aprobación |
| Dry-run sin campo de "empresas afectadas" | Media | Una revisión humana no tendría visibilidad de impacto antes de aprobar |
| Regression suite / MCP runtime bloqueados por credenciales | Baja (no indica fallo real) | Requiere que el usuario provea `DEBUG_TOKEN`/`MCP_TOKEN` o acceso al dashboard de Cloudflare |

---

## Trabajo requerido antes de cualquier población de producción

1. Diseñar y documentar los campos faltantes del dry-run (relación concepto↔concepto propuesta,
   empresas afectadas predichas) — sección "Capacidad de dry-run" arriba.
2. Decidir e implementar el camino de aprobación para "proponer concepto nuevo" en
   `CandidateConceptApprovalService` (hoy retorna `NOT_SUPPORTED`).
3. Implementar `--apply` desde cero (no existe ni una versión bloqueada parcial) con: transacciones,
   idempotencia, protección de duplicados, audit log, provenance, versionado.
4. Decidir si el catálogo de `max_depth`/traversal de `taxonomy_concept_relations` se implementa
   antes o después de la primera población real (hoy nadie lo consume).
5. Obtener `DEBUG_TOKEN`/`MCP_TOKEN` del usuario para poder correr la regresión y verificar el
   Worker end-to-end antes de dar por buena cualquier población futura.

---

## Orden de ejecución recomendado

1. Resolver el diseño de dry-run (punto 1) — es barato, no toca datos.
2. Resolver el camino de "proponer concepto nuevo" (punto 2) — cambio de código acotado.
3. Obtener credenciales y correr regresión + verificación MCP (punto 5) — para tener una línea base
   de calidad ANTES de tocar nada de escritura.
4. Recién ahí diseñar e implementar `--apply` (punto 3) con toda la batería de seguridad.
5. Población controlada (dry-run revisado por humano → aprobación manual vía
   `CandidateConceptApprovalService`, NO bulk-apply) como primera corrida real.

---

## Phase 3 Completion Verdict

| Componente | Veredicto |
|---|---|
| Schema (tablas + catálogos de gobernanza) | **COMPLETE** |
| Motor de scoring (`CanonicalConceptBuilderService`) | **IMPLEMENTED** |
| Grafo de relaciones tipadas | **SCHEMA READY, UNPOPULATED, NO CONSUMER** |
| Cola de revisión (candidate_concept_links) | **PARTIAL** (aprobación funciona, nada la alimenta, camino "concepto nuevo" no soportado) |
| Dry-run | **PARTIAL** (6/8 campos esperados, faltan 2 con impacto real en revisión humana) |
| Write-mode (`--apply`) | **NOT STARTED** (no existe código, solo el guard que lo bloquea) |
| Regression validation | **BLOCKED** (falta credencial, no es fallo de motor) |
| MCP runtime verification | **BLOCKED** (falta credencial) |
| Staging deployment | **COMPLETE** (verificado en vivo) |
| Producción population | **NOT STARTED** |

**No se creó, modificó ni eliminó ninguna fila de producción de Phase 3 durante esta auditoría.**
