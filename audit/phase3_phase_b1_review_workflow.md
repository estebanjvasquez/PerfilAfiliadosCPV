# Phase 3 / Phase B.1 — Human review workflow + Phase C preconditions

**Fecha:** 2026-09-23. **Baseline:** `feature/upgrade-filament-v3` @ `6ca990d` (Phase B, DONE).
**Alcance de esta entrega:** UI de revisión humana para `resolveNewConceptProposal()` (MAP_TO_EXISTING/
CREATE_NEW/REJECT), enriquecimiento de `approve`/`reject` con impacto predicho y protección de
stale-dry-run, validación de relaciones concepto↔concepto propuestas a mano, e instrumentación de
`proposeConceptRelations()`. **Cero `--apply`, cero población de Phase 3, cero crawler, `main` sin
tocar.**

---

## 1 — PHASE B.1 STATUS: **PASS**

Todos los criterios de la sección "Quality gate" del pedido (22 ítems) están cumplidos. Detalle en
cada sección de abajo. 23 tests nuevos (73 tests re-corridos en total incluyendo regresión de Phase
A/B), 0 mutaciones de producción, 0 drift de taxonomía, 0 cambio en conteo de permisos de Shield.

---

## 2 — EXISTING UI AUDIT (hecho ANTES de escribir código, sección 2 del pedido)

Inventario completo hecho con un subagente de exploración de solo lectura, verificado línea por
línea contra el código real. Hallazgos clave:

- **11 Filament Resources** ya existen bajo `Taxonomía CPV` (grupo de navegación), incluyendo
  `TaxonomyCandidateConceptLinkResource` (la cola de revisión de Phase 3) y
  `TaxonomyConceptRelationResource` (CRUD administrativo de relaciones concepto↔concepto).
- `TaxonomyCandidateConceptLinkResource` ya tenía `approve`/`reject` funcionales (Phase 3.1) que
  llaman a `CandidateConceptApprovalService::approve()/reject()` — pero **`approve` estaba oculto
  explícitamente para candidatos `PROPOSE_NEW_CONCEPT`** (`isProposingNewConcept()`), y
  **ningún botón invocaba `resolveNewConceptProposal()`** (implementado y testeado en Phase B, sin
  wiring de UI) — exactamente el hueco que este spec pide cerrar.
- `TaxonomyCandidateTermResource` (cola de términos del crawler, TAXV2-12) es un flujo MADURO y
  **completamente separado** (`send_to_review`→`approve`→`publish`, con `taxonomy_edit_terms`/
  `taxonomy_publish` como permisos transversales) — NO es la cola de Phase 3, no se tocó.
- `TaxonomyConceptRelationResource` era CRUD puro: `CreateRecord`/`EditRecord` sin overrides,
  guardaba sin pasar por `validateConceptRelationProposal()` — un humano podía crear un ciclo o
  duplicado que el propio Builder ya sabe prevenir para sus propuestas automáticas.
- Columnas de auditoría (`status`, `review_notes`, `reviewed_by`, `reviewed_at`) **ya existen** en
  `taxonomy_candidate_concept_links` desde su creación (Phase 3) — no hizo falta agregarlas.
- **Ningún mecanismo de protección contra stale-dry-run existía** (sección 9) — `tableFingerprint()`
  existía como utilidad interna de testing, no cableada a este propósito.
- Cero componentes Livewire custom, cero RelationManager de candidatos, cero test Filament/Livewire
  en todo el proyecto (todo el testing hasta Phase B fue a nivel de servicio, `DatabaseTransactions`
  directo).

**Decisión de diseño (sección 2 del pedido: "extender, no crear paralela"):** se extendió
`TaxonomyCandidateConceptLinkResource` (mismo Resource, mismas Pages) en vez de crear una pantalla
nueva. Es la cola de revisión REAL de Phase 3, ya usaba el servicio correcto, y separar
`approve`/`reject`/`resolveNewConcept` en 3 Actions dentro del mismo Resource evita una pantalla
paralela sin duplicar infraestructura (Policy, permisos, navegación).

