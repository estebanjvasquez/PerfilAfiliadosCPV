# TASK-0007 — APPLY ATÓMICO POR LOTE: implementación, manifiesto y preflight de solo lectura

**Tarea:** Issue #2 comentario `5997693379` — *ORCHESTRATOR — OPEN TASK-0007 / ATOMIC BATCH APPLY
PREPARATION — REAL APPLY NOT YET AUTHORIZED*
**Re-audit de la ronda 1:** comentario `6011317053` — *CORRECTIONS_REQUIRED / EXECUTION-GOVERNANCE
GATE*. Las tres compuertas corregidas están en la **§8**.
**Checkpoint base:** `4e671fa7b1b0a3635157e416be9ddbf0e9e69de7` (TASK-0006E PASS/CLOSED en `5994449681`)
**Rama:** `feature/upgrade-filament-v3`
**Fecha:** 2026-10-05 (ronda 1) / 2026-10-06 (ronda 2, correcciones)
**Definición de la tarea (verbatim):** `docs/orquestador/tasks/0007-atomic-batch-apply.md`

**APPLY REAL = NO AUTORIZADO Y NO EJECUTADO.** Al cierre de las dos rondas las 12 propuestas siguen
`PENDING_APPLY`, los 10 candidatos siguen `pending`, las 2 relaciones siguen `candidate`, y hay 0
filas con `applied_at`. Nada se publicó.

> **Nota de lectura.** Las §1–§7 describen la ronda 1 y siguen siendo válidas, con dos excepciones que
> la §8 corrige explícitamente: la lista de entornos de la §2/PARTE 8 (`['local','testing','staging']`)
> quedó reducida a `['staging']` más la excepción de tests, y el manifiesto ahora exige igualdad exacta
> con el conjunto pedido y un hash autorizado provisto aparte. Donde las dos secciones difieran, **vale
> la §8**.

---

## 1. El hueco semántico, aceptado sin reservas

El hallazgo del orquestador es correcto y está verificado leyendo el código:
`dryRunInputFingerprint()` hashea el grafo de conceptos (`taxonomy_canonical_concepts` +
`taxonomy_term_concepts`) y una señal de versión de `taxonomy_concept_relations`, entre otras ocho
tablas. En la cola real, #491 inserta un `taxonomy_term_concepts`, #629/#630 crean un concepto más dos
links y #631/#632 escriben `taxonomy_concept_relations`. Las tres son entradas del fingerprint.

Consecuencia exacta, no aproximada: **la primera escritura que cambia el grafo deja obsoletas a todas
las propuestas restantes del mismo conjunto**, y el camino de una sola propuesta registra esa
obsolescencia con `abort()`, que es TERMINAL. Como re-congelar está bloqueado por el índice único
parcial `WHERE status = 'PENDING_APPLY'`, un `apply()` encadenado habría **quemado decisiones humanas
una por una, sin recuperación posible**.

Esto está demostrado ejecutando, no argumentado: el test
`sequential_single_applies_stale_the_later_proposal_after_the_first_graph_change` congela dos
propuestas fixture contra el MISMO snapshot, aplica la que cambia el grafo y mide que la segunda vuelve
`ABORTED` con `STALE_TAXONOMY_STATE`, dejando su candidato sin resolver. El test gemelo
`the_same_pair_commits_with_no_abort_when_executed_as_one_batch` ejecuta el mismo escenario como lote y
las dos quedan `APPLIED`.

## 2. Lo que se implementó, requisito por requisito

### PARTE 1 — `applyBatch()`

`ReviewedProposalService::applyBatch(array $proposalIds, string $authorizationReference, ?array $manifest = null)`.

| requisito | dónde se cumple |
|---|---|
| 1. una transacción | `DB::connection('pgsql')->transaction()` envuelve validación + escrituras |
| 2. baseline computado una vez | `evaluateBatch()` llama `dryRunInputFingerprint()` una sola vez y lo propaga |
| 3. conjunto cargado y bloqueado en orden de id | `whereIn(...)->orderBy('id')->lockForUpdate()` |
| 4. toda unidad validada contra ese baseline antes de la primera escritura | el bucle de unidades corre completo antes de la fase de escritura |
| 5. ninguna escritura si algo falla | el `return` del bloqueo está antes de la fase de escritura |
| 6. no se recomputa el fingerprint entre miembros | no hay validación en la fase de escritura; la compuerta no se desactiva, simplemente ya corrió |
| 7. cero escrituras y cero ABORTs al bloquear | `applyBatch()`/`evaluateBatch()`/`previewBatch()` no contienen ninguna llamada a `abort()` ni a `abortWholeGroup()` — verificado estructuralmente por test |
| 8. excepción ⇒ rollback total | las excepciones se propagan; además un desenlace de escritor distinto de `APPLIED` se convierte en `LogicException` a propósito |
| 9. `apply()` intacto | sin cambios de semántica; lo único nuevo es el lock común de ejecución |

**No es un loop alrededor de `apply()`**: el test estructural exige que el cuerpo de `applyBatch()` no
contenga `$this->apply(` y que `evaluateBatch()` llame exactamente
`$this->evaluateApplicability($entry, $lockRows, $baseline)`.

### PARTE 2 — unidades de ejecución

12 filas → **11 unidades**. El grupo #629/#630 se deduplica por `proposal_group_id` y su entrada es el
miembro de id más bajo, así que entrar por cualquiera produce la misma ejecución. El escritor de grupo
se invoca **una vez** y marca `APPLIED` a los dos miembros en la misma transacción. Pedir medio grupo
devuelve `BATCH_GROUP_INCOMPLETE` en solo lectura, con el id del hermano ausente nombrado.

