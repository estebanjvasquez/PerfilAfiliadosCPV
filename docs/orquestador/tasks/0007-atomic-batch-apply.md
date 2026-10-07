# TASK-0007 — APPLY ATÓMICO POR LOTE

**Estado:** READY_FOR_REVIEW (ronda 3 — los dos agujeros residuales del camino de ejecución cerrados)
**Abierta por:** Issue #2 comentario [`5997693379`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-5997693379)
(estebanjvasquez, 2026-10-05T15:33:13Z)
**Re-audit de la ronda 1:** comentario [`6011317053`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6011317053)
(2026-10-06T07:13:43Z) — `CORRECTIONS_REQUIRED / EXECUTION-GOVERNANCE GATE`. Las tres observaciones se
aceptaron sin reservas; el detalle de cada corrección está en la **§8** de
`audit/phase7_task0007_batch_apply_2026-10-05.md`.
**Re-audit de la ronda 2:** comentario [`6015273402`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/issues/2#issuecomment-6015273402)
(2026-10-06T11:27:52Z) — `CORRECTIONS_REQUIRED / FINAL EXECUTION-PATH HARDENING`. Dos agujeros
residuales, aceptados sin reservas; detalle en la **§9** del mismo audit.
**Checkpoint base aprobado:** `4e671fa7b1b0a3635157e416be9ddbf0e9e69de7`
**Cierre previo:** TASK-0006E = PASS/CLOSED en el comentario `5994449681`

**Los dos agujeros residuales cerrados en la ronda 3:**

1. **El `applyBatch()` público podía saltearse el manifiesto en staging.** La compuerta de
   autorización corría dentro de `if ($manifest !== null)`, así que `applyBatch($ids, $referencia)` —una
   invocación directa desde Tinker, otro comando o un llamador futuro— abría la transacción en staging
   **sin manifiesto, sin hash autorizado y sin atadura al conjunto autorizado**. Ahora el manifiesto es
   **obligatorio en el servicio** para todo entorno operativo (`BATCH_MANIFEST_REQUIRED`, antes de la
   transacción y antes de cualquier lock). La excepción sin manifiesto queda sólo para `testing`, y
   documentada como SÓLO DE TESTS.
2. **El hash se validaba después de la confirmación del CLI.** El CLI sólo miraba que la opción no
   estuviera vacía antes del banner destructivo y del `confirm()`; forma y coincidencia se validaban
   dentro de `applyBatch()`, o sea **después**. Se podía pedirle a una persona que confirmara un APPLY
   con un hash equivocado para rechazarlo recién después. Ahora hay **un solo** validador
   (`authorizationFingerprintBlocker()`, público y de solo lectura): el CLI lo llama antes del banner
   y del prompt, y el servicio lo vuelve a llamar antes de la transacción.

**Las tres compuertas cerradas en la ronda 2:**

1. **Un manifiesto completo podía ejecutar sólo un subconjunto de sus ids.** `--id` sobrescribía los
   ids del manifiesto y nadie exigía igualdad de conjuntos, así que
   `--manifest=<FULL aprobado> --id=491 --execute` verificaba con éxito y ejecutaba sólo #491 — y como
   #491 cambia el grafo, las otras once quedaban obsoletas. Ahora `--id` está prohibido con
   `--execute` y el servicio exige igualdad exacta (`BATCH_MANIFEST_REQUEST_MISMATCH`).
2. **Datos compartidos reales se podían ejecutar desde `APP_ENV=local`.** Los artefactos de la ronda 1
   prueban que el `local` de la estación lee el dataset real. Ahora el único entorno operativo es
   `staging`; `testing` es la excepción de tests automatizados y `local` conserva sólo preview y
   generación de manifiestos.
3. **La autorización no estaba atada al hash del manifiesto.** Ahora
   `--expect-manifest-fingerprint` es obligatorio para ejecutar, se compara contra el manifiesto
   cargado antes de abrir la transacción, y **no** se deduce del archivo.

**APPLY REAL = NO AUTORIZADO.** Esta ronda autoriza el código de ejecución, los tests, la generación
de solo lectura del manifiesto, el preflight de lote de solo lectura contra datos reales y el deploy
del runtime a staging. La ejecución real exige una autorización humana NUEVA y explícita, posterior a
la auditoría de esta ronda, que cite el conjunto exacto de propuestas y el `manifest_fingerprint`.

> **ACTUALIZACIÓN 2026-10-07 — esa autorización llegó y el APPLY se ejecutó.** El párrafo de arriba
> describe el alcance de las rondas de implementación y se conserva como tal. La autorización quedó
> registrada en Issue #2 comentario `6032819854` (procedencia según el protocolo del comentario
> `6032759610`), citando `staging`, el conjunto exacto y el fingerprint `eb7d1467…f3702`; el lote
> ejecutó `BATCH_APPLIED` con 12 propuestas en 11 unidades y una sola transacción. Validación
> post-APPLY y el único punto que quedó bloqueado:
> **`audit/phase7_task0007_post_apply_closure_2026-10-07.md`**.

