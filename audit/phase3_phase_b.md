# Phase B — Grafo de conceptos + dry-run completo (8/8)

**Fecha:** 2026-09-23
**Baseline:** `feature/upgrade-filament-v3` @ `1c3eefb`
**Alcance:** completar los 2 campos faltantes del dry-run (B1: relación concepto↔concepto
propuesta, B2: empresas afectadas predichas) y el camino de aprobación de "proponer concepto
nuevo" (B3) — **sin ningún modo de escritura de producción, sin poblar
`taxonomy_concept_relations`/`taxonomy_candidate_concept_links` en producción**.

---

## Executive Summary

Los 3 componentes se implementaron como código nuevo (no como plan) y se testearon contra la
misma base de datos de Supabase real usada en Phase A, envueltos en `DatabaseTransactions` — nada
de lo escrito por los tests persiste. El dry-run pasa de 6/8 a 8/8 campos esperados. Se tomó una
decisión de diseño deliberada y documentada: el proponedor automático de relaciones
concepto↔concepto **solo genera `RELATED_TO`** (no direccional); los 4 tipos direccionales/
jerárquicos del catálogo (`PART_OF`/`HAS_PART`/`SUPERSEDES`/`SUPERSEDED_BY`) tienen la maquinaria
de seguridad completa (duplicados, direccionalidad, ciclos) para cuando un humano los proponga a
mano, pero el Builder no los infiere automáticamente — inferir "parte de" desde el anidamiento de
`taxonomy_categories.path` confundiría la jerarquía CPV con la semántica de conceptos, exactamente
lo que el proyecto ya decidió evitar para `relation_type` de CPV.

---

## Baseline