La comparación se hace contra los miembros **vivos** (`PENDING_APPLY`), no contra todos los
históricos: exigir un hermano ya `SUPERSEDED`/`ABORTED` bloquearía el lote por una fila que nadie va a
ejecutar — mismo criterio que `unconfirmedMembers()` desde TASK-0006E.

### PARTE 3 — validación compartida

Un solo parámetro nuevo en código heredado: `evaluateApplicability(..., ?string $baselineFingerprint = null)`.
Con `null` el comportamiento es idéntico al anterior, y es lo que pasan `apply()` y `preflight()`.
No existe ningún camino para inyectar un fingerprint arbitrario desde afuera del servicio: el lote lo
computa con la misma función, antes de escribir, y el manifiesto verifica además que el baseline siga
siendo el actual.

Las once comprobaciones que pide la PARTE 3 las cubre la cadena compartida, ya aprobada en TASK-0006D,
más las compuertas de forma del lote:

| comprobación | compuerta |
|---|---|
| propuesta `PENDING_APPLY` | compuerta de forma del lote (`BATCH_QUEUE_DRIFT`) + los tres estados resueltos de la cadena |
| `payload_fingerprint` válido | cadena compartida, por propuesta y **por miembro** de grupo |
| fingerprint congelado == baseline único | compuerta de obsolescencia con el baseline del lote (`BATCH_BASELINE_STALE`) |
| confirmación humana satisfecha | `unconfirmedMembers()` (`BATCH_CONFIRMATION_REQUIRED`) |
| fuente existe y es compatible | `evaluateCandidateLink()`/`evaluateConceptRelation()` |
| sin drift del snapshot de fuente | ídem (`BATCH_SOURCE_DRIFT`) |
| concepto destino de MAP_TO_EXISTING existe | `evaluateCandidateLink()` (`BATCH_ENTITY_MISSING`) |
| integridad de grupo y de payload por miembro | `evaluateBilingualGroup()` |
| validación de relación donde aplica | `validateConceptRelationProposal()` (`BATCH_RELATION_INVALID`) |
| REJECT de relación no publica | el camino REJECT retorna antes de la validación y escribe `rejected` |
| #420/#421/#422 no son entradas ejecutables | compuerta de status: `SUPERSEDED` ⇒ `BATCH_QUEUE_DRIFT` |

El resultado nombra `blocking_proposal_id` (la fila que bloquea, que en un grupo puede ser un hermano
y no la entrada), `blocking_unit_entry_proposal_id`, `blocking_unit_kind` y `proposal_blocker`.
**Ninguna propuesta se muta para reportar un bloqueo.**

### PARTE 4 — manifiesto

`app/Services/Taxonomy/ReviewedProposalBatchManifest.php`. Ata los ids, decisión,
`payload_fingerprint`, `taxonomy_state_fingerprint`, candidato/relación de origen, `proposal_group_id`,
unidades de ejecución, baseline, forma de la cola protegida y alcance; todo dentro de un
`manifest_fingerprint` sha256 determinístico. `generated_at`, el entorno y las notas quedan **fuera**
del hash: son evidencia de cuándo se generó, no parte de lo que se autoriza — si el hash cambiara al
regenerar, no se podría citar en una autorización.

`verify()` corre **dentro de la transacción del lote, con los locks ya tomados**, y no antes: «la cola
sigue siendo la que se autorizó» sólo es una afirmación útil si se hace cuando nadie más puede
ejecutar.

Límite declarado y no escondido: la comprobación «apareció una `PENDING_APPLY` fuera del manifiesto»
es una foto del instante de la verificación. Un `freeze()` que commitee un milisegundo después no se
vería, porque `freeze()` no toma el lock de ejecución y **no debe tomarlo** (una revisión humana no
puede quedar esperando una ejecución). Eso no debilita nada: una propuesta recién congelada no está en
el lote, no está bloqueada y no se escribe. La compuerta existe para detectar drift en la ventana de
GOBERNANZA entre autorizar y ejecutar, que se mide en horas.

Alcance (`FULL_PENDING_QUEUE` / `EXPLICIT_IDS`): esa comprobación sólo es un hallazgo si el manifiesto
declara cubrir la cola entera; en un manifiesto de subconjunto, que existan otras `PENDING_APPLY`
**es** la definición del subconjunto. El alcance entra en el fingerprint, así que un subconjunto no se
puede re-etiquetar, y el comando se niega a ejecutarlo salvo `--allow-subset-manifest`. El manifiesto
real de esta ronda es `FULL_PENDING_QUEUE`.

**Cero semántica de negocio en runtime:** ni una mención a petroleum/refinery/pipeline ni a los ids
reales en `app/`. El manifiesto se genera leyendo la cola; los 12 ids viven en el artefacto JSON.

### PARTE 5 — concurrencia y orden de locks

`executionAdvisoryLockKey()`: un `pg_advisory_xact_lock` único, tomado como **primera acción de la
transacción** por `applyBatch()` y por `apply()`.

```
1) lock común de ejecución C2
2) advisory locks de grupo, ordenados por clave
3) filas de propuesta, ordenadas por id
4) filas fuente, en el orden de las unidades
```

Por qué los locks de fila no alcanzaban: un `apply()` concurrente puede entrar por una propuesta que
el lote **no pidió** y mover el grafo global igual, entre la validación y la escritura del lote. El
lock común es único y global al servicio, así que es imposible tomarlo «cruzado»; tomándolo primero,
la corrección de TASK-0006B (clave común por grupo antes de los locks de fila) no se debilita: se le
agrega un lock estrictamente anterior y común, que es el caso favorable contra la inversión de orden.

No se amplió a un lock de mantenimiento: `freeze()`, `confirm()`, `preflight()`, `previewBatch()` y
`supersedeStaleProposal()` no lo toman, por los motivos dichos arriba.