---

## 1. El hueco semántico que abre la tarea

El orquestador lo re-auditó antes de abrir la tarea y el hallazgo es correcto:

`CanonicalConceptBuilderService::dryRunInputFingerprint()` incluye `conceptGraphFingerprint()` —que
hashea `taxonomy_canonical_concepts` **y** `taxonomy_term_concepts`— más una señal de versión de
`taxonomy_concept_relations`. Varias propuestas de la cola real mutan deliberadamente esas entradas:

| propuesta | qué escribe | ¿cambia el fingerprint? |
|---|---|---|
| #491 MAP_TO_EXISTING | 1 fila en `taxonomy_term_concepts` | **sí** |
| #629/#630 CREATE_NEW (grupo) | 1 concepto canónico + 2 `taxonomy_term_concepts` | **sí** |
| #631/#632 REJECT de relación | `taxonomy_concept_relations.status` | **sí** |
| #492-#495, #1688-#1690 CONTEXT_REQUIRED | sólo `taxonomy_candidate_concept_links` | no |

Por lo tanto `apply(491) -> apply(492) -> ...` **no es una estrategia de ejecución válida**: en cuanto
la primera escritura cambia el grafo, el resto de la cola aparece `STALE_TAXONOMY_STATE` aunque haya
sido revisada legítimamente contra el MISMO snapshot — y `apply()` registra la obsolescencia con
`abort()`, que es TERMINAL. Re-congelar está bloqueado por el índice único parcial, así que un loop
ingenuo **quemaría decisiones humanas una por una, sin recuperación**.

No es un defecto de datos y no invalida el preflight final de TASK-0006E. Es que las 12 propuestas no
son 12 ejecuciones independientes: son **un conjunto revisado contra un snapshot compartido**, y
`apply()` fue diseñado para un payload.

## 2. Qué se implementó

### `ReviewedProposalService::applyBatch(array $proposalIds, string $authorizationReference, ?array $manifest = null)`

Una transacción para todo el lote, con este contrato (los 9 requisitos de la PARTE 1):

1. **Una** transacción (`DB::connection('pgsql')->transaction()`).
2. El baseline se computa **una sola vez**, antes de cualquier escritura.
3. El conjunto pedido se carga y bloquea completo, en orden determinístico de id.
4. **Toda** unidad se valida contra ese mismo baseline **antes** de la primera escritura.
5. Sólo si el lote entero pasa ocurre alguna escritura.
6. Empezadas las escrituras no se recomputa el fingerprint global — y no porque se desactive una
   compuerta, sino porque **en la fase de escritura ya no queda validación por correr**.
7. Cualquier fallo devuelve un bloqueo de lote con **cero escrituras y cero propuestas ABORTADAS**.
   `applyBatch()` no llama a `abort()` por ningún camino (probado estructuralmente por test).
8. Cualquier excepción en la fase de escritura revierte el lote completo; un desenlace inesperado de
   un escritor se convierte en excepción a propósito, porque una cola parcialmente APPLIED no es un
   desenlace aceptable.
9. `apply()` conserva su semántica intacta para una sola propuesta.

**No es un loop alrededor de `apply()`** y **no es un segundo motor de reglas**: la validación de cada
unidad es `evaluateApplicability()`, la misma función que corren `apply()` y `preflight()`, con
`lockRows: true` y el baseline del lote. Lo único propio del lote es (a) la validación de la FORMA del
conjunto y (b) la traducción del bloqueo de una propuesta al vocabulario de lote, que vive en un único
`match` que **lanza** si aparece un bloqueo sin mapear.