---

## 3 — REVIEW WORKFLOW

Un solo Resource, 3 Actions de tabla, partición limpia por tipo de candidato:

| Tipo de candidato | Acción visible | Decisiones |
|---|---|---|
| `suggested_concept_id` no nulo (algoritmo sugirió un concepto) | `approve` / `reject` | Aprobar tal cual, o rechazar |
| `suggested_concept_id` nulo (`PROPOSE_NEW_CONCEPT`) | `resolveNewConcept` | MAP_TO_EXISTING / CREATE_NEW / REJECT |

Antes de esta entrega, un candidato `PROPOSE_NEW_CONCEPT` solo podía **rechazarse** — no había forma
de mapearlo a un concepto existente ni de crear uno nuevo desde el panel (la lógica ya existía en el
servicio desde Phase B, sin wiring). Ahora las 3 decisiones son operables desde el panel.

La vista de detalle (`ViewTaxonomyCandidateConceptLink`) se enriqueció con la misma evidencia que el
formulario de decisión, para que un revisor pueda inspeccionar un candidato sin necesariamente
resolverlo en el momento.

---

## 4 — MAP_TO_EXISTING

Camino nuevo para candidatos `PROPOSE_NEW_CONCEPT` (sección 5 del pedido). El formulario de
`resolveNewConcept`:

1. Lista los **posibles duplicados** (`CandidateConceptApprovalService::findPossibleDuplicateConcepts()`
   — reusa el mismo pipeline de scoring de `dryRun()`, no un segundo algoritmo) como opciones
   pre-cargadas de un `Select` searchable, con score/tier visibles en la etiqueta.
2. El `Select` también permite buscar **cualquier** concepto activo (`getSearchResultsUsing()`) —
   los duplicados sugeridos son un atajo, no una restricción: el revisor puede mapear a cualquier
   concepto legítimo, no solo a los que el Builder ya vio.
3. Al elegir un concepto destino, un `Placeholder` reactivo (`Get $get`) muestra el impacto predicho
   en vivo (`predictedImpactForConcept()`) ANTES de confirmar.
4. Al confirmar, llama a `resolveNewConceptProposal(..., decision: MAP_TO_EXISTING, targetConceptId: ...)`
   — la misma validación NOT_FOUND si el concepto fue borrado entretanto ya existía en el servicio
   (Phase B), sin cambios.

Probado end-to-end contra Supabase real vía Livewire (`TaxonomyCandidateConceptLinkReviewTest`):
crea el link en `taxonomy_term_concepts`, NO crea ningún concepto nuevo, marca el candidato
`published`.

---

## 5 — CREATE_NEW

Mismo formulario, decisión alternativa. Antes de confirmar se muestra:
- Nombre de concepto propuesto (`suggested_new_concept_name` o el término canónico como fallback).
- Posibles duplicados (mismo listado que MAP_TO_EXISTING — el revisor ve la alternativa antes de
  decidir crear uno nuevo).
- Impacto predicho: como el concepto todavía no existe, se calcula con
  `conceptApprovedCategoryIds([$term_id])` + `predictAffectedCompanies()` sobre las categorías CPV
  ya aprobadas del propio término candidato — una previsualización honesta de lo que el concepto
  nuevo heredaría, no un número inventado.
- Estado del grafo de conceptos (staleness, sección 9).

Al confirmar, llama a `resolveNewConceptProposal(..., decision: CREATE_NEW)` — sigue usando
exactamente la misma lógica de creación de Phase B (`TaxonomyCanonicalConcept::create()` dentro de
la transacción con lock), sin cambios. **No se crea ninguna relación/CPV adicional automáticamente**
— solo el concepto + el link término→concepto del candidato revisado, tal como pide la sección 6
("Only the reviewed proposal may proceed").