**Honestidad sobre el orden de filas:** `ORDER BY id ... FOR UPDATE` expresa la intención, pero el
orden real en que Postgres toma los locks de fila depende del plan. La garantía contra deadlock **no**
depende de eso: depende del lock común tomado antes que cualquier otro. El orden de ids es defensa en
profundidad.

### PARTE 6 — preflight de lote

`previewBatch()`: el mismo camino de validación, sin fase de escritura, sin locks, y midiendo sus
propios statements. Reporta los trece elementos que pide la PARTE 6.

Diferencia real con `preflightAll()`, que ya existía y sigue existiendo: ese evalúa 12 propuestas por
separado, cada una contra el fingerprint del instante, y reporta el write-set COMPLETO del grupo en
CADA miembro — sumar las filas de ese JSON da 49 y es incorrecto. `previewBatch()` evalúa el conjunto:
baseline único, unidades deduplicadas y proyección agregada de **40 filas**, con el grupo contado una
sola vez.

### PARTE 8 — entorno

`BATCH_ALLOWED_ENVIRONMENTS = ['local', 'testing', 'staging']`. `production` **no** está, y la
compuerta vive en el SERVICIO, no sólo en el comando: `applyBatch()` devuelve
`BATCH_ENVIRONMENT_NOT_AUTHORIZED` con cero escrituras antes de abrir la transacción. El entorno se
auto-captura con `app()->environment()` y nunca lo provee quien llama.

`--expect-environment` es obligatorio para `--execute` y es lo contrario de una declaración de
confianza: obliga al operador a decir dónde cree que está, y el comando se niega si no coincide con el
entorno real. El modo seguro es el predeterminado: sin `--execute` el comando es un preflight de solo
lectura, así que una invocación por descuido no puede aplicar nada.

## 3. Evidencia de la ronda, clasificada (PARTE 10)

### A) Evidencia heredada y aprobada, no invalidada

- TASK-0001/C1, TASK-0002, TASK-0003, TASK-0004/C2, TASK-0005, TASK-0006A/B/C, TASK-0006D,
  TASK-0006E (implementación, supersesión ejecutada y cierre).
- La regresión congelada de 32 consultas de búsqueda: este diff no toca búsqueda, ranking, embeddings,
  CPV ni semántica de taxonomía publicada.
- El preflight final de TASK-0006E (`audit/task0006e_final_preflight_2026-10-05.json`), cuyo
  fingerprint `c236bc…` el preflight de lote de esta ronda vuelve a medir idéntico.
- Las 10 propuestas protegidas y las 3 supersedidas: ninguna cambió.

### B) Evidencia nueva ejecutada en esta ronda

**Código:** `applyBatch()`, `previewBatch()`, `evaluateBatch()`, el lock común de ejecución,
`ReviewedProposalBatchManifest`, dos comandos nuevos, y un parámetro opcional en
`evaluateApplicability()`. Sin migración: el lote no agrega columnas ni estados.

**Tests:** ver sección 4.

**Staging:** ver sección 5.

**Lectura real:** ver sección 6 — manifiesto y preflight de lote generados contra la cola real, los dos
de solo lectura y los dos con detector de escrituras.

### C) Compuertas heredadas invalidadas

**NINGUNA.** El cambio de mayor riesgo era tocar `evaluateApplicability()`, que es la cadena que usan
`apply()` y `preflight()`. Se cerró haciendo que el parámetro nuevo sea opcional y que `null`
reproduzca el comportamiento anterior, y re-corriendo las suites heredadas **sin editar una línea**.
El segundo cambio en código heredado es el lock común en `apply()`: agrega un lock, no quita ninguno, y
el test de orden de locks lo verifica estructuralmente en los dos caminos.

### D) Compuertas no aplicables / no autorizadas en esta ronda

- APPLY real de cualquiera de las 12 filas — **no ejecutado**.
- Cambio de status de candidatos/relaciones reales — **no ejecutado**.
- Creación del concepto real refinería/refinery, inserts reales de TERM→CONCEPT, marcar propuestas
  reales `APPLIED`/`ABORTED` — **no ejecutado**.
- PUBLISH fuera de transacciones de test — **no ejecutado**.
- Deploy a producción, merge a `main`, edición de payloads/fingerprints congelados, cualquier cambio a
  #420/#421/#422 — **no ejecutados**.
- Migración de esquema: **no aplicable**, esta tarea no la necesita.

## 4. Tests

### 4.1 Suite nueva — `tests/Unit/Taxonomy/ReviewedProposalBatchApplyTest.php`

**35/35 PASS, 397 aserciones.** Corrida dos veces: una primera pasada completa, y una **re-corrida
íntegra contra el código final** después de los últimos ajustes, porque PHPUnit autoloada la clase una
sola vez y una corrida empezada antes de un edit es una línea base, no una verificación de regresión.

Cobertura por grupo del pedido:

