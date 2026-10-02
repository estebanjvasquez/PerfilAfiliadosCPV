# TASK-0006D — Preflight de aplicabilidad (solo lectura), corrección de UX de candidatos y diseño de propuestas obsoletas

**Referencia de gobernanza:** Issue #2 comentario `5949253156`
**Checkpoint de entrada:** `510400a2faac75c8223e5570e7581d7a3a96f416`
**Fecha:** 2026-10-02
**APPLY / PUBLISH:** NO EJECUTADO, NO AUTORIZADO. TASK-0007 sigue sin abrir.

---

## 1. Qué se entregó, y qué deliberadamente no

| Parte | Pedido | Estado |
|---|---|---|
| 1 | Preflight de solo lectura reutilizando las primitivas de `apply()` | **IMPLEMENTADO** + comando de artisan |
| 2 | Corrección de UX del candidato (defecto `5947407519`) | **IMPLEMENTADO** + 6 tests de regresión |
| 3 | Diseño del tratamiento de propuestas obsoletas | **DISEÑADO**, no implementado, no ejecutado |
| 4 | Informe pre-APPLY de las 12 propuestas | **EJECUTADO de solo lectura** (§4) |
| 5 | Nota de riesgo sobre edición de relaciones aprobadas | **VERIFICADO** - no bloquea TASK-0007 (§6) |
| 6 | Tests / staging / invariantes | **EJECUTADO** (§7, §8) |

Cero mutaciones de datos reales: ninguna propuesta, candidato, relación, concepto o mapeo cambió. Las
únicas escrituras de esta ronda son archivos de código, tests y documentación.

---

## 2. PARTE 1 — El preflight: por qué `apply()` no servía y cómo se evitó duplicar la validación

### 2.1 El problema real

Hasta esta tarea, la única forma de saber si una propuesta congelada seguía siendo aplicable era
llamar a `apply()`. Y `apply()` es **destructivo cuando falla**: registra obsolescencia, tamper, drift
y relación inválida con `abort()`, que pasa la propuesta a `ABORTED` de forma **terminal**. Como
re-congelar está bloqueado por el índice único parcial
`taxonomy_reviewed_proposals_one_pending_per_candidate` (`WHERE status = 'PENDING_APPLY'`), usar
`apply()` como sonda **quema una decisión humana sin recuperación posible**. El orquestador lo dijo
exactamente así: «apply() is NOT a safe preflight API».

### 2.2 Reutilización, no reimplementación

El requisito explícito era reutilizar las primitivas de validación «rather than copy/reimplement
divergent logic». Lo que se hizo no fue escribir un segundo validador sino **extraer el único que
hay**:

- toda la cadena de validación de `apply()` se movió a `evaluateApplicability(proposal, lockRows)`;
- `apply()` la llama con `lockRows: true` y, si devuelve un bloqueo, lo traduce con
  `applyOutcomeForBlocker()`; si no, pasa a los métodos que SOLO escriben
  (`writeCandidateLinkDecision` / `writeBilingualGroupCreateNew` / `writeConceptRelationDecision`);
- `preflight()` la llama con `lockRows: false` y describe el resultado.

**La única diferencia entre los dos llamadores es cómo se LEEN las filas fuente** (con o sin
`lockForUpdate()`). Qué se valida, en qué orden y con qué desenlace es un solo cuerpo de código.

El orden de las compuertas se preservó byte por byte, incluidas sus dos excepciones deliberadas, que
son fáciles de romper en una extracción:

1. `REJECT` no exige el snapshot de `term_id` (no determina ningún destino de escritura);
2. el chequeo de `term_id` corre **antes** de la rama `CONTEXT_REQUIRED` (ronda 4 de TASK-0004,
   defecto 2).

### 2.3 Cómo se garantiza que no puedan divergir

Tres mecanismos, no una promesa en un comentario:

1. **`applyAbortReasonForBlocker()`** es el único lugar donde se decide qué `ABORT_*` escribe
   `apply()` para cada bloqueo. Un bloqueo nuevo sin mapear **lanza `LogicException`** en vez de
   abortar con un motivo inventado.
2. **Test estructural**: los tres métodos de escritura de `apply()` no contienen ni una llamada a
   `$this->abort(`. Si no pueden abortar, entonces **todo** aborto de `apply()` sale de la cadena
   compartida. Una validación reintroducida dentro de una rama de escritura rompe el test.
3. **Test de paridad por vocabulario**: cada bloqueo del preflight declara el `ABORT_*` que predice,
   para los dos tipos de origen, incluidos los que **no** abortan (`null` también es paridad: un
   `apply()` prematuro sobre algo sin confirmar no quema nada).

Ninguno de los tres llama a `apply()` — requisito explícito del orquestador («WITHOUT calling real
apply»). La semántica de `apply()` en sí ya está cubierta por las suites existentes, que siguen verdes
(§7).

### 2.4 Ausencia de efectos: medida, no declarada

`preflight()` no abre transacción de escritura, no toca `status`/`application_result`/
`authorization_reference`/`target_environment`/`applied_at`, no escribe la fila fuente, no inserta
auditoría, no crea conceptos/mapeos/relaciones y **no llama a `apply()` por ningún camino**.