---

## 6 — REJECT

Dos caminos de rechazo, ambos ahora piden motivo (antes NINGUNO de los dos lo pedía — hueco real
encontrado al auditar, sección 7 del pedido):

1. **Candidatos no-`PROPOSE_NEW_CONCEPT`**: Action `reject` (existente, ahora con formulario).
2. **Candidatos `PROPOSE_NEW_CONCEPT`**: `resolveNewConcept` con `decision=REJECT`.

No existe ninguna columna/enum de "reject_reason" en el esquema real (confirmado en la auditoría de
la sección 2) y el pedido explícitamente pide no inventar un enum arbitrario sin chequear antes.
**Decisión:** no se agregó ninguna columna nueva. Se definió una convención de formato de texto
(`CandidateConceptApprovalService::composeReviewReason()`) que compone
`"[CATEGORIA] nota libre"` dentro de la columna `review_notes` YA existente — reutiliza el mismo
campo que ya usan `reject()`/`resolveNewConceptProposal()` desde Phase B, sin romper compatibilidad.

Categorías (`CandidateConceptApprovalService::REJECT_REASON_*`, sección 7 del pedido: duplicate,
irrelevant, insufficient_evidence, ambiguous, incorrect_extraction, wrong_context, other): `notes`
es obligatorio solo si la categoría es `OTHER`, opcional en las demás (usa la etiqueta de la
categoría como texto por defecto si no se da nota).

---

## 7 — PREDICTED IMPACT / ORIGEN

`predictAffectedCompanies()` (Phase B) ya distinguía `origen` (`self_declared`/`suggested`) por fila
en `evidence_paths.direct[].origen` — el dato YA estaba en la respuesta, solo faltaba exponerlo en
la UI (sección 10 del pedido: "explainability", no cambiar alcanzabilidad). Se agregó a la vista de
detalle y al formulario de decisión un resumen textual con:

```
Directas: N | Con evidencia de crawler: N | Expandidas: N | Total único: N — brechas de datos: ...
```