| grupo | tests |
|---|---|
| A — happy path | lote representativo (2× CONTEXT_REQUIRED + MAP_TO_EXISTING + grupo bilingüe CREATE_NEW + REJECT de relación) que commitea atómico con el estado final proyectado; el preflight de lote prediciendo exactamente lo que el lote escribe; y las 5 unidades validando contra el baseline único |
| B — por qué el loop no sirve | `sequential_single_applies_stale_the_later_proposal_after_the_first_graph_change` + su gemelo que ejecuta el mismo par como lote sin un solo abort |
| C — bloqueos con cero escrituras | tamper, baseline obsoleto, drift de fuente, confirmación faltante, relación inválida, queue drift y pedido vacío — cada uno midiendo cero statements SQL de escritura, conteos protegidos intactos, 0 APPLIED, 0 ABORTED y 0 filas de auditoría |
| D — grupo | el grupo ejecuta UNA vez con UN concepto y los dos miembros APPLIED; medio grupo rechazado en solo lectura; hermano manipulado bloqueando el lote completo y nombrando al hermano, no a la entrada |
| E — concurrencia e idempotencia | determinismo de la clave del lock de ejecución y no-colisión con la de grupo; exclusión mutua REAL medida con **dos conexiones a Postgres**; los dos caminos verificados en `pg_locks` teniendo el lock; prueba **estructural del ORDEN** de locks en los dos métodos; replay del lote completo devolviendo `BATCH_ALREADY_EXECUTED` con cero escrituras y sin duplicar concepto/link/auditoría; y orden de unidades independiente del orden de llegada de los ids |
| F — invariantes | ningún statement del lote menciona `taxonomy_term_cpv_relations`; una fila `SUPERSEDED` no es entrada ejecutable y queda **byte-idéntica** (comparación cruda de la fila completa); el índice único parcial sigue rechazando un segundo freeze; y `production` rechazado |
| paridad | cada constante `PREFLIGHT_*` declara su traducción de lote (recorrida **por reflexión** sobre las constantes de la clase, así que un bloqueo nuevo no puede quedar sin mapear en silencio); un bloqueo sin mapear **lanza**; y `applyBatch`/`evaluateBatch`/`previewBatch` no contienen ninguna llamada a `abort()`, `abortWholeGroup(`, `->apply(` ni `$this->apply(` |

Sobre la prueba de concurrencia: no se levantan dos procesos PHP en paralelo, porque eso exigiría
commitear fixtures reales para que las dos conexiones los vieran y esta ronda no autoriza escrituras
reales. Se usa la alternativa que el propio comentario admite («or an equivalent deterministic
fixture»): exclusión mutua medida con dos conexiones reales a Postgres, posesión del lock verificada
en `pg_locks`, y el orden de locks probado estructuralmente. La ausencia de inversión de orden se
demuestra por construcción —un lock único tomado antes que cualquier otro— más esas tres mediciones.

### 4.2 Único test heredado modificado, y por qué

`ReviewedProposalGroupLockingTest::applying_an_ungrouped_proposal_takes_no_group_lock` **falló** en la
primera corrida contra el código nuevo: contaba **todos** los advisory locks de la sesión como proxy de
«¿tomó el lock DEL GRUPO?», y desde la PARTE 5 `apply()` toma también el lock común de ejecución, así
que el conteo pasó de 0 a 1.

La invariante que el test cubre **no cambió** —una propuesta suelta sigue sin tomar ningún lock de
grupo, que es exactamente lo que dicen su nombre y su mensaje de error—; lo que cambió es que el
conteo total dejó de medirla. El test ahora mide los advisory locks que **no** son la clave de
ejecución, y afirma **aparte** que el lock de ejecución **sí** está tomado, que es el comportamiento
nuevo y deliberado. Es el único test heredado tocado en esta ronda, y se declara acá en vez de dejarlo
pasar como «ajuste menor».

Resultado tras el ajuste: **7/7 PASS**.

### 4.3 Suites heredadas, sin editar una línea

| suite | resultado |
|---|---|
| `ReviewedProposalServiceTest` | 41/41 PASS |
| `ReviewedProposalConfirmationTest` | 47/47 PASS |
| `ReviewedProposalPreflightTest` | 24/24 PASS |
| `ReviewedProposalSupersessionTest` | 20/20 PASS |
| `TaxonomyReviewedProposalResourceTest` | 12/12 PASS |
| `TaxonomyReviewedProposalConfirmationUiTest` | 9/9 PASS |
| `TaxonomyCandidateConceptLinkReviewTest` | 20/21 — ver abajo |

El único fallo local es
`viewing_a_propose_new_concept_candidate_with_duplicate_signals_does_not_500`, con
`RuntimeException: The "intl" PHP extension is required to use the [format] method`: el hueco de
`ext-intl` **preexistente** de esta máquina (la política de Application Control bloquea `php_intl.dll`),
verde en staging y ya verificado como preexistente en la ronda 2 de TASK-0006D restaurando ese archivo
a su versión de HEAD. No lo toca este diff.

### 4.4 Qué se corrió contra qué código, dicho con precisión

PHPUnit autoloada una clase **una sola vez**, así que una corrida empezada antes de un edit es una
línea base y no una verificación de regresión. Por eso las tres pasadas:

| pasada | contenido | resultado |
|---|---|---|
| 1 | 139 tests heredados (`Service` 41 + `Confirmation` 47 + `Preflight` 24 + `Supersession` 20 + `GroupLocking` 7) | 761 aserciones, **1 fallo** — el de 4.2, que se corrigió |
| 2 | 84 tests contra el código final: `GroupLocking` 7 + **`BatchApply` 35** + `ReviewedProposalResource` 12 + `ConfirmationUi` 9 + `CandidateConceptLinkReview` 21 | 692 aserciones, **1 fallo**, el preexistente de `ext-intl` |
| 3 | 132 tests heredados re-corridos **después de los últimos edits del servicio**: `Service` 41 + `Confirmation` 47 + `Preflight` 24 + `Supersession` 20, sin editar una línea | 735 aserciones, **0 fallos** |

Totales: **355 ejecuciones de test** en las tres pasadas, 2.188 aserciones, con un único fallo real
—el de 4.2, corregido— y el fallo preexistente de `ext-intl` de esta máquina.

Todos los fixtures viven dentro de `DatabaseTransactions` sobre `pgsql`: ninguna propuesta, candidato
o relación REAL participa de ningún test, y **ningún test ejecuta un APPLY real**.

## 5. Staging

- **HEAD desplegado: `7d015e20a0fabd792d30a4b595c7a617f7b600ba`.** Workflow «Deploy a Contabo» run
  **37360603242**, `completed/success` para ese sha exacto (job `deploy`, los cuatro pasos `success`,
  19:03:30 → 19:04:13 UTC).