Cómo se verifica:

- cada test de bloqueo instala `DB::listen()` y exige **cero** statements que empiecen con
  `insert|update|delete|truncate|alter|create|drop`;
- la fila de la propuesta se compara **columna por columna** (lectura cruda, sin casts) antes y
  después;
- se comparan los conteos de las 6 tablas relevantes, incluida `taxonomy_audit_log`;
- el **comando de artisan lleva la misma comprobación en producción**: si detecta un solo statement
  de escritura, aborta con código de error en vez de reportar "solo lectura" sobre una corrida que no
  lo fue. En la corrida real contra las 12 propuestas reportó `write_statements_observed: 0`.

Un detalle que también es ausencia de efectos y es fácil pasar por alto: **el preflight no toma el
advisory lock del grupo** (`lockRows: false` lo saltea, y hay un test que lo comprueba con
`holdsGroupAdvisoryLock()`). Tomarlo haría que una lectura de diagnóstico pudiera **demorar** un
`apply()` real — un efecto observable, aunque no escribiera nada.

### 2.5 Lo que el preflight NO es

Dicho explícitamente para que no se sobre-interprete: es una **foto sin lock**, válida en el instante
en que se tomó. No reserva nada y no autoriza nada. Entre el preflight y un `apply()` posterior el
estado puede cambiar, y por eso `apply()` revalida todo otra vez con locks. Un `READY_TO_APPLY`
significa «hoy no hay nada que lo impida», **nunca** «ya está aprobado para ejecutarse».

### 2.6 Vocabulario de bloqueo y su correspondencia con `apply()`

| Bloqueo del preflight | `apply()` escribiría | ¿Terminal? | Categoría de gobernanza |
|---|---|---|---|
| `READY_TO_APPLY` | — | no | READY_TO_APPLY |
| `TAMPER_DETECTED` | `TAMPER_DETECTED` | **sí** | BLOCKED_FOR_OTHER_REASON |
| `STALE_TAXONOMY_STATE` | `STALE_TAXONOMY_STATE` | **sí** | NEEDS_REVALIDATION |
| `ENTITY_MISSING` | `ENTITY_MISSING` | **sí** | BLOCKED_FOR_OTHER_REASON |
| `SOURCE_DRIFT` | `SOURCE_FIELD_DRIFTED` | **sí** | NEEDS_REVALIDATION |
| `SOURCE_ALREADY_RESOLVED` | `CANDIDATE_ALREADY_RESOLVED` / `RELATION_ALREADY_RESOLVED` (por tipo) | **sí** | BLOCKED_FOR_OTHER_REASON |
| `GROUP_INCOMPLETE_OR_INCONSISTENT` | `BILINGUAL_GROUP_NOT_APPLICABLE` | **sí** | BLOCKED_FOR_OTHER_REASON |
| `RELATION_VALIDATION_FAILED` | `RELATION_INVALID_AT_APPLY_TIME` | **sí** | NEEDS_REVALIDATION |
| `HUMAN_CONFIRMATION_REQUIRED` | — (no es abort) | no | BLOCKED_FOR_OTHER_REASON |
| `ALREADY_APPLIED` / `ALREADY_ABORTED` | — (replay idempotente) | no | BLOCKED_FOR_OTHER_REASON |

Dos decisiones de clasificación que conviene justificar en vez de dejarlas implícitas:

- **`TAMPER_DETECTED` no es `NEEDS_REVALIDATION`.** No es un problema de frescura sino un hallazgo de
  seguridad: alguien modificó campos de decisión después de congelarlos. Clasificarlo como «hay que
  revalidar» invitaría a resolverlo re-congelando en vez de investigando.
- **`HUMAN_CONFIRMATION_REQUIRED` tampoco.** No falta revalidar nada; falta que una persona confirme
  por la UI autenticada.

### 2.7 El write-set: la parte que protege contra usar `apply()` como prueba

El informe describe lo que `apply()` escribiría **también cuando está bloqueado**, y eso es lo más
útil del método. Para un bloqueo terminal el write-set **no está vacío**: son dos escrituras que
QUEMAN la propuesta (`status -> ABORTED` + la fila de auditoría del intento), con una nota explícita.
Así la advertencia del orquestador queda dicha fila por fila, en vez de depender de que alguien se
acuerde.

Para `HUMAN_CONFIRMATION_REQUIRED` y los estados ya resueltos el write-set es **cero**: esos no
queman nada, y el informe lo distingue.

### 2.8 Honestidad de las compuertas no evaluadas

La cadena corta en el **primer** impedimento, y debe hacerlo: seguir validando sobre una premisa ya
falsa daría respuestas inventadas (no se puede buscar drift en una fila fuente que no existe). El
informe **no simula** las compuertas que no corrieron: las deja en `null`, y el propio JSON lleva el
campo `checks_null_means` explicando que `null` significa «no se evaluó», **nunca** «pasó». Para
`REJECT`, el drift se reporta como «no aplica» (`source_snapshot_drift_applicable: false`) en vez de
«ok», porque esa decisión deliberadamente no depende de ningún campo fuente congelado.