El único cambio en código heredado es un parámetro nuevo y opcional en `evaluateApplicability()`:
`?string $baselineFingerprint = null`. Con `null` —que es lo que pasan `apply()` y `preflight()`— el
comportamiento es byte por byte el anterior.

### Unidades de ejecución (PARTE 2)

12 filas → **11 unidades**, porque #629/#630 son UNA unidad indivisible. La entrada de un grupo es su
miembro de id más bajo, así que entrar por cualquiera produce la misma ejecución; el escritor de grupo
(`writeBilingualGroupCreateNew()`) se llama **una sola vez** y marca APPLIED a los dos miembros. Un
lote que pida medio grupo se rechaza en solo lectura con `BATCH_GROUP_INCOMPLETE`.

### Manifiesto (PARTE 4) — `ReviewedProposalBatchManifest`

Ata los ids exactos, la decisión, el `payload_fingerprint`, el `taxonomy_state_fingerprint`, el
candidato/relación de origen, el `proposal_group_id`, las unidades de ejecución, el baseline, la forma
de la cola protegida y el **alcance**. Todo eso entra en un `manifest_fingerprint` determinístico;
`generated_at`, el entorno y las notas quedan **fuera** del hash, porque son evidencia de cuándo se
generó, no parte de lo que se autoriza — si el hash cambiara al regenerar, no se podría citar en una
autorización.

`verify()` rechaza con: `BATCH_TAMPER_DETECTED` (manifiesto editado, o identidad atada distinta),
`BATCH_BASELINE_STALE` (el grafo se movió), `BATCH_QUEUE_DRIFT` (falta una propuesta, dejó de estar
`PENDING_APPLY`, apareció una `PENDING_APPLY` fuera del manifiesto, o cambió la forma de la cola
protegida) y `BATCH_ALREADY_EXECUTED` (replay del manifiesto completo).

El **alcance** (`FULL_PENDING_QUEUE` vs `EXPLICIT_IDS`) no es un atajo: la regla «apareció una
`PENDING_APPLY` fuera del manifiesto» sólo es un hallazgo si el manifiesto declara cubrir la cola
entera. En un manifiesto de subconjunto, que existan otras `PENDING_APPLY` **es** la definición del
subconjunto. El alcance va dentro del fingerprint, así que un subconjunto no se puede re-etiquetar
como cola completa; y el comando se niega a ejecutar un subconjunto salvo que se lo diga
explícitamente con `--allow-subset-manifest`. El manifiesto real de esta ronda es
`FULL_PENDING_QUEUE`.

Cero semántica de negocio en el runtime: ni una mención a petroleum/refinery/pipeline ni a los ids
reales. El manifiesto se **genera leyendo la cola**; los 12 ids viven en el artefacto JSON.

### Lock común de ejecución (PARTE 5)

`executionAdvisoryLockKey()` — un `pg_advisory_xact_lock` único, tomado como **primera acción de la
transacción** por `applyBatch()` **y** por `apply()`. Orden de locks idéntico en los dos caminos:

```
1) lock común de ejecución C2
2) advisory locks de grupo, ordenados por clave
3) filas de propuesta, ordenadas por id
4) filas fuente, en el orden de las unidades
```

Sin él, un `apply()` suelto podría cambiar el grafo **entre** la validación y la escritura de un lote
en curso, y los locks de fila no alcanzan: el apply concurrente puede entrar por una propuesta que el
lote no pidió y mover el grafo global igual. El lock es único y global al servicio, así que no puede
tomarse «cruzado»: tomándolo primero, la corrección de TASK-0006B (clave común por grupo antes de los
locks de fila) no se debilita, se le agrega un lock estrictamente anterior.

No se amplió a un lock de mantenimiento: `freeze()`, `confirm()`, `preflight()`, `previewBatch()` y
`supersedeStaleProposal()` **no** lo toman — los dos primeros no publican taxonomía, los dos
siguientes no escriben, y la supersesión sólo escribe `status` + rastro de filas que ya protege con
`lockForUpdate()`. Ampliarlo haría que una revisión humana pudiera quedar esperando una ejecución.