- `taxonomy_term_cpv_relations` = 9.749, `taxonomy_canonical_concepts` = 79,
  `taxonomy_term_concepts` = 142, `taxonomy_concept_relations` = 0,
  `taxonomy_candidate_concept_links` = 0 (sin cambios respecto a Phase A — ver sección "Database
  Before/After" más abajo para la confirmación real).
- `taxonomy_concept_relation_types` = 5 filas reales: `RELATED_TO` (simétrico), `PART_OF`↔`HAS_PART`
  (direccionales, inversos entre sí), `SUPERSEDES`↔`SUPERSEDED_BY` (direccionales, inversos entre
  sí).

---

## Seeded Relation Type Catalog (verificado en código, no asumido)

| Código | Direccional | Inverso | Peso default | Semántica |
|---|---|---|---|---|
| `RELATED_TO` | No | — | 0.50 | Relación general sin dirección — dos conceptos genuinamente relacionados sin que uno sea parte/reemplace al otro. |
| `PART_OF` | Sí | `HAS_PART` | 0.70 | El concepto origen es un componente del concepto destino. |
| `HAS_PART` | Sí | `PART_OF` | 0.70 | Inverso de `PART_OF` — el concepto origen contiene al destino. |
| `SUPERSEDES` | Sí | `SUPERSEDED_BY` | 0.60 | El concepto origen reemplaza/sustituye al destino (versión más nueva de un mismo concepto). |
| `SUPERSEDED_BY` | Sí | `SUPERSEDES` | 0.60 | Inverso de `SUPERSEDES`. |

Automatización permitida por tipo: `RELATED_TO` puede llegar a `AUTO_ELIGIBLE` (con corroboración
suficiente); los 4 direccionales **nunca** se auto-proponen — solo se validan si un humano los
ingresa (`REVIEW_REQUIRED` implícito, no hay otro camino en el código actual).

---

## Concept Relation Proposer (B1)

**Implementado:** `CanonicalConceptBuilderService::proposeConceptRelations(?array $conceptIds)`.

- Evalúa pares de conceptos (todo-contra-todo cuando se pide explícitamente sin argumentos, o
  acotado a los conceptos ya vistos como candidatos en la corrida actual de `dryRun()` — nunca
  all-pairs "por accidente" dentro del loop de términos).
- Señales reales, sin inventar un modelo nuevo: similitud léxica de nombre (`pg_trgm`, reusa
  `similarity()`), CPV compartido (reusa `cpvSignals()`, el MISMO método que usa el scoring
  término↔concepto), solapamiento de término/alias entre miembros.
- Requiere `concept_relations.min_corroborating_signals` (default 2 de 3) para `AUTO_ELIGIBLE`;
  por debajo de `concept_relations.review_threshold` (default 0.40) no propone nada.
- Nunca duplica: chequea `taxonomy_concept_relations` existente antes de proponer (ambos
  sentidos para `RELATED_TO`).
- Nuevos parámetros configurables agregados a `TaxonomyRankingParameters`
  (`concept_relations.*`, grupo "Phase B - Grafo de relaciones entre conceptos") — mismo mecanismo
  de configuración ya usado por Phase 3, no una tabla de config paralela.

## Directionality Rules

Implementado en `validateConceptRelationProposal()`:
- Auto-rechaza auto-relación (`source === target`).
- Tipo simétrico (`directional=false`): (A,B) y (B,A) son la MISMA relación — duplicado en
  cualquier sentido se rechaza (`DUPLICATE_VIA_SYMMETRY`).
- Tipo direccional con inverso (`PART_OF`↔`HAS_PART`, `SUPERSEDES`↔`SUPERSEDED_BY`): proponer
  (A,B,TIPO) cuando ya existe (B,A,INVERSO) es la MISMA relación expresada al revés — se rechaza
  (`DUPLICATE_VIA_INVERSE`).

## Cycle Protection

Implementado en `wouldCreateCycle()` (BFS acotado por `concept_relations.max_cycle_check_depth`,
default 20 — límite de costo del chequeo, distinto del `max_depth` de negocio del catálogo de
gobernanza). Se aplica a cualquier tipo direccional. Verificado con un test real: A PART_OF B,
B PART_OF C ya existentes → proponer C PART_OF A se rechaza con `CYCLE_DETECTED` antes de que
exista ningún modo de escritura que pudiera persistirlo.

## Confidence Model

Reutiliza el mismo patrón de umbrales configurables que Phase 3 (nunca constantes mágicas sin
explicar): `concept_relations.auto_eligible_threshold` (0.75), `concept_relations.review_threshold`
(0.40), `concept_relations.min_corroborating_signals` (2). `confidence` (0.0-1.0, promedio de las 3
señales) y `automation_decision` (`AUTO_ELIGIBLE`/`REVIEW_REQUIRED`) son campos separados — una
confianza alta en un tipo direccional NUNCA es `AUTO_ELIGIBLE` porque el Builder simplemente no
genera propuestas direccionales automáticas (ver decisión de diseño arriba).

---

## Predicted Company Impact (B2)

**Implementado:** `CanonicalConceptBuilderService::predictAffectedCompanies(array $categoryIds)`
+ atajo `predictedImpactForConcept(TaxonomyCanonicalConcept $concept)`.

- Consulta `empresa_taxonomy_category` (el vínculo real empresa↔CPV, mismo dato que expone el
  Worker como evidencia `canonical_cpv`/`declared_profile` — una sola fuente de verdad, no una
  semántica de alcanzabilidad paralela en PHP) + `company_term_matches` (evidencia de crawler,
  TAXV2-13, hoy vacía porque el crawler es Fase E — no implementado, no fabricado).
- `direct_company_count`, `evidence_company_count`, `expanded_company_count` (0 — sin consumidor de
  `taxonomy_concept_relations` todavía, no hay expansión real que calcular), `total_unique_company_count`.
- Deduplicado por `empresa_id`: una empresa con 2 filas de evidencia (ej. `origen` distinto) cuenta
  1 vez en `total_unique_company_count`, preservando ambas rutas en `evidence_paths`.
- Se adjunta a CADA resultado del dry-run (`predicted_impact`), memoizado por concepto (no una
  query por par término×concepto — mismo patrón de cache que `conceptEvidenceCache`).
- Flags de diagnóstico preservados y distinguibles (sección 14 del pedido): `CPV_WITHOUT_COMPANIES`
  (CPV resuelto, 0 empresas) es un flag propio, separado de cualquier fallo de resolución de
  concepto (que directamente no genera un `categoryIds` para consultar, ver
  `CONCEPT_RESOLUTION_FAILURE` cuando la lista de categorías está vacía).

## Evidence Deduplication

Verificado con test real: 2 filas de `empresa_taxonomy_category` para la misma `empresa_id` (dos
orígenes) → `total_unique_company_count = 1`, con ambas rutas de evidencia preservadas en el array
`companies[].evidence_paths`.

---

## New Concept Proposal (B3)

**Implementado:** `CandidateConceptApprovalService::resolveNewConceptProposal()` +
`findPossibleDuplicateConcepts()`.

- `approve()` (el método existente) **no se modificó** — sigue devolviendo
  `RESULT_NOT_SUPPORTED` para candidatos "proponer concepto nuevo" (test existente intacto, cero
  regresión). Un candidato de concepto nuevo no es un sí/no binario, necesita que el revisor elija
  entre 3 caminos — por eso se resuelve con un método nuevo, no sobrecargando el existente.
- `resolveNewConceptProposal(candidateId, reviewer, decision, targetConceptId?, notes?)` con
  `decision` ∈ `{MAP_TO_EXISTING, CREATE_NEW, REJECT}` (sección 17 del pedido).
  - `REJECT` delega directamente a `reject()` (reuso, no duplicación).
  - `MAP_TO_EXISTING` vincula el término al concepto existente indicado — no crea nada.
  - `CREATE_NEW` crea un `TaxonomyCanonicalConcept` nuevo y vincula el término.
- Mismas salvaguardas que `approve()`/`reject()`: transacción, `lockForUpdate()`, autorización
  re-verificada dentro del servicio, idempotencia (segunda llamada → `ALREADY_PROCESSED`), audit
  log vía `TaxonomyAuditLogger` (mismo mecanismo, no uno paralelo).

## Duplicate Protection

`findPossibleDuplicateConcepts()` reutiliza el MISMO pipeline de retrieval+scoring que `dryRun()`
(nuevo método público `scoredCandidatesForTerm()`) — no un segundo algoritmo de similitud. Un
candidato "concepto nuevo" que en realidad tiene conceptos existentes con evidencia parcial
(tier ≠ REJECT) los expone como `possible_existing_concepts`, tanto en el dry-run
(`propose_new_concept_details`) como bajo demanda antes de aprobar.

## Approval Service

Requisitos de la sección 18 del pedido, todos cumplidos (mismos que ya tenía `approve()`/
`reject()`, extendidos al nuevo método): transacción, idempotencia, protección de duplicados
(reusa el link existente en vez de violar el `UNIQUE`), audit, validación (autorización +
`isProposingNewConcept()` + `status===PENDING` re-verificados dentro de la transacción), rollback
implícito por transacción de Postgres. No se activó aprobación en bulk — cada llamada requiere un
`$reviewer` y una `$decision` explícitos.

---

## Dry-Run Contract 8/8

| # | Campo | Estado antes de Phase B | Estado después |
|---|---|---|---|
| 1 | Concepto propuesto | ✅ | ✅ (sin cambios) |
| 2 | Mapeo de término propuesto | ✅ | ✅ (sin cambios) |
| 3 | Confidence | ✅ (como `score`) | ✅ (sin cambios) |
| 4 | Fuente/provenance | ⚠️ Parcial | ⚠️ Sigue parcial (`source_evidence` booleano) — no se resolvió, fuera del alcance de B1/B2/B3 puntuales |
| 5 | Conflicto | ⚠️ Solo en `auditExisting()` | ⚠️ Sin cambios — no era parte de los 2 campos pedidos para Phase B |
| 6 | Requiere revisión | ⚠️ Implícito vía `tier` | ⚠️ Sin cambios en el campo término→concepto; SÍ explícito (`automation_decision`) en las propuestas nuevas de B1 y B3 |
| **7** | **Relación de concepto propuesta** | ❌ Faltaba | ✅ **`concept_relation_proposals`** |
| **8** | **Empresas afectadas predichas** | ❌ Faltaba | ✅ **`predicted_impact`** por candidato |

**Nota honesta:** Phase B cerró específicamente los 2 campos que el pedido nombraba (B1/B2) más el
camino de aprobación (B3). Los campos 4-6 quedan con el mismo nivel de completitud parcial que ya
tenían — no se tocaron porque no estaban en el alcance de esta fase; quedan anotados como trabajo
pendiente real en `docs/task.md`, no como "resueltos".

---

## Tests

Extendidos:
- `tests/Unit/Taxonomy/CanonicalConceptBuilderServiceTest.php` — 15 tests nuevos (B1/B2): impacto
  predicho (vacío, deduplicado, data-gap), dry-run adjunta `predicted_impact`, propuestas
  `RELATED_TO` vía CPV compartido, nunca tipos direccionales, pares sin señal no proponen nada,
  validación (auto-relación, tipo desconocido, duplicado exacto, duplicado simétrico, duplicado
  vía inverso, ciclo detectado, relación válida nueva).
- `tests/Unit/Taxonomy/CandidateConceptApprovalServiceTest.php` — 9 tests nuevos (B3):
  duplicados vacíos para candidato existente, REJECT delega, sin autorización no escribe nada, no
  aplica a candidato que no es "concepto nuevo", crea concepto + vincula, mapea a existente sin
  crear, `MAP_TO_EXISTING` sin id falla `NOT_FOUND`, idempotencia, audit log.

Todos corren dentro de `DatabaseTransactions` sobre `pgsql` — nada persiste tras cada test, mismo
patrón que las suites de Phase 3/3.1 ya existentes.

## Test Results (ejecución real contra Supabase, no simulado)

| Archivo | Resultado |
|---|---|
| `CanonicalConceptBuilderServiceTest.php` | ✅ **22/22** (155 assertions) |
| `CandidateConceptApprovalServiceTest.php` | ✅ **21/21** (50 assertions) |
| `ValidationCorpusTest.php` (sin cambios de código, re-verificado igual) | ✅ **13/13** (33 assertions) |
| **Total** | **56/56, 0 fallas reales** |

**Nota real del proceso (transparencia, no se oculta):** las primeras corridas devolvieron errores
`SQLSTATE[08006]... max clients reached in session mode - max clients are limited to pool_size: 15`
— **agotamiento del pool de conexiones de Supabase por correr 2 procesos de PHPUnit en paralelo
contra el mismo pooler de sesión**, no un bug de código. Confirmado corriendo cada archivo por
separado y de a uno. Además, 3 helpers de fixtures de los tests nuevos (no el código de
producción) tenían columnas `NOT NULL` reales de `taxonomy_categories`/`taxonomy_term_cpv_relations`
que no se estaban seteando (`source_version`, `cpv_code`, `level`, `weight`, `source`) — corregidos
tras el primer intento real contra la BD, confirmando por qué correr contra datos reales (no
mocks) importa: estos NOT NULL no eran obvios desde el código de producción, que siempre inserta
esas columnas correctamente.

---

## Regression Comparison

Re-corrida real de las 32 queries contra el Worker desplegado (`node scripts/regression-suite.mjs`),
comparado campo por campo contra `audit/regression_baseline_2026-09-23.json` (Phase A):

- **31/32 queries idénticas** (mismo `direct_company_count`, `diagnostic_flags`, `top_empresa_ids`).
- **1/32 (`represas`)** devolvió `HTTP 401` en la primera corrida — se generó un `DEBUG_TOKEN`
  nuevo (con autorización previa ya establecida en Phase A, mismo token de Cloudflare todavía
  activo, no revocado por el usuario) justo antes de correr la suite, y la primera query golpeó
  probablemente un nodo de borde de Cloudflare que aún no tenía el secreto propagado — el mismo
  patrón de propagación ya visto con el reset de contraseña de Supabase en Phase A. **Confirmado:**
  reintentar `represas` sola inmediatamente después devolvió `200` con el resultado idéntico a la
  línea base. No es una regresión de búsqueda, es una condición de carrera de propagación de
  secretos, ya resuelta y explicada.
- Ningún cambio de Phase B toca `perfilafiliados-mcp` (el Worker TypeScript) — todo el código nuevo
  vive en Laravel/PHP, así que un resultado idéntico es lo esperado, no una coincidencia.

---

## Database Before/After

| Tabla | Antes de Phase B | Después de escribir código + correr 56 tests | Δ |
|---|---|---|---|
| `taxonomy_term_cpv_relations` | 9.749 | 9.749 | 0 |
| `taxonomy_canonical_concepts` | 79 | 79 | 0 |
| `taxonomy_term_concepts` | 142 | 142 | 0 |
| `taxonomy_concept_relations` | 0 | 0 | 0 |
| `taxonomy_candidate_concept_links` | 0 | 0 | 0 |

**Cero drift confirmado en vivo contra Supabase — ninguna fila de producción de Phase 3 se creó,
modificó ni eliminó.**

---

## Known Data Gaps

- `NO_CRAWLER_EVIDENCE` sigue aplicando a `predictAffectedCompanies()` — el crawler (Fase E) no
  existe, `company_term_matches` está vacía, esperado.
- Campos 4/5/6 del dry-run (provenance/conflicto/requiere-revisión explícitos para candidatos
  término→concepto) siguen parciales — no eran el alcance de Phase B.
- `taxonomy_concept_relation_types.source_concept_types`/`target_concept_types` (arrays que
  podrían restringir qué tipos de concepto pueden combinarse con cada relación) están seedeados en
  `NULL` para los 5 tipos — el proponedor de Phase B no los usa como filtro todavía (no hay
  ninguna regla real que aplicar mientras estén vacíos); si se llenan en el futuro, habría que
  sumar ese chequeo.

---

## Phase C Readiness

**No se implementó `--apply` ni ningún código de escritura masiva — solo diseño, per instrucción
explícita.** Lo que Phase B deja listo para cuando se autorice Phase C:

- **Modelo de propuesta estructurado** (B1) y **de impacto** (B2) ya en el formato que un futuro
  `--apply` necesitaría leer para decidir qué escribir — no hay que rediseñar el contrato de datos.
- **Validación de seguridad completa** (`validateConceptRelationProposal()`) ya implementada y
  testeada — Phase C puede/debe llamarla ANTES de cualquier INSERT real, no reinventarla.
- **Camino de aprobación humana** (B3) ya production-ready para el caso término→concepto-nuevo —
  Phase C de "escritura masiva" debería seguir sin tocar este camino manual, son necesidades
  distintas (revisión de 1 caso vs. lote).

Pendiente de diseñar en Phase C (no iniciado):
- Modelo de transacciones para lotes (no una fila a la vez como el camino manual).
- Ordenamiento de escritura (¿conceptos nuevos antes que sus relaciones? sí, por la FK).
- Invariante `APPLY(DRY_RUN_RESULT)` = exactamente lo revisado, nunca un recálculo independiente.
- Versionado de taxonomía, manejo de fallo parcial, validación post-escritura.

---

## Risks

- El proponedor de `RELATED_TO` corre todo-contra-todo sobre los conceptos vistos en cada
  `dryRun()` — con 79 conceptos esto es barato (≤3.081 pares en el peor caso), pero si el catálogo
  de conceptos canónicos creciera a un orden de magnitud mayor, este método necesitaría acotarse
  (mismo principio de "retrieval acotado, nunca all-pairs" que ya sigue el resto del Builder). No
  es un riesgo hoy, es una nota para cuando el catálogo crezca.
- `predictAffectedCompanies()` no distingue `es_principal`/confirmado vs. sugerido al contar
  "directo" — cuenta cualquier fila de `empresa_taxonomy_category` sin importar `origen`. Es una
  simplificación deliberada y documentada, no un bug, pero vale la pena revisar si el negocio
  quiere que "sugerido, no confirmado" cuente distinto en una fase futura.

## Remaining Work

1. Wiring de UI en Filament para `resolveNewConceptProposal()` (hoy solo existe la capa de
   servicio, testeada — el Resource `TaxonomyCandidateConceptLinkResource` todavía no tiene botones
   para MAP_TO_EXISTING/CREATE_NEW, solo Approve/Reject del flujo existente). No es parte del
   pedido explícito de Phase B (que pedía el "code path", cumplido) pero es necesario antes de que
   un revisor humano real pueda usarlo desde el panel.
2. Los campos 4-6 del dry-run (provenance/conflicto/revisión explícitos) — fuera de alcance de
   Phase B, quedan para una fase de refinamiento futura si se decide que hacen falta.
3. `source_concept_types`/`target_concept_types` del catálogo de tipos de relación — hoy no se
   consumen como filtro (están en NULL); si se llenan, el proponedor de B1 debería empezar a
   respetarlos.