Esto se ve en la práctica en #420–#422 (§4): su `source_entity_exists` es `null`, no `true` — la
obsolescencia bloqueó antes. Que sus candidatos #263–#265 siguen `pending` es un dato de la foto de
estado (§3), no un veredicto de compuerta.

### 2.9 Superficie entregada

- `ReviewedProposalService::preflight(int $proposalId)` y `preflightAll(array $onlyIds = [])`
- `php artisan taxonomy:reviewed-proposals-preflight [--id=*] [--json=ruta] [--full]`
  — sin `--authorized-by` y sin aceptarlo: no hay nada que autorizar porque no ejecuta nada.
- `ReviewedProposalService::payloadFingerprintIsValid()`,
  `applyAbortReasonForPreflightBlocker()`, `governanceCategoryForBlocker()` (públicas, de
  diagnóstico, sin efectos).

---

## 3. Foto de estado de solo lectura (antes y después de esta ronda, idéntica)

| Tabla / medida | Valor | Esperado por el orquestador |
|---|---|---|
| `taxonomy_candidate_concept_links` | 10 | 10 ✓ |
| `taxonomy_concept_relations` | 2 | 2 ✓ |
| `taxonomy_term_concepts` | 142 | 142 ✓ |
| `taxonomy_canonical_concepts` | 81 | 81 ✓ |
| `taxonomy_term_cpv_relations` | 9749 | 9749 ✓ |
| `taxonomy_reviewed_proposals` | 12 | 12 ✓ |
| propuestas `APPLIED` | 0 | 0 ✓ |
| propuestas `ABORTED` | 0 | — |
| propuestas `PENDING_APPLY` | 12 | — |
| candidatos `pending` | 10 (todos) | — |
| relaciones `approved` | **0** | — |
| relaciones `candidate` | 2 (#61, #62) | — |

Fingerprint actual de `dryRunInputFingerprint()`:
`c236bc5159ae4421a72dc64b1daa5b850b77a40b1c19425a3c6ac6762d305da2`

Fingerprints congelados presentes en la tabla: exactamente dos —
`1d0eb041f64286967c7178d9bde13b8e8f88fee82fd68bc5cc2255065c46f4f6` (3 propuestas, **obsoleto**) y
`c236bc51…` (9 propuestas, **igual al actual**).

---

## 4. PARTE 4 — Informe pre-APPLY de las 12 propuestas

Generado con `php artisan taxonomy:reviewed-proposals-preflight --json=... --full`.
Artefacto completo: `audit/task0006d_preflight_12_proposals_2026-10-02.json` (856 líneas).
`write_statements_observed: 0`.

| # | Origen | Decisión | Procedencia | Confirmación | Payload | Obsoleta | Drift | Grupo/Relación | Resultado | Categoría |
|---|---|---|---|---|---|---|---|---|---|---|
| 420 | cand #263 / `petroleum` (en) | CONTEXT_REQUIRED | humano (pre-0006B) | no exige | válido | **SÍ** | n/e | — | `STALE_TAXONOMY_STATE` | **NEEDS_REVALIDATION** |
| 421 | cand #264 / `crude oil` (en) | CONTEXT_REQUIRED | humano (pre-0006B) | no exige | válido | **SÍ** | n/e | — | `STALE_TAXONOMY_STATE` | **NEEDS_REVALIDATION** |
| 422 | cand #265 / `oil and gas` (en) | CONTEXT_REQUIRED | humano (pre-0006B) | no exige | válido | **SÍ** | n/e | — | `STALE_TAXONOMY_STATE` | **NEEDS_REVALIDATION** |
| 491 | cand #272 / `pipeline` (en) | MAP_TO_EXISTING → #2890 | humano | no exige | válido | no | no | — | `READY_TO_APPLY` | READY_TO_APPLY |
| 492 | cand #266 / `exploration` (en) | CONTEXT_REQUIRED | agente | confirmada `http` | válido | no | no | — | `READY_TO_APPLY` | READY_TO_APPLY |
| 493 | cand #267 / `upstream` (en) | CONTEXT_REQUIRED | agente | confirmada `http` | válido | no | no | — | `READY_TO_APPLY` | READY_TO_APPLY |
| 494 | cand #268 / `midstream` (en) | CONTEXT_REQUIRED | agente | confirmada `http` | válido | no | no | — | `READY_TO_APPLY` | READY_TO_APPLY |
| 495 | cand #269 / `downstream` (en) | CONTEXT_REQUIRED | agente | confirmada `http` | válido | no | no | — | `READY_TO_APPLY` | READY_TO_APPLY |
| 629 | cand #270 / `refinery` (en) | CREATE_NEW bilingüe | agente | confirmada `http` | válido | no | no | grupo `043fce22…`, 2/2 coherente | `READY_TO_APPLY` | READY_TO_APPLY |
| 630 | cand #271 / `refinería` (es) | CREATE_NEW bilingüe | agente | confirmada `http` | válido | no | no | grupo `043fce22…`, 2/2 coherente | `READY_TO_APPLY` | READY_TO_APPLY |
| 631 | rel #61 / 4→25 `RELATED_TO` | REJECT | agente | confirmada `http` | válido | no | n/a | validación no corre (REJECT) | `READY_TO_APPLY` | READY_TO_APPLY |
| 632 | rel #62 / 16→61 `RELATED_TO` | REJECT | agente | confirmada `http` | válido | no | n/a | validación no corre (REJECT) | `READY_TO_APPLY` | READY_TO_APPLY |

`n/e` = no evaluado (una compuerta anterior bloqueó primero). `n/a` = no aplica a esa decisión.

**Resumen: 9 `READY_TO_APPLY`, 3 `NEEDS_REVALIDATION`, 0 `BLOCKED_FOR_OTHER_REASON`.**

### 4.1 Lo que el preflight comprobó y no se dio por supuesto

El orquestador pidió «do not infer that only #420–#422 are stale; report the actual result for every
proposal». Resultados que **no** se podían deducir de la tabla de fingerprints:

- **`payload_fingerprint` válido en las 12.** Cero tamper, incluidas las cuatro que pasaron por la
  invalidación y re-confirmación de TASK-0006C — lo que confirma empíricamente que los 9 campos del
  fingerprint no incluyen ninguna columna de confirmación.
- **Las 8 confirmaciones humanas están presentes y son `http`.** Ninguna propuesta quedó en
  `HUMAN_CONFIRMATION_REQUIRED`.
- **#491 es aplicable de verdad**, no sólo "no obsoleta": su concepto destino **#2890 existe** (la
  compuerta de existencia corrió y pasó).