### Preflight de lote (PARTE 6) — `previewBatch()`

El MISMO camino de validación, sin entrar nunca a la fase de escritura, sin locks y midiendo sus
propios statements (`write_statements_observed`). Reporta manifest fingerprint, baseline y fingerprint
actual, ids, unidades, miembros de grupo, validez de payload por propuesta, estado/drift de la fuente,
compuertas de confirmación, validación de relación/grupo, write-set proyectado agregado, conteos
protegidos proyectados y bloqueos.

Diferencia real con `preflightAll()`, que ya existía: `preflightAll()` evalúa 12 propuestas por
separado, cada una contra el fingerprint del instante, y reporta el write-set COMPLETO del grupo en
CADA miembro (sumar las filas del JSON da 49 y es incorrecto). `previewBatch()` evalúa el CONJUNTO:
un baseline único, unidades deduplicadas y una proyección agregada — **40 filas, el grupo contado una
vez**, que es la cifra que la PARTE 7 proyecta.

### Comandos

| comando | qué hace |
|---|---|
| `taxonomy:reviewed-proposal-batch-manifest [--id=*] [--out=]` | genera el manifiesto, SOLO LECTURA, con detector de escrituras que falla si observa una |
| `taxonomy:apply-reviewed-proposal-batch [--manifest=] [--id=*] [--json=]` | **por defecto** preflight de lote de solo lectura |
| `… --execute --manifest= --authorized-by= --expect-environment= [--force] [--allow-subset-manifest]` | APPLY atómico real |

El modo seguro es el predeterminado: una invocación por descuido no puede aplicar nada.
`--expect-environment` es obligatorio para ejecutar y es lo contrario de una declaración de confianza:
el entorno real se auto-captura con `app()->environment()` (nunca lo provee quien llama) y el comando
se niega si no coincide con lo que el operador escribió. `production` está prohibido **en el
servicio**, no sólo en el comando: `BATCH_ALLOWED_ENVIRONMENTS = ['local', 'testing', 'staging']`.

## 3. Resultado del preflight de lote REAL (solo lectura)

Artefactos vigentes (ronda 3): `audit/task0007_batch_preflight_2026-10-06_r3.json` y
`audit/task0007_batch_manifest_2026-10-06_r3.json`. Los de las rondas 1 y 2 se conservan como registro
histórico.

**`manifest_fingerprint` = `eb7d14672a1051cdb4fcb98e3e8c5bd68ef01e0910193c004843f565686f3702`**
(alcance `FULL_PENDING_QUEUE`). Los dos re-audits pidieron explícitamente no asumir que el hash
anterior siguiera siendo autoritativo: se **regeneró** en cada ronda y el valor medido es el mismo,
porque ninguna de las dos tocó lo que el manifiesto ata ni cómo se hashea
(`generate()`/`fingerprintFor()`), sino las compuertas que lo verifican y el orden en que se evalúan.
Y la cola real no se movió.

- 12 propuestas aceptadas, **11 unidades de ejecución**, **cero bloqueos** — exactamente la
  clasificación esperada por la PARTE 6.
- `write_statements_observed = 0`.
- baseline = `c236bc5159ae4421a72dc64b1daa5b850b77a40b1c19425a3c6ac6762d305da2` (el mismo del cierre
  de TASK-0006E).
- write-set proyectado: **40 filas** — 1 concepto canónico, 3 TERM→CONCEPT, 10 candidatos, 2
  relaciones, 12 propuestas, 12 filas de auditoría, **0 TERM→CPV**.
- conteos proyectados idénticos a la PARTE 7: 81→82 conceptos, 142→145 TERM→CONCEPT, 9749 TERM→CPV
  sin cambio, 10 candidatos pendientes → 3 published + 7 context_required, 2 relaciones candidate → 2
  rejected y 0 approved, PENDING_APPLY 12→0, APPLIED 0→12, SUPERSEDED 3 sin cambio, ABORTED 0.

## 4. Lo que NO se hizo (y es deliberado)

- **Ningún APPLY real.** Las 12 propuestas siguen `PENDING_APPLY`, los 10 candidatos `pending`, las 2
  relaciones `candidate`, 0 filas con `applied_at`.
