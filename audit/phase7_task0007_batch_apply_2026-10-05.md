# TASK-0007 ronda 1 — APPLY ATÓMICO POR LOTE: implementación, manifiesto y preflight de solo lectura

**Tarea:** Issue #2 comentario `5997693379` — *ORCHESTRATOR — OPEN TASK-0007 / ATOMIC BATCH APPLY
PREPARATION — REAL APPLY NOT YET AUTHORIZED*
**Checkpoint base:** `4e671fa7b1b0a3635157e416be9ddbf0e9e69de7` (TASK-0006E PASS/CLOSED en `5994449681`)
**Rama:** `feature/upgrade-filament-v3`
**Fecha:** 2026-10-05
**Definición de la tarea (verbatim):** `docs/orquestador/tasks/0007-atomic-batch-apply.md`

**APPLY REAL = NO AUTORIZADO Y NO EJECUTADO.** Al cierre de esta ronda las 12 propuestas siguen
`PENDING_APPLY`, los 10 candidatos siguen `pending`, las 2 relaciones siguen `candidate`, y hay 0
filas con `applied_at`. Nada se publicó.

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

## 8. Qué falta para la ejecución real

Una autorización humana nueva y explícita en Issue #2 que cite el conjunto exacto de ids y el
`manifest_fingerprint` del artefacto de esta ronda, más el entorno autorizado (compartido/staging;
`production` sigue prohibido por el servicio). Esa autorización es un acto separado por diseño desde
TASK-0004, y nada de esta ronda la otorga ni la anticipa.