- **El grupo bilingüe #629/#630 es coherente**: 2 miembros, los dos `PENDING_APPLY`, mismo
  fingerprint, misma identidad ES/EN congelada, los dos candidatos `pending` y sin drift. Si
  cualquiera de esas cosas fallara, el resultado sería `GROUP_INCOMPLETE_OR_INCONSISTENT`.
- **Las relaciones #61 y #62 siguen `candidate`** y sus propuestas son `REJECT`, así que
  `validateConceptRelationProposal()` **no se invoca** para ellas (el camino de REJECT corta antes).
  Relevante para la PARTE 5.

### 4.2 Un hallazgo que el preflight hizo visible

**#491 mapea el término `pipeline` (en) al concepto #2890 — que es uno de los dos conceptos cuya
creación dejó obsoletas a #420–#422.**

Verificado: #2890 = `oleoducto` / `oil pipeline`, creado 2026-10-01 13:07:11; #2891 = `gasoducto` /
`gas pipeline`, 13:07:30. #420–#422 se revisaron ~09:48–09:50 y #491 a las 13:08 — un minuto después
de que existieran los conceptos nuevos.

Por qué importa: corrobora con datos, no con suposiciones, que el cambio de estado que invalidó
#420–#422 **es sustantivo para esos mismos términos**. `petroleum`, `crude oil` y `oil and gas` fueron
marcados «demasiado genéricos para un mapeo directo» cuando `oleoducto`/`gasoducto` no existían. El
gate de obsolescencia no está siendo pedante: señala un cambio que un humano debería mirar. Esto es la
base de la recomendación de la PARTE 3 (§5).

### 4.3 Proyección del write-set (no es una autorización)

Si las 9 `READY_TO_APPLY` se aplicaran — lo que **requiere** la autorización de TASK-0007, inexistente
hoy:

| Tabla | Efecto | Antes → Después |
|---|---|---|
| `taxonomy_canonical_concepts` | +1 (el concepto bilingüe del grupo) | 81 → 82 |
| `taxonomy_term_concepts` | +3 (#24→#2890, #22 y #23→concepto nuevo) | 142 → 145 |
| `taxonomy_candidate_concept_links` | 3 → `published`, 4 → `context_required`, 3 quedan `pending` | 10 → 10 |
| `taxonomy_concept_relations` | 2 → `rejected` (ninguna aprobada) | 2 → 2 |
| `taxonomy_term_cpv_relations` | **sin cambios** | 9749 → 9749 |
| `taxonomy_reviewed_proposals` | 9 → `APPLIED`, 3 siguen `PENDING_APPLY` | 12 → 12 |
| `taxonomy_audit_log` | +9 filas de auditoría de ejecución | — |

**Total: 31 filas escritas.**

Aviso de lectura del artefacto: #629 y #630 reportan el **mismo** write-set de 9 filas porque son
hermanos del mismo grupo — entrar por cualquiera de los dos aplica el grupo completo. **Sumar las dos
filas del JSON da 18 y sería incorrecto**; el grupo se cuenta una sola vez. Y la convergencia
bilingüe no escribe ninguna relación TÉRMINO→CPV: prohibición explícita de la sección D de
TASK-0006B, visible en el write-set.

---

## 5. PARTE 3 — Diseño del tratamiento de propuestas obsoletas

Documento completo: `docs/orquestador/designs/0006d-stale-proposal-supersession.md`.

**Recomendación: OPCIÓN A (supersesión no destructiva + sucesor), con el sucesor OPCIONAL y con el
diff de estado como requisito de entrega, no como mejora.**

Resumen del razonamiento:

- **No toca `apply()`.** Un sucesor con `prepared_by_actor_type='agent'` +
  `requires_human_confirmation=TRUE` ya es rechazado por el gate de confirmación **existente**, sin
  una línea de código nueva en el camino de ejecución. La OPCIÓN B exige modificar el gate de
  obsolescencia — el más crítico del sistema — para resolver un problema de tres filas, y convierte
  «¿el estado es idéntico al revisado?» en dos preguntas con dos fuentes de verdad.
- **Compatible con el índice único parcial por construcción**: el predecesor sale de `PENDING_APPLY`
  antes de que el sucesor entre, en una sola transacción con `lockForUpdate()` + re-chequeo — el
  patrón que este servicio ya usa tres veces. El índice no se debilita ni se toca.
- **La OPCIÓN C es un subconjunto de la A**, no una alternativa: supersedir *sin* sucesor devuelve el
  candidato a la UI de revisión normal, y la PARTE 2 de esta misma tarea ya hace que esa UI reaparezca
  sola. Implementar A entrega C gratis.
- **Condición explícita**: sin el diff de estado, A es **peor** que C, porque presentaría al humano
  una decisión ya redactada pidiéndole que la confirme sin mostrarle qué cambió — el consentimiento
  ceremonial que el orquestador advirtió. Si el diff no se implementa, hay que entregar C.

**Para los tres casos reales la recomendación es supersesión SIN sucesor**, por §4.2: la pregunta que
corresponde no es «¿confirmás la decisión vieja?» sino «¿`petroleum` sigue siendo demasiado genérico
ahora que existen `oleoducto` y `gasoducto`?». Eso es una revisión nueva, no una confirmación.

La OPCIÓN D queda registrada como **NO ACEPTABLE** (no sobrescribir fingerprints, no recalcular el
payload para que pase, no agregar un flag de apply forzado, no desactivar `STALE_TAXONOMY_STATE`, no
borrar y recrear historia).

**Nada de este diseño se implementó ni se ejecutó.** La transición de datos sobre #420/#421/#422
requiere autorización explícita y separada en un comentario posterior del Issue #2.

---

## 6. PARTE 5 — Riesgo de edición de relaciones ya aprobadas

**Veredicto: NO puede afectar a las 12 propuestas pendientes ni a la ejecución de TASK-0007. No
bloquea. Queda como gate separado de endurecimiento administrativo.**

### 6.1 El riesgo existe y está confirmado en el código

`TaxonomyConceptRelation::booted()` sólo revalida cuando `isDirty('status')` **y** el status nuevo es
`approved`. Editar los extremos o el tipo de una relación **ya aprobada** no ensucia `status`, así que
el guard no corre y no hay revalidación. `TaxonomyConceptRelationResource::canEdit()` devuelve `false`
para toda fila con `isUnderC2Review()` (status `candidate`, o con una propuesta `PENDING_APPLY`), pero
`true` para una relación `approved`/`rejected` sin propuesta pendiente. El riesgo reportado es real.

### 6.2 Por qué no alcanza a esta cola ni a TASK-0007

Cuatro razones, cada una verificada:

1. **Hoy no hay nada en estado editable.** Las dos relaciones (#61, #62) son `candidate` **y** tienen
   propuestas `PENDING_APPLY` (#631, #632) → `isUnderC2Review()` verdadero → `canEdit()`/`canDelete()`
   falsos, lo que cierra a la vez la acción de tabla y la ruta `/{record}/edit`. Y hay **cero**
   relaciones aprobadas (§3).
2. **Las dos propuestas de relación son `REJECT`, no `PUBLISH_RELATION`.** El camino de REJECT en
   `apply()` retorna antes de llamar a `validateConceptRelationProposal()`, así que su ejecución **no
   lee el grafo de relaciones en absoluto**.
3. **Las 10 propuestas de candidato no tocan relaciones.** `taxonomy_concept_relations` sólo se lee
   desde `validateConceptRelationProposal()`, alcanzable únicamente por el camino PUBLISH_RELATION.
4. **Aun hipotéticamente, el gate de obsolescencia contiene la ventana.**
   `taxonomy_concept_relations` es una de las 8 tablas dentro de `dryRunInputFingerprint()`, vía
   `tableVersionSignal()` (COUNT + MAX(`updated_at`)). Cualquier edición por el CRUD genérico pasa por
   Eloquent, que toca `updated_at` → el fingerprint cambia → **todas** las propuestas pendientes
   quedan obsoletas y `apply()` aborta en vez de publicar contra un grafo que nunca se revalidó.

   Límite honesto, ya documentado en el docblock de `dryRunInputFingerprint()`: un UPDATE por SQL
   crudo que deliberadamente no toque `updated_at` no se detectaría. Eso no es alcanzable desde la UI.

### 6.3 Nota de preparación mirando hacia adelante

Consecuencia concreta de TASK-0007 que conviene registrar ahora, aunque no bloquee: aplicar #631/#632
deja las relaciones #61/#62 en `rejected` **sin propuesta pendiente**, y entonces `canEdit()` pasa a
devolver **`true`** para ellas. Y como `validateConceptRelationProposal()` **no filtra por status**
(una fila `rejected` sigue contando como duplicado exacto), editar esas dos filas después de TASK-0007
podría cambiar el resultado de una propuesta de relación **futura** sin ninguna revalidación.

Es exactamente el riesgo ya registrado, ahora con una fecha concreta a partir de la cual se vuelve
alcanzable. No bloquea TASK-0007 (ninguna propuesta futura existe todavía, y el gate de obsolescencia
seguiría cubriendo a las que existan). Recomendación para el gate separado: extender el guard del
modelo para revalidar cuando cambian `source_concept_id`/`target_concept_id`/`relation_type` de una
fila que ya está `approved`, no sólo cuando cambia `status`.

No se implementó ningún rediseño administrativo general en esta tarea: no es demostrablemente
necesario para un APPLY seguro, y hacerlo ampliaría el alcance e invalidaría regresiones heredadas.

---

## 7. PARTE 6 — Tests y staging

### 7.1 Resultados

Todo con fixtures desechables dentro de `DatabaseTransactions` sobre `pgsql`, en entorno local
seguro. **No se reusó** el procedimiento de contenedor efímero sobre los bind mounts de staging en
vivo (requisito explícito: «do not use shared live bind mounts in a way that can rewrite staging
caches»). Ninguna propuesta/candidato/relación real participó de ningún test.

| Suite | Resultado | Qué protege |
|---|---|---|
| `ReviewedProposalPreflightTest` (**nuevo**) | **18/18 PASS** | Ausencia de efectos, vocabulario de bloqueo, write-set, paridad con `apply()` |
| `ReviewedProposalServiceTest` | **41/41 PASS**, sin editar una línea | El contrato C2 de TASK-0004 tras la extracción de la cadena de validación |
| `ReviewedProposalConfirmationTest` | **47/47 PASS**, sin editar | Confirmación humana + anulación de TASK-0006B/0006C |
| `ReviewedProposalGroupLockingTest` | **7/7 PASS**, sin editar | Serialización por advisory lock de grupo |
| `CanonicalConceptApplyServiceTest` | **21/21 PASS**, sin editar | Phase C1 |
| `CandidateConceptApprovalServiceTest` | **27/27 PASS**, sin editar | Camino legacy de aprobación |
| **Corrida de unidad combinada** | **161 tests** | — |
| `TaxonomyCandidateConceptLinkReviewTest` | **19/19 PASS** (6 nuevos/reescritos) | Regresión de UX de la PARTE 2 |
| `TaxonomyReviewedProposalResourceTest` | **11/11 PASS**, sin editar | Resource de solo lectura |
| `TaxonomyReviewedProposalConfirmationUiTest` | **9/9 PASS**, sin editar | UX de confirmación humana |
| `TaxonomyCandidateConceptLinkResourceTest` | **4/4 PASS**, sin editar | Formato de impacto predicho |
| **Corrida de Filament combinada** | **43 tests** | — |

**Que las 143 regresiones heredadas pasen sin editar una sola línea es la evidencia central de que la
extracción de la cadena de validación no cambió el comportamiento de `apply()`.** Si el orden de las
compuertas, una constante `ABORT_*` o un desenlace hubieran cambiado, estas suites lo detectarían: ya
cubren tamper, obsolescencia, drift por campo, entidad faltante, fuente ya resuelta, coherencia de
grupo, validación de relación, idempotencia y concurrencia.

Dos cosas que conviene declarar en vez de dejarlas implícitas:

1. **La corrida base previa al refactor NO sirve como verificación de regresión.** Se lanzó antes de
   terminar la extracción, y PHPUnit autocarga una clase una sola vez, así que corrió contra el
   `ReviewedProposalService` **anterior**. Dio 95/95 y se conserva como **base**, no como prueba. Las
   161 pruebas de la tabla se reejecutaron **completas** contra el código final.
2. **Tres fixtures de test propios fallaron antes de quedar bien**, y los tres eran errores del
   fixture, no del código de producción: borrar un concepto arrastraba el candidato por FK en cascada
   (se separó el concepto DESTINO del SUGERIDO); el guard del modelo rechaza -correctamente- crear una
   relación aprobada conflictiva (se siembra el estado con el query builder); y
   `taxonomy_concept_relations` tiene `UNIQUE(source, target, relation_type)`, así que un duplicado
   exacto **no se puede insertar por ningún camino** (se usa la relación simétrica inversa, que para
   `RELATED_TO` -no direccional- es la MISMA relación semántica y la validación rechaza con
   `DUPLICATE_VIA_SYMMETRY`). Ese último cambio dejó el test **más fuerte**: una reimplementación
   ingenua del preflight que sólo buscara duplicados exactos no detectaría ese caso.

### 7.2 El único fallo local, y por qué es ajeno a este diff

`TaxonomyCandidateConceptLinkReviewTest::viewing_a_propose_new_concept_candidate_with_duplicate_signals_does_not_500`
falla localmente con HTTP 500 y
`RuntimeException: The "intl" PHP extension is required to use the [format] method`. Es el gap
preexistente de `ext-intl` de este entorno (`TextEntry::make('confidence')->numeric(4)` en la página de
detalle llama a `Number::format()`), verde en staging.

**No se asumió: se verificó.** Se restauró ese archivo a su versión de HEAD `510400a` (sin ningún
cambio de esta tarea) y el test **falla idéntico, con la misma excepción**. El fallo es anterior a este
diff.

Consecuencia de diseño que esto motivó: el texto nuevo de la página de detalle se extrajo a
`TaxonomyCandidateConceptLinkResource::frozenProposalNotice()` y el test lo verifica **directamente**,
sin renderizar la página. Así la cobertura nueva es determinística localmente, y la cobertura de
renderizado sigue siendo la del test heredado.

### 7.3 Staging

*(completado tras el despliegue; ver §7.4)*

---

## 8. PARTE 2 — Corrección de UX del candidato

### 8.1 El defecto, con su causa raíz

Reportado en el comentario `5947407519`, observado por el dueño **durante su propio paso de
confirmación legítimo**: la grilla de candidatos seguía ofreciendo `Revisar (congelar decisión C2)` en
las filas 266–269, aunque cada una ya tenía una propuesta `PENDING_APPLY` (#492–#495) y la tabla misma
las etiquetaba `CONGELADA_PENDIENTE`. Entrar por ahí producía «Identidad bilingüe inválida», que
parecía un defecto de #492 y no lo era: era un camino equivocado.

Causa raíz: `freezeReview` comprobaba sólo `status === pending` + permiso de update. Pero `freeze()`
**deliberadamente no toca el `status` del candidato** — congelar una decisión no publica ni resuelve
nada —, así que un candidato ya revisado sigue siendo `pending` **por diseño**. El chequeo confundía
«sigue pending» con «sigue sin revisar».

### 8.2 Qué se cambió

1. **`freezeReview` se esconde** cuando existe una propuesta `PENDING_APPLY` viva. El predicado es la
   **existencia de la propuesta**, no el status del candidato — el único que distingue de verdad los
   dos casos.
2. **Nueva acción `viewReviewedProposal`** («Ver propuesta revisada»): enlace de solo lectura a
   `TaxonomyReviewedProposalResource`, que es donde vive la acción correcta para este estado
   (`Confirmar decisión preparada`). Visible sólo si el usuario puede ver **esa** propuesta, resuelto
   con la policy del recurso de destino (por tipo de origen), así que nunca lleva a un 403/404.
3. **La insignia `CONGELADA_PENDIENTE` se conserva** sin cambios.
4. **Los candidatos con propuesta viva ya no se ofrecen como socios de convergencia bilingüe.** Es la
   otra puerta de entrada al mismo defecto: elegir uno habría hecho que el índice único parcial
   rechazara el grupo y `freezeBilingualConceptGroup()` revirtiera el grupo **completo**, haciéndole
   perder al revisor también la revisión de los demás miembros.
5. **La página de detalle lo dice explícitamente**: una entrada de solo lectura declara que ya existe
   una propuesta congelada, su id, su decisión y que la acción que corresponde vive en «Propuestas
   revisadas». Es donde el revisor aterriza antes de decidir; sin eso, el candidato se ve «pending»
   (lo está, por diseño) y parece sin revisar.
6. **Ningún botón de APPLY/Publish**, en ninguna de las dos pantallas.

### 8.3 Comportamiento elegido para `ABORTED`/`APPLIED` — declarado, no adivinado

El orquestador pidió explícitamente no suponerlo. **Sólo `PENDING_APPLY` esconde el botón.**

- **`ABORTED`**: el botón **sigue disponible**. `abort()` es terminal para la propuesta pero **no toca
  la fila fuente**, así que el candidato queda legítimamente pendiente de una revisión nueva — que es
  el camino de recuperación documentado para una propuesta obsoleta. Esconderlo dejaría al candidato
  sin **ninguna** forma de volver a revisarse, y el índice único parcial tampoco lo impide. Cubierto
  por un test que fuerza un abort real por obsolescencia y comprueba que el botón reaparece.
- **`APPLIED`**: `apply()` resuelve el candidato (`published`/`rejected`/`context_required`), así que
  el chequeo de `status === pending` que ya existía lo cubre solo. Un candidato `pending` con una
  propuesta `APPLIED` **no lo puede producir `apply()` por ningún camino**; si apareciera por cirugía
  manual, se trata con la misma regla que `ABORTED` (revisable), en vez de agregar un caso especial
  para un estado que la aplicación no genera.

La regla coincide exactamente con lo que la base de datos permite: donde el botón se esconde, un
`freeze()` nuevo fallaría de todos modos por el índice parcial.

### 8.4 La visibilidad de la UI no es la salvaguarda

Se verifica por test: tras un primer congelamiento, la invocación **directa del servicio** —
sorteando por completo la UI — sigue devolviendo `ALREADY_HAS_PENDING_PROPOSAL`, sigue habiendo
exactamente una propuesta `PENDING_APPLY`, y la primera decisión congelada **no se reescribe**. La
autorización server-side (`policy update` dentro de `freeze()`) tampoco cambió.

### 8.5 Coste de consultas

`liveFrozenProposal()` usa la relación **ya cargada** `$record->reviewedProposals`, la misma que
alimenta la columna `Propuesta C2`. Eloquent la cachea por fila, así que la corrección **no agrega
consultas** a la grilla. El filtro de convergencia es una cláusula `whereDoesntHave` dentro de la
consulta que ya existía.

---

## 9. Estructura de evidencia exigida por el contrato de cierre

### A) Gates heredados aprobados que siguen vigentes

| Gate | Origen | Estado tras este diff |
|---|---|---|
| Inmutabilidad del payload congelado (9 campos) | TASK-0004 | **Intacto.** El preflight no escribe; el refactor no cambió `computePayloadFingerprint()` ni sus entradas. Verificado en los 12 reales: los 12 `payload_fingerprint` válidos. |
| Separación REVIEW / CONFIRM / APPLY | TASK-0006B | **Intacto.** El preflight es un cuarto evento de **solo lectura** que no participa del ciclo. |
| Gate de confirmación humana exigible | TASK-0006B | **Intacto.** Mismo predicado (`requires_human_confirmation`), misma posición en la cadena (después de tamper y obsolescencia). |
| `confirm()` sólo por HTTP autenticado | TASK-0006C | **Intacto, no tocado.** Las 8 confirmaciones reales siguen siendo `http`. |
| Asimetría anulación/reasignación de confirmación | TASK-0006C | **Intacto, no tocado.** |
| Guard de publicación de modelo (candidatos y relaciones) | TASK-0004 HIGH-2 | **Intacto.** `withC2PublicationContext()` sigue encendiéndose sólo alrededor de las mismas dos escrituras. |
| Índices únicos parciales de un `PENDING_APPLY` | TASK-0006B | **Intacto.** La PARTE 2 alinea la UI con ellos; no los modifica. |
| Serialización por advisory lock de grupo, sin deadlock | TASK-0006B re-audit | **Intacto.** `apply()` sigue tomándolo como primera acción de su transacción, antes de cualquier `lockForUpdate()`; se re-toma en la evaluación del grupo cuando corre con locks. El preflight **no** lo toma (probado). |
| `canEdit()`/`canDelete()` cerrados bajo revisión C2 | TASK-0005 re-audit | **Intacto, no tocado** (§6). |
| Regresión de 32 consultas de búsqueda | heredada | **Heredada sin cambios**: este diff no toca búsqueda, ranking ni semántica de taxonomía publicada (§10). |

### B) Evidencia nueva ejecutada en esta ronda (toda de solo lectura)

1. Preflight real de las **12** propuestas: 9 `READY_TO_APPLY`, 3 `NEEDS_REVALIDATION`,
   `write_statements_observed: 0`.
2. Los **12** `payload_fingerprint` recomputados y válidos — cero tamper en datos reales.
3. Dos fingerprints de estado distintos confirmados, y confirmado cuál es el actual.
4. Conteos protegidos verificados antes y después: 10 / 2 / 142 / 81 / 9749 / 12 / 0 applied.
5. **Cero** relaciones aprobadas; #61 y #62 siguen `candidate`.
6. Coherencia real del grupo bilingüe #629/#630 (2/2, misma identidad, mismo fingerprint, sin drift).
7. Existencia real del concepto destino #2890 de #491, y su fecha de creación (13:07:11) comparada
   con el `reviewed_at` de #420–#422 (§4.2).
8. Las 8 confirmaciones humanas presentes y todas por canal `http`.

### C) Gates heredados invalidados por este diff

**Ninguno.**

El cambio de mayor riesgo es la extracción de la cadena de validación fuera de `apply()`. Mitigación y
verificación: el orden y los desenlaces se preservaron compuerta por compuerta (incluidas las dos
excepciones deliberadas de §2.2), las constantes `ABORT_*` escritas son las mismas, y las suites
heredadas de C2/confirmación/concurrencia se reejecutaron completas contra el código nuevo (§7).

### D) Gates no aplicables a esta ronda

- **APPLY / PUBLISH**: no ejecutados y no autorizados. Ninguna propuesta cambió de estado.
- **Migraciones**: ninguna. Esta tarea no agrega ni modifica esquema.
- **Rotación de credenciales**: no corresponde y sigue siendo condición STOP.
- **Merge a `main` / despliegue a producción**: no corresponde.
- **Transición de datos de supersesión**: diseñada, **no autorizada**, no ejecutada.

---

## 10. Invariantes y alcance

- **Conteos protegidos tras TASK-0006D**: 10 / 2 / 142 / 81 / 9749 / 12 reviewed / 0 applied —
  idénticos a los de entrada (§3).
- **Regresión de búsqueda**: heredada sin cambios. Este diff toca `ReviewedProposalService` (refactor
  + preflight), dos recursos de Filament (visibilidad y un enlace de solo lectura), una página de
  detalle (una entrada de solo lectura) y un comando nuevo de solo lectura. No toca búsqueda, ranking,
  embeddings, CPV ni la semántica de la taxonomía publicada.
- **Sin cambios de esquema**, sin backfill, sin datos sembrados.