- Ninguna fila real cambió de estado; #420/#421/#422 no se tocaron.
- Ningún payload/fingerprint congelado se editó.
- Sin merge a `main`, sin deploy a producción, sin migración (esta tarea **no necesita** cambios de
  esquema: `applyBatch()` no agrega columnas ni estados).

## 5. Comentario `5997693379` — verbatim

```text
[ORCHESTRATOR — OPEN TASK-0007 / ATOMIC BATCH APPLY PREPARATION — REAL APPLY NOT YET AUTHORIZED]

TASK-0006E is CLOSED/PASS in comment `5994449681`.

The taxonomy owner has now authorized OPENING TASK-0007 and development of the execution
path/instructions. This comment does **NOT** authorize the real APPLY itself. Real execution against
the shared/staging data requires one later, explicit owner authorization after this task's
implementation and final batch preflight are audited.

CURRENT APPROVED BASELINE
Repository checkpoint: `4e671fa7b1b0a3635157e416be9ddbf0e9e69de7`.

Current live executable queue:
- #491 — MAP_TO_EXISTING — candidate 272 (`pipeline`)
- #492 — CONTEXT_REQUIRED — candidate 266 (`exploration`)
- #493 — CONTEXT_REQUIRED — candidate 267 (`upstream`)
- #494 — CONTEXT_REQUIRED — candidate 268 (`midstream`)
- #495 — CONTEXT_REQUIRED — candidate 269 (`downstream`)
- #629/#630 — one bilingual CREATE_NEW group — candidates 270/271 (`refinery` + `refinería`) → exactly ONE canonical concept
- #631 — REJECT — concept relation 61
- #632 — REJECT — concept relation 62
- #1688 — CONTEXT_REQUIRED — candidate 263 (`petroleum`)
- #1689 — CONTEXT_REQUIRED — candidate 264 (`crude oil`)
- #1690 — CONTEXT_REQUIRED — candidate 265 (`oil and gas`)

Historical only, never part of this executable batch:
- #420/#421/#422 = SUPERSEDED

Exact current taxonomy fingerprint from the final read-only preflight:
`c236bc5159ae4421a72dc64b1daa5b850b77a40b1c19425a3c6ac6762d305da2`

Protected live state at task opening:
- candidate links = 10; all 10 pending
- concept relations = 2; both candidate
- TERM→CONCEPT = 142
- canonical concepts = 81
- TERM→CPV = 9749
- reviewed proposals = 15
- PENDING_APPLY = 12
- SUPERSEDED = 3
- APPLIED = 0
- ABORTED = 0
- published candidates = 0
- approved concept relations = 0

======================================================================
CRITICAL DESIGN FINDING — DO NOT LOOP THE EXISTING SINGLE apply()
======================================================================

Before opening this task I re-audited the execution semantics.

`CanonicalConceptBuilderService::dryRunInputFingerprint()` includes:
- `conceptGraphFingerprint()`, which hashes BOTH `taxonomy_canonical_concepts` and `taxonomy_term_concepts`;
- a version signal for `taxonomy_concept_relations`;
- the other builder dependencies already governed by C2.

Several proposals in this queue intentionally mutate those fingerprint inputs:
- #491 inserts a TERM→CONCEPT row;
- #629/#630 create one canonical concept + two TERM→CONCEPT rows;
- #631/#632 change `taxonomy_concept_relations`.

Therefore a naïve loop:
`apply(491) -> apply(492) -> ...`
is NOT a valid execution strategy.

After the first graph-changing APPLY, the current global fingerprint changes. Subsequent proposals,
even though legitimately reviewed against the SAME original snapshot, would then appear
`STALE_TAXONOMY_STATE` and could be terminally ABORTED by the current single-proposal path.

This is not a data defect and does not invalidate the final preflight. It is a batch-execution
semantic gap: the 12 proposals form one reviewed execution set against one shared snapshot, while
`apply()` was designed for a single payload.

TASK-0007 exists first to close that gap safely. DO NOT attempt real APPLY until it is closed and
re-audited.

======================================================================
PART 1 — IMPLEMENT ATOMIC BATCH APPLY
======================================================================

Add a governed batch operation, e.g.
`ReviewedProposalService::applyBatch(array $proposalIds, string $authorizationReference)`
plus a CLI such as:
`taxonomy:apply-reviewed-proposal-batch`

Do NOT implement it as a loop around public `apply()`.

Required contract:

1. ONE database transaction for the whole batch.
2. Compute the current taxonomy fingerprint ONCE at the batch baseline, before any writes.
3. Load and lock the full requested reviewed-proposal set in deterministic id order.
4. Validate EVERY execution unit against that same baseline BEFORE the first write.
5. Only if the entire batch passes validation may any write occur.
6. Once writes start, do not recompute the global stale fingerprint between members; the graph changes
   produced by earlier members in this SAME authorized batch are expected, not external drift.
7. Any validation failure before the write phase returns a batch blocker with ZERO writes and ZERO
   proposal ABORTs.
8. Any exception during the write phase rolls back the whole transaction. No partial APPLIED queue is
   acceptable.
9. Existing single-proposal `apply()` remains supported and retains its existing semantics for true
   single-proposal use.

======================================================================
PART 2 — EXECUTION UNITS / GROUP SEMANTICS
======================================================================

The 12 proposal rows are NOT 12 independent write units.

#629/#630 are ONE indivisible bilingual group. The batch must:
- require both live group members when either one is included;
- validate both payload fingerprints;
- validate group identity/convergence exactly as the current grouped evaluator does;
- execute the group ONCE;
- create exactly ONE canonical concept;
- create exactly TWO TERM→CONCEPT links;
- mark both #629 and #630 APPLIED;
- never call the group writer twice.

A partial group request must be rejected read-only with an explicit blocker such as:
`BATCH_GROUP_INCOMPLETE`.

Deduplicate execution units deterministically.

======================================================================
PART 3 — SHARED VALIDATION, NOT A SECOND RULE ENGINE
======================================================================

Reuse the already-approved validation primitives extracted in TASK-0006D. Do not create an independent
validator that can drift from single `apply()`.

Before the first write, validate for every relevant row/unit:
- proposal is PENDING_APPLY;
- immutable payload fingerprint is valid;
- frozen taxonomy fingerprint equals the ONE batch baseline fingerprint;
- human-confirmation requirement is satisfied;
- source entity exists and is still in the compatible state;
- frozen source snapshot has no drift;
- MAP_TO_EXISTING target concept still exists;
- grouped bilingual integrity and per-member payload integrity hold;
- concept-relation validation where applicable;
- REJECT relation semantics remain non-publishing;
- #420/#421/#422 are not accepted as executable inputs.

The batch validation result must identify the exact proposal/unit that blocks execution and why.

Do not mutate a blocked proposal merely to report the blocker.

======================================================================
PART 4 — BATCH MANIFEST / EXACT QUEUE PROTECTION
======================================================================

Create a read-only batch manifest from the approved queue. It must bind at least:
- the exact 12 proposal ids;
- each proposal's decision;
- each proposal's payload_fingerprint;
- each proposal's taxonomy_state_fingerprint;
- source candidate/relation id;
- proposal_group_id where present;
- baseline current taxonomy fingerprint;
- generation timestamp;
- a deterministic manifest fingerprint/hash.

For the eventual real execution, the batch command must reject if:
- any expected proposal disappeared;
- any expected proposal is no longer PENDING_APPLY;
- any extra PENDING_APPLY proposal appeared outside the authorized manifest;
- payload/group/source identity differs;
- current baseline fingerprint differs;
- protected queue shape differs.

Use explicit errors such as:
- BATCH_QUEUE_DRIFT
- BATCH_BASELINE_STALE
- BATCH_TAMPER_DETECTED
- BATCH_SOURCE_DRIFT
- BATCH_CONFIRMATION_REQUIRED
- BATCH_GROUP_INCOMPLETE
- BATCH_RELATION_INVALID
- BATCH_ALREADY_EXECUTED

Do not hardcode petroleum/refinery/pipeline semantics into runtime code. The manifest is execution
data/evidence, not business logic.

======================================================================
PART 5 — CONCURRENCY / LOCK ORDER
======================================================================

The batch must be safe against concurrent C2 execution.

Introduce one common transaction-scoped C2 EXECUTION advisory lock used by:
- the new `applyBatch()`; and
- the existing public single `apply()`.

Lock order must be consistent everywhere:
1. common C2 execution advisory lock;
2. grouped advisory lock(s), deterministic/sorted;
3. reviewed-proposal rows, sorted id;
4. source rows / referenced rows in deterministic order.

The purpose is to prevent a concurrent single APPLY from changing the graph between batch validation
and batch write, and to avoid deadlock inversion with the existing bilingual group lock.

Add concurrency/lock-order tests or an equivalent deterministic fixture proving a single APPLY cannot
interleave with an active batch APPLY.

Do not broaden this into a general application-wide maintenance lock.

======================================================================
PART 6 — DRY-RUN / BATCH PREFLIGHT
======================================================================

Add a side-effect-free batch preflight that uses the SAME batch validation path but never enters the
write phase.

It must report:
- manifest fingerprint;
- baseline/current taxonomy fingerprint;
- exact proposal ids;
- execution units;
- grouped members;
- per-proposal payload validity;
- source state/drift;
- confirmation gates;
- relation/group validation;
- projected write set;
- projected final protected counts;
- blockers;
- `write_statements_observed = 0`.

Run this read-only against the REAL current queue.

Expected classification, if nothing changes:
- 12 proposal rows accepted;
- 11 execution units because #629/#630 are one group;
- ZERO blockers.

======================================================================
PART 7 — EXPECTED REAL OUTCOME IF LATER AUTHORIZED
======================================================================

This is a projection, NOT authorization.

If the exact current 12-row manifest is later authorized and applies atomically, expected published
result:

Candidates:
- #263 petroleum → context_required
- #264 crude oil → context_required
- #265 oil and gas → context_required
- #266 exploration → context_required
- #267 upstream → context_required
- #268 midstream → context_required
- #269 downstream → context_required
- #270 refinery → published
- #271 refinería → published
- #272 pipeline → published

Relations:
- #61 → rejected
- #62 → rejected

Taxonomy:
- canonical concepts: 81 → 82
- TERM→CONCEPT: 142 → 145
- TERM→CPV: MUST remain 9749
- exactly one new bilingual concept for refinería/refinery
- pipeline maps to the already-reviewed existing concept from #491
- no concept relation is approved by #631/#632

Reviewed proposals:
- PENDING_APPLY: 12 → 0
- APPLIED: 0 → 12
- SUPERSEDED: stays 3 (#420/#421/#422)
- ABORTED: stays 0

Expected logical write footprint from the approved preflight projection: 40 row-level writes/inserts
across the existing tables, subject to idempotent reuse if an already-existing exact TERM→CONCEPT row
is encountered. The batch audit artifact must explain any deviation from 40 before real execution.

======================================================================
PART 8 — AUTHORIZATION / ENVIRONMENT
======================================================================

TASK-0007 ROUND 1 AUTHORIZES:
- batch execution code;
- tests;
- read-only manifest generation;
- read-only batch preflight against real shared data;
- staging deployment of the runtime code through the existing workflow;
- fixture-only batch execution tests.

TASK-0007 ROUND 1 DOES NOT AUTHORIZE:
- real APPLY of any of the 12 rows;
- changing any real candidate/relation status;
- creating the real refinería/refinery concept;
- real TERM→CONCEPT inserts;
- marking any real proposal APPLIED or ABORTED;
- PUBLISH outside fixture/test transactions;
- production deploy;
- merge to main;
- editing the 12 frozen payloads/fingerprints;
- touching #420/#421/#422.

The eventual real command must auto-capture the target environment and must refuse an unexpected
environment. For this project phase, execution authorization will be scoped explicitly to the
shared/staging environment; production remains prohibited.

======================================================================
PART 9 — REQUIRED TESTS
======================================================================

At minimum:

A. HAPPY PATH
- fixture batch containing multiple CONTEXT_REQUIRED proposals + MAP_TO_EXISTING + one bilingual
  CREATE_NEW group + REJECT relations;
- all validate against one baseline;
- all commit atomically;
- graph-changing early writes do NOT falsely stale later members;
- expected final state matches the approved projection.

B. WHY SINGLE-LOOP IS INVALID
- fixture proving that sequential independent single `apply()` calls across two proposals that both
  depend on the same frozen graph would stale the later proposal after the first graph-changing write;
- this test documents the reason TASK-0007 needs batch semantics.

C. ZERO-WRITE BLOCKERS
For each representative blocker (tamper, stale baseline, source drift, missing confirmation,
incomplete group, invalid relation, queue drift):
- batch returns blocked;
- 0 candidates/relations/taxonomy rows changed;
- 0 proposals become APPLIED;
- 0 proposals become ABORTED;
- 0 execution audit rows are written.

D. GROUP
- #629/#630-equivalent fixture executes once;
- one concept only;
- both proposals APPLIED;
- partial group rejected;
- sibling payload tamper blocks entire batch with zero writes.

E. CONCURRENCY / IDEMPOTENCY
- batch and single apply cannot interleave;
- replay after a successful full batch reports already executed/idempotent state and creates no
  duplicate concept/link/audit publication;
- deterministic lock order.

F. INVARIANTS
- no TERM→CPV write;
- SUPERSEDED rows remain untouched;
- no published relation from REJECT;
- unique PENDING_APPLY constraints remain effective.

Existing C2 / confirmation / group / supersession / preflight / UX suites must remain green.

======================================================================
PART 10 — ROUND-1 COMPLETION CONTRACT
======================================================================

After implementation + tests + staging deployment + REAL READ-ONLY batch preflight:

STOP.

Post the audit artifacts and return:

READY_FOR_REVIEW
Issue #2
HEAD <exact-sha>

The audit must classify:
A) inherited approved evidence;
B) newly executed code/test/staging/read-only evidence;
C) any invalidated prior gate;
D) not-applicable gates.

Do NOT execute the real batch in the same round.

After the orchestrator audits ROUND 1, the taxonomy owner will be asked for a NEW explicit
authorization containing the exact manifest fingerprint / proposal set. Only that later authorization
may permit the real atomic APPLY.

TASK-0007 = OPEN.
REAL APPLY = NOT AUTHORIZED.
```