- Smoke posterior al deploy, sin autenticación: `GET /` **200**; `GET /admin/login` **200**; las tres
  pantallas de taxonomía (`taxonomy-candidate-concept-links`, `taxonomy-reviewed-proposals`,
  `taxonomy-canonical-concepts`) **302 → login 200**. **Ningún 500/503.**
- El deploy corre `php artisan migrate --force`: en esta ronda es un **no-op**, porque TASK-0007 no
  agrega ninguna migración.
- Límite declarado, igual que en las rondas anteriores: esta sesión no tiene clave SSH al host, así que
  el HEAD desplegado se verifica por el run del workflow para ese sha exacto y por el smoke HTTP, no
  inspeccionando el contenedor. El riesgo de un 500 en las pantallas autenticadas está cubierto por las
  suites de Filament, que las abren con `assertOk()`.
- Ninguna escritura de datos acompañó al deploy: la verificación de solo lectura posterior confirma
  `max(taxonomy_audit_log.id) = 3034` —la última fila sigue siendo el freeze de #1690 por el dueño— y
  `0` filas con `applied_at` o `authorization_reference`.

## 6. Lectura real de solo lectura

### 6.1 Manifiesto — `audit/task0007_batch_manifest_2026-10-05.json`

- `manifest_version` = `taxonomy-reviewed-proposal-batch-manifest/v1`
- `scope` = `FULL_PENDING_QUEUE`
- **`manifest_fingerprint` = `eb7d14672a1051cdb4fcb98e3e8c5bd68ef01e0910193c004843f565686f3702`**
- `generated_at` = 2026-10-05 19:04:08 (evidencia, **fuera** del fingerprint)
- 12 propuestas atadas: `[491, 492, 493, 494, 495, 629, 630, 631, 632, 1688, 1689, 1690]`
- **11 unidades de ejecución** (una `BILINGUAL_GROUP` con `[629, 630]`, diez `SINGLE_PROPOSAL`)
- baseline = `c236bc5159ae4421a72dc64b1daa5b850b77a40b1c19425a3c6ac6762d305da2`
- cero statements de escritura observados durante la generación

Ese `manifest_fingerprint` es el valor que una autorización humana posterior tiene que citar. Se
puede recomputar en cualquier momento regenerando el manifiesto sobre el mismo estado: si el estado no
cambió, el hash es idéntico.

### 6.2 Preflight de lote — `audit/task0007_batch_preflight_2026-10-05.json`

- `mode` = `READ_ONLY_BATCH_PREFLIGHT`, `write_statements_observed` = **0**
- 12 propuestas aceptadas, 11 unidades, **cero bloqueos** — la clasificación exacta que la PARTE 6
  declara esperada
- verificación del manifiesto dentro del informe: `ok = true`, `scope = FULL_PENDING_QUEUE`,
  `manifest_fingerprint_recomputed_ok = true`, `baseline_matches_current = true`,
  `all_bound_proposals_pending_apply = true`, `identity_matches = true`,
  `no_unauthorised_pending_proposals = true`, `protected_shape_matches = true`
- `payload_fingerprint_valid` = true en las 12; `stale` = false en las 12;
  `source_entity_exists`/`source_state_compatible` = true en las 12
- grupo #629/#630: `member_payload_fingerprint_valid` `{629: true, 630: true}`,
  `tampered_member_ids` `[]`, `consistent` true, `reuses_concept_id` null
- #631/#632: `relation_validation` null y `source_snapshot_drift_applicable` false — contrato, no
  hueco: el camino REJECT retorna antes de `validateConceptRelationProposal()` porque rechazar no
  publica nada en el grafo. Lo que sí se verificó: payload válido y relación fuente todavía `candidate`
- write-set proyectado: **40 filas**, sin desvío respecto de la proyección aprobada

| tabla | operación | filas |
|---|---|---|
| `taxonomy_canonical_concepts` | INSERT | 1 |
| `taxonomy_term_concepts` | INSERT | 3 |
| `taxonomy_candidate_concept_links` | UPDATE | 10 |
| `taxonomy_concept_relations` | UPDATE | 2 |
| `taxonomy_reviewed_proposals` | UPDATE | 12 |
| `taxonomy_audit_log` | INSERT | 12 |
| `taxonomy_term_cpv_relations` | — | **0** |

- conteos protegidos proyectados, idénticos a la PARTE 7:

| conteo | ahora | proyectado |
|---|---|---|
| candidatos (total / pending / published / context_required) | 10 / 10 / 0 / 0 | 10 / 0 / 3 / 7 |
| relaciones (total / candidate / approved / rejected) | 2 / 2 / 0 / 0 | 2 / 0 / 0 / 2 |
| conceptos canónicos | 81 | 82 |
| TERM→CONCEPT | 142 | 145 |
| **TERM→CPV** | **9749** | **9749** |
| propuestas (total / PENDING_APPLY / APPLIED / ABORTED / SUPERSEDED) | 15 / 12 / 0 / 0 / 3 | 15 / 0 / 12 / 0 / 3 |

La proyección **no es una autorización**. Un lote sin bloqueos significa «hoy nada lo impide», nunca
«aprobado para ejecutarse».

### 6.3 Estado real al cierre de la ronda — verificación de solo lectura

Medido después del deploy y de los dos artefactos, con una lectura directa de la base compartida:

| conteo | valor | coincide con la apertura de la tarea |
|---|---|---|
| candidate links / todos `pending` | 10 / 10 | sí |
| concept relations / todas `candidate` | 2 / 2 | sí |
| TERM→CONCEPT | 142 | sí |
| conceptos canónicos | 81 | sí |
| TERM→CPV | 9749 | sí |
| reviewed proposals | 15 | sí |
| `PENDING_APPLY` / `SUPERSEDED` / `APPLIED` / `ABORTED` | 12 / 3 / 0 / 0 | sí |
| candidatos publicados / relaciones aprobadas | 0 / 0 | sí |