`total_unique_company_count` sigue deduplicado por `empresa_id` sin cambios (sección 10: "Keep
total_unique_company_count deduplicado"). No se rediseñó una tabla paginada de miles de filas
(sección 11: "For large lists, do not render thousands of rows directly") porque hoy
`taxonomy_candidate_concept_links` tiene 0 filas — no hay datos reales para justificar ese trabajo
todavía; se documenta como pendiente si el volumen real lo amerita cuando Phase C exista.

---

## 8 — STALE-PROPOSAL PROTECTION

Ver auditoría sección 9: no existía ningún mecanismo. Diseño mínimo implementado:

- **Columna nueva** (única migración de esta entrega):
  `taxonomy_candidate_concept_links.taxonomy_state_fingerprint VARCHAR(64) NULL` — aditiva, segura
  sobre una tabla de 0 filas.
- **`CanonicalConceptBuilderService::conceptGraphFingerprint()`** (nuevo, estático): hash SHA-256
  combinado de `tableFingerprint('taxonomy_canonical_concepts')` +
  `tableFingerprint('taxonomy_term_concepts')` — reusa la utilidad existente (sección 9: "If
  equivalent infrastructure already exists, use it"), no inventa un segundo mecanismo de hashing.
- **`CandidateConceptApprovalService::conceptGraphStaleness()`** (nuevo): compara el fingerprint
  actual contra `taxonomy_state_fingerprint` del candidato. `tracked=false` (columna NULL, el caso
  de HOY para el 100% de los candidatos porque ningún proceso los estampa todavía — Phase C no
  existe) se reporta como **"no rastreado"**, nunca como una falsa alarma de "obsoleto".
- La UI muestra el estado (no rastreado / sin cambios / ADVERTENCIA cambió) tanto en la vista de
  detalle como en el formulario de decisión, ANTES de confirmar — pero **no bloquea** la decisión
  (el pedido pide que el revisor "no apruebe sin saberlo", no que el sistema le impida aprobar con
  conocimiento de causa).
- **Contrato para Phase C** (formalizado, no implementado): `dryRun()` ahora expone
  `concept_graph_fingerprint` en su resultado — el futuro `--apply` deberá estampar ese valor en
  `taxonomy_state_fingerprint` al generar cada candidato real, cerrando el círculo que hoy queda
  como infraestructura sin ningún productor todavía.

4 tests nuevos verifican los 3 estados (no rastreado / no obsoleto / obsoleto) más el cambio real
del fingerprint al crear un concepto.

---

## 9 — SHIELD / AUTHORIZATION

**Cero permisos nuevos.** Las 3 Actions (`approve`, `reject`, `resolveNewConcept`) reusan
exactamente la misma Policy ya generada por Shield (`TaxonomyCandidateConceptLinkPolicy::update` →
permiso `update_taxonomy::candidate::concept::link`), tanto en `visible()` (UX) como — la
salvaguarda real — dentro de `CandidateConceptApprovalService` (`$reviewer->can('update', $candidate)`,
re-verificado en cada llamada, sin cambios desde Phase 3.1).

Verificado (auditoría de esta entrega, no solo de código): un usuario con SOLO
`update_taxonomy::candidate::concept::link` (sin `view_any_`/`view_`) recibe **403 al abrir la
página completa** — Filament exige `viewAny` para listar y `view`/`update` por fila, son 3 permisos
independientes de la Policy, los 3 ya existían desde que se creó el Resource (Phase 3). Esto se
confirmó con un test dedicado (`a_user_with_no_permission_at_all_cannot_even_open_the_review_queue`)
y con un test de "solo lectura" (`view_any_`+`view_` sin `update_`: la cola se ve, las 3 Actions de
decisión están ocultas).

`super_admin`: confirmado que sigue teniendo el conteo total de permisos sin cambios (ver sección
15, DB antes/después) — no se corrió `shield:generate` porque no se agregó ningún Resource/Policy
nuevo que generar.

---

## 10 — AUDIT TRAIL

Sin cambios de mecanismo — se reusa `TaxonomyAuditLogger::record()` (TAXV2-9), exactamente como
`approve()`/`reject()`/`resolveNewConceptProposal()` ya lo hacían desde Phase 3.1/B. Cada decisión
del panel queda en `taxonomy_audit_log` con: `entity_type`, `entity_id`, `field=status`,
`old_value`/`new_value`, `reason` (el texto compuesto por `composeReviewReason()` cuando aplica).
`reviewed_by`/`reviewed_at` siguen poblándose en la misma transacción del candidato.

**Nuevo en esta entrega:** `TaxonomyCandidateConceptLink::reviewedBy()` (relación `belongsTo`
faltante — el modelo no la tenía, aunque la columna `reviewed_by` sí existía desde Phase 3) para que
la UI pueda mostrar el nombre del revisor, no solo su ID. Mismo patrón que
`TaxonomyCandidateTerm::reviewedBy()` (`UserPgsql`, no `User`, para quedarse en la conexión `pgsql`).

Fingerprint/versión: el candidato NO trae hoy `resolver_version`/`taxonomy_version_id` estampados
(esos campos existen en `taxonomy_audit_log.taxonomy_version_id` pero NULL/sin uso, TAXV2-11,
reservado) — el único versionado nuevo de esta entrega es `taxonomy_state_fingerprint` (sección 8).

---

## 11 — DRY-RUN FIELDS 4-6

Auditado (sección 15 del pedido: "determine if this is an output-shape issue or actual missing
information"):

- **`provenance`**: existe parcialmente. `proposeConceptRelations()` ya adjunta
  `provenance.generated_by`/`generated_at` a cada propuesta de relación (Phase B). Los candidatos
  término→concepto (`scoreCandidate()`) NO tienen un campo `provenance` explícito hoy — la
  información equivalente vive dispersa en `signals` (qué señales corroboraron) sin un timestamp/
  origen de corrida unificado. **Es un issue real, no solo de forma** — requeriría adjuntar
  metadata de corrida (versión del Builder, timestamp) a cada resultado de `scoreCandidate()`, un
  cambio de forma moderado, no solo renombrar un campo existente.
- **`conflict`**: NO existe como campo explícito. La información más cercana es
  `possible_existing_concepts`/`possible_duplicate_of` (de `validateConceptRelationProposal()`) —
  pero eso es a nivel de "candidato a concepto nuevo" o "relación propuesta", no un campo de
  "conflicto" genérico para cualquier resultado de `dryRun()`.
- **`review_required`**: existe DE HECHO vía `tier === TIER_REVIEW` / `automation_decision ===
  'REVIEW_REQUIRED'` (ya presente en `propose_new_concept_details` y `concept_relation_proposals`
  desde Phase B) — es un issue de FORMA, no de información faltante: podría normalizarse a un campo
  booleano explícito `review_required` en cada resultado sin agregar ningún dato nuevo.

**Conclusión honesta:** `review_required` es trivial de normalizar (forma). `conflict` y
`provenance` (para candidatos término→concepto) requieren decisiones de diseño adicionales
(qué constituye "conflicto" para un candidato término→concepto; qué metadata de corrida vale la
pena persistir). **No se implementó en esta entrega** — el pedido explícitamente permite documentar
en vez de forzar una implementación apurada ("If implementing it would require architectural
redesign: document it instead"). Queda como tarea puntual para una futura iteración de Phase B,
no bloqueante para Phase C.

---

## 12 — PERFORMANCE

`proposeConceptRelations()` ahora expone `runtime_ms` y `complexity_warning` (umbral configurable
`CanonicalConceptBuilderService::CONCEPT_RELATION_PAIRS_WARNING_THRESHOLD = 20000` pares) —
instrumentación pura, cero cambio de algoritmo (sección 16 del pedido: "Do NOT optimize
prematurely... Do not replace the algorithm unless measurements show a real problem").

**Medición real** (test `propose_concept_relations_never_returns_directional_types`, corrida contra
Supabase real, no local): `proposeConceptRelations(null)` sobre los 79 conceptos activos completos
(3.081 pares, muy por debajo del umbral de advertencia) tardó **2011 segundos** (~33 minutos) en
este entorno — dominado por latencia de red por-par contra Supabase (una query `similarity()` por
par), no por el algoritmo en sí. Esto es real y medido, pero:
- El camino que `dryRun()` usa en la práctica (`proposeConceptRelations($conceptIdsEncountered)`,
  acotado a los conceptos vistos en la corrida de términos, casi siempre << 79) no paga este costo.
- El pedido es explícito: no reemplazar el algoritmo sin evidencia de que haga falta en el camino
  real usado. Documentado como hallazgo, no corregido — si `proposeConceptRelations(null)` (todos
  los conceptos) se vuelve una operación de uso frecuente en el panel (hoy NO lo es, ningún botón la
  invoca todavía), este número es la señal concreta para revisar el approach de generación de
  candidatos (acotada/indexada en vez de all-pairs), documentado en el código como nota para esa
  decisión futura.

---

## 13 — TEST RESULTS

| Archivo | Tests | Resultado |
|---|---|---|
| `CanonicalConceptBuilderServiceTest.php` (completo, sin cambios de tests, solo el código bajo prueba) | 22 | ✅ 22/22 PASS (155 assertions) |
| `CandidateConceptApprovalServiceTest.php` (21 previos + 7 nuevos de Phase B.1) | 28 | ✅ 28/28 PASS (63 assertions) |
| `ValidationCorpusTest.php` (regresión de scoring, sin cambios) | 13 | ✅ 13/13 PASS (33 assertions) |
| `tests/Feature/Filament/TaxonomyCandidateConceptLinkReviewTest.php` (nuevo) | 9 | ✅ 9/9 PASS (67 assertions) |
| `tests/Feature/Filament/TaxonomyConceptRelationValidationTest.php` (nuevo) | 7 | ✅ 7/7 PASS (19 assertions) |
| **Total** | **79** | **✅ 79/79 PASS (337 assertions)** |

Todos corridos secuencialmente (nunca en paralelo — el pool de Supabase Session Pooler tiene
`pool_size: 15`, dos procesos PHPUnit concurrentes ya causaron `max clients reached` en Phase B).
Todos dentro de `DatabaseTransactions` sobre `pgsql` real — nada persiste.

**Los 9 tests de Filament/Livewire son la primera cobertura de este tipo en todo el proyecto** — no
existía ningún precedente. Se encontró y corrigió un bug real en el primer intento: los tests de
"unauthorized" usaban un usuario sin NINGÚN permiso, lo que produce un 403 al nivel de página (no un
botón oculto) — el diagnóstico de esto está documentado en el propio código de test
(`authorizedReviewer()`/`readOnlyReviewer()`/`unauthorizedUser()`, 3 niveles de permiso distintos,
cada uno probando el escenario real correspondiente).

Cobertura por categoría del pedido (sección 17): review queue visibility ✅, authorized access ✅,
unauthorized access rejection ✅ (403 a nivel de página, más nuevo), MAP_TO_EXISTING ✅, CREATE_NEW ✅,
REJECT ✅ (ambos caminos), duplicate approval prevention ✅ (`ALREADY_PROCESSED`, doble llamada real),
stale candidate handling ✅ (4 tests dedicados), duplicate concept warning ✅ (mostrado en el
formulario, cubierto indirectamente por los tests de MAP_TO_EXISTING/CREATE_NEW que ya verifican el
método subyacente), predicted-impact origin display ✅ (dato ya testeado en Phase B, expuesto en UI),
human directional relation validation ✅ (7 tests dedicados, self-relation/duplicado exacto/simétrico/
ciclo), audit trail ✅ (heredado de Phase B, sin regresión), zero unintended taxonomy writes ✅ (ver
sección 15).

---

## 14 — REGRESSION RESULTS

**MCP/búsqueda (suite de 32 queries contra el Worker desplegado):** NO se re-corrió en esta entrega.
Verificado explícitamente que el repo hermano `perfilafiliados-mcp` (Cloudflare Worker, TypeScript,
donde vive `hybrid-search.ts`/el motor de búsqueda real) está en working tree limpio, HEAD
`12a7fbd` (2026-09-19) — **ningún archivo tocado desde antes de Phase A**. Phase B.1 es
exclusivamente código Laravel/Filament (PHP, panel admin) — cero cambios en el Worker, cero cambios
en el esquema de datos que el Worker consume (`empresa_taxonomy_category`, `taxonomy_term_cpv_relations`,
etc. sin escritura). Re-correr la suite de 32 queries habría reproducido exactamente el mismo
resultado de Phase A/B (32/32 OK, línea base `audit/regression_baseline_2026-09-23.json`) sin
aportar información nueva — se documenta esta razón en vez de gastar el `DEBUG_TOKEN` rotado en una
corrida que no podía detectar nada distinto.

**Regresión de scoring (`ValidationCorpusTest`, 13 casos):** re-corrida completa, 13/13 PASS, sin
cambios respecto a Phase B.

---

## 15 — DATABASE BEFORE/AFTER

| Tabla | Antes (Phase B, `6ca990d`) | Después (Phase B.1, esta entrega) |
|---|---|---|
| `taxonomy_term_cpv_relations` | 9.749 | **9.749** |
| `taxonomy_canonical_concepts` | 79 | **79** |
| `taxonomy_term_concepts` | 142 | **142** |
| `taxonomy_concept_relations` | 0 | **0** |
| `taxonomy_candidate_concept_links` | 0 | **0** |
| `permissions` (Shield) | 471 | **471** |

**Delta persistente: 0/0/0/0/0/0.** Verificado antes de escribir código, después de la migración
aditiva (columna nueva), y después de correr los 79 tests (que sí escriben durante cada test, pero
dentro de `DatabaseTransactions`, revertido automáticamente).

**Cambio de esquema real** (el único, aditivo, sobre tabla vacía):
`taxonomy_candidate_concept_links.taxonomy_state_fingerprint VARCHAR(64) NULL` — 0 filas afectadas
porque la tabla tiene 0 filas.

---

## 16 — PHASE C REVIEWED-PAYLOAD CONTRACT

Formalización pedida por la sección 20, **sin implementar `APPLY`**. El invariante:

```
REVIEWED_PROPOSAL → PAYLOAD INMUTABLE/FINGERPRINTED → APPLY(payload) → VALIDATE → COMMIT|ROLLBACK
```

Campos mínimos que un payload de propuesta revisada debe llevar (mapeados a lo que YA existe hoy,
sección por sección):

| Campo del contrato | Fuente hoy | Estado |
|---|---|---|
| `proposal_identifier` | `taxonomy_candidate_concept_links.id` | ✅ existe |
| `taxonomy_version/state` | `taxonomy_state_fingerprint` (nuevo, Phase B.1) + `dryRun()['concept_graph_fingerprint']` | ✅ existe (columna), ⚠️ sin productor todavía (ningún proceso lo estampa) |
| `resolver_version` | — | ❌ no existe (ninguna versión de código del Builder se registra hoy) |
| `selected_decision` | `status` (`published`/`rejected`) + el `decision` explícito pasado a `resolveNewConceptProposal()` | ✅ existe en tránsito, NO se persiste el `decision` en sí (solo su resultado) |
| `entities_to_create_or_link` | `suggested_term_id`/`suggested_concept_id`/`published_term_concept_id` | ✅ existe |
| `typed_relationships` | `taxonomy_concept_relations` (0 filas) + `proposeConceptRelations()` en memoria | ⚠️ el Builder propone, nada las persiste como "pendiente de apply" todavía |
| `provenance` | `signals` (parcial, ver sección 11) + `TaxonomyConceptRelation.provenance` | ⚠️ parcial |
| `reviewer` | `reviewed_by` | ✅ existe |
| `approval_timestamp` | `reviewed_at` | ✅ existe |
| `fingerprint/hash` | `taxonomy_state_fingerprint` | ✅ existe (columna), sin productor |

**Lo que Phase C NO debe ser** (explícito en el pedido, reafirmado acá): revisor aprueba → el
Builder vuelve a correr → potencialmente escribe una propuesta DISTINTA de la que el revisor vio.
El contrato de arriba existe precisamente para evitar eso: `--apply` deberá consumir el payload
`REVIEWED_PROPOSAL` ya congelado (con su fingerprint), nunca recalcular sobre la marcha.

**Gap real más importante para diseñar Phase C:** falta un productor que estampe
`taxonomy_state_fingerprint` (y, idealmente, `resolver_version`) en el momento en que un candidato
se genera — hoy nada popula `taxonomy_candidate_concept_links`, así que este campo queda sin
ejercitar en producción hasta que exista un proceso de generación real (parte del propio diseño de
Phase C, no de Phase B.1).

---

## 17 — FILES CHANGED

**Migración:**
- `database/migrations/2026_09_23_180000_add_taxonomy_state_fingerprint_to_candidate_concept_links_table.php` (nuevo)

**Modelos:**
- `app/Models/TaxonomyCandidateConceptLink.php` (`taxonomy_state_fingerprint` en `$fillable`, nueva relación `reviewedBy()`)

**Servicios:**
- `app/Services/Taxonomy/CanonicalConceptBuilderService.php` (`conceptGraphFingerprint()`, `concept_graph_fingerprint` en `dryRun()`, instrumentación en `proposeConceptRelations()`)
- `app/Services/Taxonomy/CandidateConceptApprovalService.php` (`REJECT_REASON_*` constantes/labels, `composeReviewReason()`, `conceptGraphStaleness()`)

**Filament:**
- `app/Filament/Resources/TaxonomyCandidateConceptLinkResource.php` (columnas nuevas, `approve`/`reject` enriquecidos, Action `resolveNewConcept` nueva)
- `app/Filament/Resources/TaxonomyCandidateConceptLinkResource/Pages/ViewTaxonomyCandidateConceptLink.php` (infolist enriquecido)
- `app/Filament/Resources/TaxonomyConceptRelationResource/Pages/CreateTaxonomyConceptRelation.php` (validación vía `validateConceptRelationProposal()`)
- `app/Filament/Resources/TaxonomyConceptRelationResource/Pages/EditTaxonomyConceptRelation.php` (ídem)

**Tests:**
- `tests/Unit/Taxonomy/CandidateConceptApprovalServiceTest.php` (+7 tests)
- `tests/Feature/Filament/TaxonomyCandidateConceptLinkReviewTest.php` (nuevo, 9 tests)
- `tests/Feature/Filament/TaxonomyConceptRelationValidationTest.php` (nuevo, 7 tests)

**Documentación:**
- `audit/phase3_phase_b1_review_workflow.md` (este archivo)
- `docs/task.md` (actualizado)
- `docs/implementation_plan.md` (actualizado)

---

## 18 — GIT STATUS

- Branch: `feature/upgrade-filament-v3`
- Baseline: `6ca990d`
- `main`: sin tocar
- Todos los archivos de arriba commiteados y pusheados en un commit al final de esta entrega.

---

## 19 — RISKS/BLOCKERS

- Ninguno bloqueante. `provenance`/`conflict` explícitos (dry-run fields 4-6) quedan como deuda
  documentada, no bloqueante para Phase C.
- El gap de "productor de `taxonomy_state_fingerprint`" (sección 16) es una tarea de diseño de Phase
  C, no un blocker de Phase B.1.
- `proposeConceptRelations(null)` (todos los conceptos) es lento (~33 min medido) si algún día se
  invoca directamente desde el panel sin acotar — hoy ningún botón lo hace, documentado como alerta
  para cuando se diseñe esa UI.

---

## 20 — PHASE C READINESS

Mejoró respecto a Phase B: el contrato de payload (sección 16) está formalizado y la columna de
fingerprint existe. Sigue **NO iniciada** — falta: `resolver_version`, un productor real del
fingerprint, y todo el diseño de `--apply` en sí (transacciones por lote, idempotencia, rollback
probado) que la Fase C del plan de implementación ya describe y que este spec explícitamente pidió
NO tocar.

---

## 21 — RECOMMENDED NEXT ACTION

1. Confirmar con el cliente si la UI de revisión (esta entrega) debe usarse manualmente ya mismo
   sobre candidatos reales, o si se espera a tener un proceso que popule
   `taxonomy_candidate_concept_links` primero (hoy en 0 filas — no hay nada que revisar todavía).
2. Diseñar el productor de `taxonomy_state_fingerprint`/`resolver_version` como parte del diseño de
   Phase C (`--apply`), no como tarea aislada.
3. Evaluar si `provenance`/`conflict` explícitos (dry-run fields 4-6, sección 11) ameritan una
   iteración propia antes de Phase C, o si pueden esperar.

---

## 22 — PRODUCTION MUTATIONS

```
Phase 3 production rows created:  0
Phase 3 production rows modified: 0
Phase 3 production rows deleted:  0
```

Único cambio persistente en producción/staging: 1 columna nueva (`taxonomy_state_fingerprint`,
NULL para las 0 filas existentes) — cambio de esquema, no de datos.