## 6. Qué falta para ejecutar de verdad

Una autorización humana nueva y explícita en Issue #2 que cite:

- el conjunto exacto de ids `[491, 492, 493, 494, 495, 629, 630, 631, 632, 1688, 1689, 1690]`;
- el `manifest_fingerprint` `eb7d14672a1051cdb4fcb98e3e8c5bd68ef01e0910193c004843f565686f3702`;
- el entorno autorizado (compartido/staging; production sigue prohibido).

El comando que la ejecutaría, el día que esa autorización exista — **corregido por la ronda 2**:

```bash
php artisan taxonomy:apply-reviewed-proposal-batch \
  --manifest=audit/task0007_batch_manifest_2026-10-06_r3.json \
  --execute --authorized-by="Issue #2 comment <id>" \
  --expect-manifest-fingerprint=eb7d14672a1051cdb4fcb98e3e8c5bd68ef01e0910193c004843f565686f3702 \
  --expect-environment=staging
```

Lo que cambió respecto de la ronda 1 y vale la pena leer antes de ejecutar:

- `--id` ya **no se puede usar** con `--execute`. Un manifiesto autoriza UN conjunto atómico.
- `--expect-manifest-fingerprint` es **obligatorio**: el hash autorizado se cita aparte del archivo, y
  se valida —falta, forma y coincidencia— **antes** del banner destructivo y **antes** de preguntar.
- Sólo **`staging`** puede ejecutar. Correrlo desde `local` se rechaza, aunque `local` sí puede
  generar el manifiesto y correr el preflight (las dos cosas son de solo lectura).
- El manifiesto no es sólo una exigencia del comando: **el servicio** lo exige en todo entorno
  operativo, así que una invocación directa de `applyBatch()` sin manifiesto tampoco ejecuta.

Si entre hoy y ese día cambia cualquier cosa —una propuesta más, un candidato resuelto por otra vía,
un concepto nuevo— el manifiesto se rechaza solo (`BATCH_QUEUE_DRIFT` / `BATCH_BASELINE_STALE`) con
cero escrituras, y hay que regenerarlo y reautorizarlo. Eso es lo que hace que la autorización
signifique algo.

Hasta que eso exista, un lote sin bloqueos significa «hoy nada lo impide», nunca «aprobado para
ejecutarse».