- ids `PENDING_APPLY` exactos: `[491, 492, 493, 494, 495, 629, 630, 631, 632, 1688, 1689, 1690]`
- ids `SUPERSEDED` exactos: `[420, 421, 422]`
- filas con `applied_at` no nulo: **0**; filas con `authorization_reference` no nulo: **0**
- `max(taxonomy_audit_log.id)` = **3034**, que sigue siendo el freeze de #1690 por el dueño: **esta
  ronda no agregó ninguna fila de auditoría de ejecución**
- fingerprint actual: `c236bc5159ae4421a72dc64b1daa5b850b77a40b1c19425a3c6ac6762d305da2`, idéntico al
  de la apertura

## 7. Condiciones de STOP

Ninguna alcanzada, y la principal se respetó literalmente: **el lote real no se ejecutó en esta
ronda**. No se publicó taxonomía, no se cambió el status de ninguna fila real, no se creó ningún
concepto real, no se editó ningún payload/fingerprint congelado, no se tocaron #420/#421/#422, no hubo
merge a `main` ni deploy a producción, y no se agregó ninguna migración.

## 8. Re-audit `6011317053` — las tres compuertas de gobernanza de ejecución

Las tres observaciones se aceptan **sin reservas**: las tres son alcanzables con el código de la ronda
1 y las tres amplían lo que el dueño autorizó. Ninguna es un desacuerdo de criterio.

### 8.1 BLOQUEO 1 — un manifiesto completo podía ejecutar sólo un subconjunto de sus ids

**El defecto, exacto.** `verify()` comprobaba que todas las propuestas que el manifiesto ATA siguieran
vivas y que la cola no tuviera ninguna de más, pero **nadie comprobaba que el conjunto a EJECUTAR
fuera ese mismo conjunto**. El CLI, además, dejaba que `--id` sobrescribiera los ids tomados del
manifiesto y le pasaba a `applyBatch()` ese conjunto más chico junto con el manifiesto COMPLETO. Por lo
tanto `--manifest=<FULL aprobado> --id=491 --execute` verificaba con éxito y ejecutaba sólo #491.

**Por qué el daño es peor que «queda una fila sin aplicar».** #491 escribe `taxonomy_term_concepts`,
así que tras esa ejecución parcial el fingerprint global cambia y las once revisiones restantes pueden
quedar obsoletas. El conjunto atómico que el dueño autorizó deja de ser recuperable **como ese
conjunto** — y re-congelar está bloqueado por el índice único parcial.

**La corrección, en dos capas:**

1. **Servicio** (la que no se puede evitar): `verify()` recibe ahora el conjunto PEDIDO como parámetro
   **obligatorio y sin valor por defecto**, y exige igualdad exacta de conjuntos normalizados contra
   los ids atados → `BATCH_MANIFEST_REQUEST_MISMATCH`, antes del baseline y antes de la liveness,
   porque no tiene sentido verificar el estado vivo de un manifiesto que no es el que se va a
   ejecutar. Que el parámetro no tenga default es deliberado: con uno, un llamador futuro podría
   omitirlo y volver a habilitar el defecto en silencio.
2. **CLI** (la opción preferida del orquestador): `--id` queda **prohibido** junto con `--execute`, y
   se rechaza el uso simultáneo en vez de intentar reconciliar los dos insumos. Si los dos pueden
   describir conjuntos distintos, alguien va a creer alguna vez que `--id` «acota» una ejecución
   autorizada; no la acota, la rompe.

Vale para los dos alcances. `--allow-subset-manifest` significa «el MANIFIESTO ata un subconjunto a
propósito», nunca «tomá un subconjunto arbitrario de un manifiesto ya atado», y así quedó escrito en
la ayuda de la opción y en el detalle del bloqueo.

El ORDEN de las compuertas también se fijó: la integridad del archivo se comprueba **antes** que la
igualdad de conjuntos, así que a un manifiesto al que le editaron la lista de propuestas se le reporta
`BATCH_TAMPER_DETECTED` —el hallazgo correcto— y no un simple desajuste de pedido, aunque las dos
cosas sean ciertas a la vez.

### 8.2 BLOQUEO 2 — datos compartidos reales se podían ejecutar desde `APP_ENV=local`

**El defecto, exacto.** `BATCH_ALLOWED_ENVIRONMENTS = ['local', 'testing', 'staging']` presentaba tres
entornos como objetivos operativos equivalentes. Y la prueba de que eso era más amplio de lo
autorizado está en los **propios artefactos de la ronda 1**: se generaron con
`generated_in_environment = local` y leyeron la cola real de 12 filas y los conteos protegidos, o sea
que el `APP_ENV=local` de esta estación **está conectado al dataset compartido REAL**. Con esa lista,
`--execute --expect-environment=local` habría podido ejecutar datos reales desde una máquina de
desarrollo.

**La corrección**, separando lo que no es lo mismo en vez de mezclarlo en una lista:

| constante | valor | qué significa |
|---|---|---|
| `BATCH_EXECUTABLE_ENVIRONMENTS` | `['staging']` | el ÚNICO entorno operativo que puede ejecutar |
| `BATCH_FIXTURE_TEST_ENVIRONMENT` | `'testing'` | excepción para tests automatizados, donde cada lote vive en una transacción que **nunca commitea** |

`environmentCanExecuteBatch()` es el único predicado, para que la regla no quede duplicada entre el
servicio y el comando con posibilidad de divergir. `production` devuelve `false` por no estar en
ninguna de las dos: la prohibición no depende de una lista negra que alguien pueda olvidar de
actualizar, sino de que sólo pase lo explícitamente permitido.

Dos matices que importan:

- **`local` conserva preview y generación de manifiestos.** Las dos son de solo lectura y es donde
  tienen sentido; dejar el preflight inutilizable no habría sido una corrección sino otro defecto. Hay
  un test que lo comprueba: en `local` el lote se bloquea con `BATCH_ENVIRONMENT_NOT_AUTHORIZED` y el
  mismo preview sigue devolviendo cero bloqueos y cero escrituras, reportando
  `environment_authorized_for_execution = false`.
- **El CLI es más estricto que el servicio, a propósito**: acepta sólo
  `BATCH_EXECUTABLE_ENVIRONMENTS`, sin la excepción de `testing`. Una persona corriendo el comando en
  `testing` no es un test de fixture.

### 8.3 BLOQUEO 3 — la autorización del dueño no estaba atada al hash del manifiesto

**El defecto, exacto.** Un manifiesto auto-hasheado prueba «este archivo no se editó sin cambiar su
hash». **No** prueba «este es el hash que el dueño autorizó». El escenario de fallo es concreto: el
dueño autoriza el hash A; después se genera un manifiesto B internamente válido; el operador corre B
citando el comentario que autorizó A; y si B coincide con el estado vivo, nada detecta que la
referencia de autorización y el manifiesto cargado describen conjuntos de ejecución distintos.

**La corrección:** un SEGUNDO insumo de confianza, independiente del archivo.
`--expect-manifest-fingerprint=<sha256>` es obligatorio con `--execute`, y `applyBatch()` lo recibe
como parámetro propio. La compuerta corre **antes de abrir la transacción** y antes de cualquier
confirmación —un hash que no es el autorizado no debe ni tomar locks— y distingue tres casos con
nombres distintos, porque piden acciones distintas:

| bloqueo | cuándo |
|---|---|
| `BATCH_AUTHORIZATION_FINGERPRINT_MISSING` | el operador no citó el hash autorizado |
| `BATCH_AUTHORIZATION_FINGERPRINT_MALFORMED` | lo citó pero no tiene forma de sha256 (64 hex) |
| `BATCH_AUTHORIZATION_FINGERPRINT_MISMATCH` | el manifiesto cargado **no es** el autorizado |

La comparación usa `hash_equals()` sobre minúsculas y recortando espacios: un copiado/pegado desde un
comentario del Issue no debería romper una ejecución legítima, y un hash no se compara con `===`
cuando existe la función que lo hace en tiempo constante. Lo que **no** se acepta es un hash distinto.

**No se deduce del archivo**, que es la parte que hace que la compuerta sirva de algo: deducirlo
colapsaría los dos insumos en uno. Hay un test **estructural** que lo fija: el cuerpo de `applyBatch()`
no puede contener `$manifest['manifest_fingerprint']` ni un fallback `$expectedManifestFingerprint ??`,
los dos insumos llegan como parámetros separados, y la compuerta se evalúa antes del `->transaction(`.

### 8.4 Tests de la ronda 2

**Corrida completa, un solo proceso, requisito 1 de la ronda 2: 189/189 PASS, 1.338 aserciones, cero
fallos.**

| suite | tests |
|---|---|
| `ReviewedProposalBatchApplyTest` | **50** (35 heredados de la ronda 1 + 15 nuevos) |
| `ReviewedProposalGroupLockingTest` | 7 |
| `ReviewedProposalServiceTest` | 41 |
| `ReviewedProposalConfirmationTest` | 47 |
| `ReviewedProposalPreflightTest` | 24 |
| `ReviewedProposalSupersessionTest` | 20 |

Las cinco suites heredadas se corrieron **sin editar una línea**. Nota honesta sobre la trazabilidad de
las corridas: una pasada anterior de la suite del lote tuvo **9 errores consecutivos** por una caída
transitoria de DNS de esta máquina (`could not translate host name
"aws-0-us-west-2.pooler.supabase.com"`), no por el código — los tests anteriores y posteriores a ese
bloque pasaron. Esos 9 se re-corrieron limpios y después la corrida completa de 189 pasó entera en un
solo proceso, que es la que se reporta arriba.

La suite del lote pasó de **35 a 50 tests**. Los 15 nuevos cubren exactamente lo que el re-audit pidió:

| requisito del re-audit | test |
|---|---|
| manifiesto FULL + subconjunto estricto → bloqueado, cero escrituras, cero ABORTs | `a_strict_subset_of_a_full_manifest_is_refused_with_zero_writes` |
| manifiesto de subconjunto + subconjunto adicional → bloqueado | `a_further_subset_of_a_subset_manifest_is_refused_too` |
| (añadido) pedir MÁS de lo atado → bloqueado | `extra_ids_beyond_the_manifest_are_refused_as_well` |
| mismos ids en otro orden → aceptado; duplicados normalizados → aceptado | `the_same_set_in_a_different_order_or_with_duplicates_is_accepted` |
| `--id` no puede debilitar un manifiesto (nivel comando) | `the_command_refuses_id_together_with_execute` |
| (añadido) precedencia tamper > mismatch | `an_edited_manifest_is_reported_as_tamper_and_not_as_a_request_mismatch` |
| `local` → `BATCH_ENVIRONMENT_NOT_AUTHORIZED`, cero escrituras | `local_can_preview_but_cannot_execute_a_batch` |
| `production` → bloqueado, cero escrituras | `the_batch_refuses_an_environment_that_is_not_authorised_to_execute` |
| `testing` permitido para fixtures; `staging` pasa la compuerta | `staging_passes_the_environment_gate_and_testing_is_only_the_fixture_exception` |
| hash autorizado == hash cargado → pasa | `the_batch_executes_when_the_authorised_fingerprint_matches_the_loaded_manifest` |
| mismatch → rechazado antes de la transacción, cero escrituras | `the_batch_refuses_a_valid_manifest_that_is_not_the_authorised_one` |
| falta el hash con `--execute` → rechazado | `the_batch_refuses_a_manifest_without_the_authorised_fingerprint` + `the_command_refuses_execute_without_the_authorised_fingerprint` |
| hash malformado → rechazado | `the_batch_refuses_a_malformed_authorised_fingerprint` |
| (añadido) el hash no se deduce del archivo | `the_authorisation_fingerprint_is_never_inferred_from_the_manifest_file` |
| (añadido) el CLI rechaza ejecutar fuera de staging | `the_command_refuses_execute_outside_staging` |

Los tests de nivel comando usan un manifiesto de **fixture** escrito a un archivo temporal, no el
artefacto real de `audit/`: ese ata las 12 propuestas reales, y si alguna compuerta del comando se
debilitara, un test podría llegar a nombrarlas. Con un manifiesto de fixture ningún camino de esos
tests puede siquiera mencionar una propuesta real.

### 8.5 Manifiesto y preflight nuevos (requisitos 3–6 de la ronda 2)

El orquestador advirtió explícitamente que **no se asuma** que el fingerprint anterior sigue siendo
autoritativo. Se regeneró, y el valor medido es:

**`manifest_fingerprint` = `eb7d14672a1051cdb4fcb98e3e8c5bd68ef01e0910193c004843f565686f3702`**

Es el **mismo** que el de la ronda 1, y la razón es verificable: esta ronda no tocó `generate()` ni
`fingerprintFor()` —lo que el manifiesto ATA y cómo se hashea no cambió— sino `verify()`, que es la
comprobación contra el estado vivo y no forma parte del contenido atado. Además la cola real no se
movió. Se reporta como **medición**, no como suposición.

Artefactos nuevos (los de la ronda 1 se conservan como registro histórico):

- `audit/task0007_batch_manifest_2026-10-06.json` — `scope = FULL_PENDING_QUEUE`, 12 propuestas, 11
  unidades, baseline `c236bc…`, cero statements de escritura durante la generación.
- `audit/task0007_batch_preflight_2026-10-06.json` — `generated_at` 2026-10-06 08:53:54.

Verificación del preflight nuevo, punto por punto contra el requisito 5:

| requisito | medido |
|---|---|
| 12 filas / 11 unidades / cero bloqueos | 12 aceptadas, 11 unidades, `blocker = null` |
| `write_statements_observed = 0` | **0** |
| conteos vivos 10 / 2 / 142 / 81 / 9749 / 15 / 12 pending / 3 superseded / 0 applied / 0 aborted | idénticos |
| proyección de escrituras = 40 | **40**, sin desvío que explicar |
| sin escrituras de auditoría posteriores | `max(taxonomy_audit_log.id)` = **3034** |

Y dos campos del informe que **sólo** existen por las correcciones de esta ronda, así que el artefacto
es evidencia de que las compuertas están activas y no sólo declaradas:

- `environment_authorized_for_execution: false` con `target_environment: local` — BLOQUEO 2 visible en
  el artefacto: la máquina que generó el informe puede leer pero **no** puede ejecutar.
- `manifest_verification.findings.requested_set_equals_bound_set: true` — BLOQUEO 1: el conjunto
  pedido es exactamente el atado.

### 8.6 Staging de la ronda 2

- **HEAD desplegado: `c1d174eb6c1ac886f9c76f0965751e91538365c4`.** Workflow «Deploy a Contabo» run
  **37439406937**, `completed/success` para ese sha exacto (job `deploy`, los cuatro pasos `success`,
  08:55:38 → 08:57:55 UTC).
- Smoke posterior: `GET /` **200**, `GET /admin/login` **200**, las tres pantallas de taxonomía
  **302 → login 200**. **Ningún 500/503.**
- Sigue sin haber migración, así que el `migrate --force` del deploy es un no-op.

### 8.7 Clasificación de la ronda 2 (PARTE 10)

**A) Evidencia heredada y aprobada, no invalidada.** TASK-0001/C1 a TASK-0006E; el diseño atómico de
baseline único de TASK-0007; la validación de aplicabilidad compartida; el diseño de unidades de
ejecución agrupadas; el diseño del advisory lock de ejecución; y la regresión congelada de búsqueda
—esta ronda no toca búsqueda, ranking, embeddings, CPV ni semántica publicada.

**B) Evidencia nueva de esta ronda.** Las tres compuertas corregidas con sus 15 tests nuevos; el deploy
de `c1d174e` a staging con smoke limpio; el manifiesto y el preflight regenerados contra la cola real,
los dos de solo lectura; y la re-verificación directa del estado vivo.

**C) Compuertas invalidadas.** Ninguna de las compuertas técnicas heredadas. Lo que el re-audit
invalidó —y con razón— fue la *disposición de la ronda 1 a pedir la autorización de ejecución real*, y
eso es justamente lo que esta ronda repara. El cambio de mayor riesgo fue volver obligatorio el tercer
parámetro de `verify()`: se hizo deliberadamente sin valor por defecto, y los llamadores existentes se
actualizaron uno por uno.

**D) No autorizado y no ejecutado.** APPLY/PUBLICACIÓN real, cambios de status de candidatos o
relaciones, creación real de conceptos/links, deploy a producción, merge a `main`, y cualquier cambio a
#420/#421/#422.

## 9. Qué falta para la ejecución real

Una autorización humana nueva y explícita en Issue #2 que cite el conjunto exacto de ids y el
`manifest_fingerprint` del artefacto de esta ronda, más el entorno autorizado (compartido/staging;
`production` sigue prohibido por el servicio). Esa autorización es un acto separado por diseño desde
TASK-0004, y nada de esta ronda la otorga ni la anticipa.
