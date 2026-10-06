<?php

namespace App\Services\Taxonomy;

use App\Models\TaxonomyCandidateConceptLink;
use App\Models\TaxonomyCanonicalConcept;
use App\Models\TaxonomyConceptRelation;
use App\Models\TaxonomyReviewedProposal;
use App\Models\User;
use App\Services\TaxonomyAuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Phase C2 (TASK-0004, Issue #2 comentario `5886148283`): implementa el contrato que
 * `CanonicalConceptApplyService` (Phase C1) dejó explícitamente pendiente en su propio docblock:
 *
 *   REVIEWED_PROPOSAL -> payload inmutable y con fingerprint -> APPLY(payload) -> VALIDATE
 *   server-side -> COMMIT/ROLLBACK transaccional -> AUDIT
 *
 * Dos pasos DELIBERADAMENTE separados, nunca colapsados en uno:
 *
 * 1. `freeze()` - un humano ya tomó una decisión de revisión (MAP_TO_EXISTING / CREATE_NEW /
 *    REJECT sobre un candidato de `taxonomy_candidate_concept_links`, o PUBLISH_RELATION / REJECT
 *    sobre una relación candidata de `taxonomy_concept_relations`). Esta decisión se CONGELA como
 *    una fila de `taxonomy_reviewed_proposals`, con un fingerprint de los campos de decisión
 *    (`payload_fingerprint`, detección de manipulación) y el estado real de la taxonomía en ese
 *    instante (`taxonomy_state_fingerprint`, detección de obsolescencia). `freeze()` NUNCA escribe
 *    en `taxonomy_term_concepts`, `taxonomy_canonical_concepts` ni cambia el `status` del
 *    candidato/relación original - solo dejarlo "revisado" no publica nada.
 * 2. `apply()` - toma un payload YA congelado y, con una autorización de ejecución EXPLÍCITA y
 *    separada (mismo formato/convención que `CanonicalConceptApplyService::apply()`:
 *    `$authorizationReference` no vacío con al menos un dígito, `$targetEnvironment`
 *    auto-capturado, nunca provisto por quien llama), revalida todo contra el estado REAL (no el
 *    congelado) y recién ahí escribe. Puede aplicarse mucho después de `freeze()`, por una persona
 *    distinta - por diseño (hallazgo del orquestador: "distinguish review decision from execution
 *    authorization").
 *
 * Invariante central del hallazgo 1 de TASK-0004: ninguno de los dos métodos vuelve a correr
 * `CanonicalConceptBuilderService::dryRun()`/`scoredCandidatesForTerm()` para "redescubrir" o
 * recalcular la propuesta - la propuesta es exactamente la que el humano revisó
 * (`suggested_concept_id`/`suggested_new_concept_name` del candidato ya existente, o los campos ya
 * existentes de la relación candidata), nunca una nueva corrida del Builder.
 *
 * IDEMPOTENCIA (hallazgo 4): la salvaguarda real contra doble-aplicación es `lockForUpdate()` +
 * re-chequeo de `status` DENTRO de la transacción de `apply()` (idéntico patrón a
 * `CandidateConceptApprovalService::approve()`/`reject()`) - un segundo `apply()` del mismo
 * `$proposalId` ve `status=APPLIED` bajo el lock y devuelve `RESULT_ALREADY_APPLIED` sin escribir
 * nada de nuevo (ni mapeos, ni conceptos, ni relaciones, ni filas de auditoría). Contra doble-
 * `freeze()` concurrente del MISMO candidato/relación, la salvaguarda es el índice único parcial de
 * la migración (`WHERE status = 'PENDING_APPLY'`), no una lectura previa de la aplicación.
 *
 * TASK-0004, re-audit HIGH-1 (Issue #2 comentario `5890113782`): el payload congelado incluye TODOS
 * los campos fuente decision-relevantes (`term_id`, `new_concept_name`/`target_concept_id` para
 * candidatos; `source_concept_id`/`target_concept_id`/`relation_type` para relaciones), leídos UNA
 * sola vez al congelar. `apply()` nunca redescubre estos valores de la fila viva para decidir QUÉ
 * escribir - solo relee la fila viva para IDENTIDAD/status/compatibilidad, comparando cada campo
 * decision-relevante contra su snapshot congelado y abortando (`ABORT_SOURCE_DRIFT`) si drifearon,
 * en vez de publicar en silencio con un valor desactualizado.
 *
 * TASK-0004, re-audit HIGH-2 (Issue #2 comentario `5890113782`): las DOS escrituras que constituyen
 * "publicación" (`taxonomy_candidate_concept_links.status -> published`,
 * `taxonomy_concept_relations.status -> approved`) están bloqueadas a nivel de MODELO
 * (`TaxonomyCandidateConceptLink::booted()`/`TaxonomyConceptRelation::booted()`) para cualquier
 * camino que no encienda `isApplyingC2Publication()` - que SOLO este servicio enciende, y
 * únicamente alrededor de esas dos escrituras específicas. `CandidateConceptApprovalService`
 * (Phase 3.1/B3, aprobado en TASK-0001) sigue existiendo como código/tests históricos, pero
 * `approve()`/`resolveNewConceptProposal()` (MAP_TO_EXISTING/CREATE_NEW) ya NO pueden publicar de
 * verdad - la transacción que los contiene revierte en el momento en que intentan la transición de
 * status bloqueada. `reject()` no se ve afectado (nunca escribió una tabla protegida). Este NO es
 * un servicio "aditivo y paralelo" en el sentido de TASK-0004 original - la corrección de este
 * re-audit cierra deliberadamente ese bypass.
 *
 * TASK-0004, re-audit ronda 3, corrección A (Issue #2 comentario `5892711739`): CREATE_NEW exige
 * `new_concept_name` EXPLÍCITO y no vacío en el payload de decisión pasado a `freeze()` -
 * `RESULT_VALIDATION_FAILED` si falta o está en blanco. La ronda anterior movió el fallback mutable
 * de `apply()` a `freeze()`, pero seguía siendo un fallback implícito
 * (`?? $candidate->suggested_new_concept_name`) - eso permitía que el valor generado por el sistema
 * se convirtiera en "el nombre revisado por un humano" sin que ningún humano lo eligiera realmente.
 * Ahora `freeze()` no completa ese campo por ningún camino; `suggested_new_concept_name` queda
 * disponible solo como sugerencia para que la UI la muestre.
 *
 * TASK-0004, re-audit ronda 3, corrección C (Issue #2 comentario `5892711739`): cuarto desenlace de
 * revisión para TERM_CONCEPT_LINK, `DECISION_CONTEXT_REQUIRED` - un término puede ser válido en el
 * vocabulario del dominio pero demasiado genérico/inespecífico para sostener un mapeo directo
 * producto/servicio/CPV (hallazgo de revisión humana de dominio, no hardcodeado a los 10 candidatos
 * reales actuales). Igual que CREATE_NEW, exige un `context_reason` explícito no vacío. `apply()`
 * NUNCA escribe `taxonomy_term_concepts` ni crea un concepto para esta decisión - el candidato pasa a
 * `TaxonomyCandidateConceptLink::STATUS_CONTEXT_REQUIRED` (distinto de `STATUS_REJECTED`: el
 * candidato NO se descarta, y el término/motivo del revisor queda preservado en `review_notes` para
 * un POSIBLE uso futuro como evidencia contextual - sin que eso implique que algún consumidor de
 * búsqueda lo lea hoy; ninguno lo hace, ver auditoría de consumidores más abajo). El fingerprint de
 * tamper-detection (que incluye el campo `decision`) ya cubría genéricamente que nadie pueda mutar
 * una decisión congelada de CONTEXT_REQUIRED hacia MAP_TO_EXISTING/CREATE_NEW sin que `apply()` lo
 * detecte y aborte con cero escrituras - no fue necesario un mecanismo nuevo para eso, solo un test
 * que lo pruebe explícitamente.
 *
 * TASK-0004, re-audit ronda 4 (Issue #2 comentario `5909267134`): dos defectos semánticos cerrados
 * sobre las correcciones de la ronda 3, sin tocar HIGH-1/HIGH-2/GATE-3/GATE-4/MEDIUM-5 (reconfirmados
 * como correctos por ese mismo comentario):
 *
 * 1. CREATE_NEW conflaba el nombre REVISADO por el humano con el nombre SUGERIDO por el Builder al
 *    validar drift en `apply()` - comparaba `new_concept_name` (revisado) contra
 *    `$candidate->suggested_new_concept_name` (vivo), lo cual hacía imposible que un revisor
 *    corrigiera/normalizara legítimamente el nombre sugerido (Builder sugiere "X", humano aprueba
 *    "Y" -> abortaba tratando la discrepancia REVISOR-VS-SUGERENCIA como si fuera DRIFT DE LA
 *    FUENTE). `freeze()` ahora congela DOS campos con roles distintos: `new_concept_name` (lo que se
 *    publica) y `source_suggested_new_concept_name` (snapshot de la sugerencia, usado
 *    EXCLUSIVAMENTE para comparar contra la fila viva). `apply()` publica desde el primero y
 *    detecta drift comparando el segundo.
 * 2. CONTEXT_REQUIRED se resolvía ANTES del chequeo de drift de `term_id` compartido, así que un
 *    candidato cuyo `suggested_term_id` cambió después de `freeze()` podía terminar con la decisión
 *    "necesita contexto" aplicada al término NUEVO, nunca revisado por el humano - violando la misma
 *    regla de inmutabilidad que ya protegía a MAP_TO_EXISTING/CREATE_NEW. El chequeo de `term_id` se
 *    movió para correr ANTES de la rama CONTEXT_REQUIRED (sigue corriendo DESPUÉS de REJECT, la única
 *    decisión que genuinamente no resuelve ningún término específico).
 *
 * TASK-0006D (Issue #2 comentario `5949253156`), PARTE 1: se agrega un camino de SOLO LECTURA,
 * `preflight()`, y la validación deja de estar embutida dentro de `apply()`.
 *
 * El problema concreto que resuelve: hasta acá, la única forma de saber si una propuesta congelada
 * seguía siendo aplicable era llamar a `apply()` - y `apply()` es destructivo cuando falla, porque
 * registra obsolescencia/tamper/drift con `abort()`, que pasa la propuesta a `ABORTED` de forma
 * TERMINAL. Como re-congelar está bloqueado por el índice único parcial, "probar con apply() para ver
 * qué pasa" podía quemar una decisión humana sin recuperación posible. El orquestador lo resumió así:
 * «apply() is NOT a safe preflight API».
 *
 * La corrección NO es una segunda implementación de la validación - eso divergiría con el tiempo y
 * sería peor que el problema original. La cadena ENTERA se extrajo a `evaluateApplicability()`, que
 * ahora usan los dos: `apply()` con `lockRows: true` y `preflight()` con `false`. Lo único que
 * difiere es CÓMO se leen las filas fuente; QUÉ se valida, en qué ORDEN y con qué desenlace es un
 * solo cuerpo de código. `applyAbortReasonForBlocker()` declara explícitamente la correspondencia
 * entre cada bloqueo del preflight y el `ABORT_*` que `apply()` escribe de verdad, y un test de
 * paridad la verifica bloqueo por bloqueo.
 *
 * `freeze()`, `confirm()` e `invalidateConfirmation()` no cambian en nada por esta tarea.
 *
 * TASK-0006D, re-audit (Issue #2 comentario `5952211890`): se cierran dos defectos de SEGURIDAD DE
 * EJECUCIÓN del camino agrupado, ninguno de los cuales era visible en los datos reales pero los dos
 * alcanzables:
 *
 * 1. El grupo bilingüe validaba el `payload_fingerprint` SOLO de la propuesta de entrada, mientras
 *    `writeBilingualGroupCreateNew()` publicaba y marcaba `APPLIED` a TODOS los hermanos pendientes -
 *    así que `apply(#629)` podía escribir #630 sin revalidar el payload inmutable de #630. Ahora el
 *    fingerprint de CADA miembro pendiente se revalida como PRIMERA compuerta de ese miembro, antes de
 *    leer un solo campo de su payload o de su fila fuente (ver `evaluateBilingualGroup()`). El defecto
 *    venía del diseño original de la sección D de TASK-0006B; la extracción de TASK-0006D lo heredó.
 * 2. Un bloqueo terminal detectado en un hermano abortaba únicamente la propuesta de ENTRADA, dejando
 *    al hermano `PENDING_APPLY` dentro de un grupo que ya había fallado como grupo - un hermano varado
 *    en silencio, aparentemente aplicable. Ahora todo bloqueo terminal de un grupo aborta el grupo
 *    COMPLETO, atómicamente y con una fila de auditoría por miembro (ver `abortWholeGroup()`).
 *    `HUMAN_CONFIRMATION_REQUIRED` sigue siendo NO terminal y no aborta nada.
 *
 * TASK-0006E (Issue #2 comentario `5955148859`): se agrega el CUARTO evento del ciclo de vida,
 * `supersedeStaleProposal()`, y con él el estado terminal `SUPERSEDED`.
 *
 *   REVIEW/FREEZE  ->  CONFIRM  ->  APPLY
 *                  \->  SUPERSEDE (sale de la cola sin destruirse; el origen vuelve a revisión)
 *
 * El problema que resuelve: una revisión cuyo `taxonomy_state_fingerprint` caducó estaba en un
 * callejón sin salida. `apply()` la habría dejado `ABORTED` -terminal- y re-congelar está bloqueado por
 * el índice único parcial, así que no existía forma de volver a pedir la decisión humana sin destruir
 * la anterior. La supersesión retira la fila de `PENDING_APPLY` conservándola ÍNTEGRA (decisión,
 * payload, los dos fingerprints, revisor y `reviewed_at` intactos) y, como los índices únicos parciales
 * filtran por `status = 'PENDING_APPLY'`, eso **libera el slot** del origen: el candidato reaparece en
 * la cola de revisión normal sin que ningún índice se debilite.
 *
 * Dos consecuencias que hubo que cerrar explícitamente, no son detalles:
 * - `SUPERSEDED` no es `APPLIED` ni `ABORTED`, así que sin una compuerta propia habría caído por la
 *   cadena de validación y `apply()` lo habría ABORTADO, pisando el rastro recién grabado. Hay una
 *   compuerta terminal en `evaluateApplicability()` que devuelve `ALREADY_SUPERSEDED` con cero
 *   escrituras, y el trigger de base de datos además prohíbe salir de ese estado.
 * - `unconfirmedMembers()` pasó a mirar sólo miembros `PENDING_APPLY`: exigir la confirmación de un
 *   hermano ya retirado de la cola bloquearía el grupo por una fila que nadie va a ejecutar.
 *
 * TASK-0007 (Issue #2 comentario `5997693379`): se agrega `applyBatch()`, la ejecución ATÓMICA de un
 * CONJUNTO de propuestas revisadas contra un único baseline, y su preflight `previewBatch()`.
 *
 * EL HUECO QUE CIERRA, que no es un defecto de datos ni de `apply()`: `apply()` fue diseñado para UN
 * payload, y su compuerta de obsolescencia exige que el estado de la taxonomía siga siendo el que el
 * humano revisó. Varias propuestas de una misma cola revisada mutan deliberadamente las entradas de
 * `dryRunInputFingerprint()` -una MAP_TO_EXISTING inserta `taxonomy_term_concepts`, un grupo
 * CREATE_NEW crea un concepto más dos links, un REJECT de relación escribe
 * `taxonomy_concept_relations`-, así que la PRIMERA escritura que cambia el grafo deja obsoletas a
 * todas las demás propuestas del mismo conjunto. Encadenar `apply()` las habría ABORTADO una por una,
 * de forma terminal, aunque hubieran sido revisadas contra el MISMO snapshot. Un lote no es una
 * optimización: es la única forma correcta de ejecutar un conjunto revisado como lo que es.
 *
 * Lo que NO cambia: `apply()` conserva su semántica completa para el uso de una sola propuesta, y la
 * validación del lote es la MISMA `evaluateApplicability()` -no hay un segundo motor de reglas-, con
 * el baseline del lote como único fingerprint de obsolescencia. Lo único nuevo en el camino de una
 * sola propuesta es el advisory lock COMÚN de ejecución (`executionAdvisoryLockKey()`), que los dos
 * caminos toman primero: sin él, un `apply()` suelto podría cambiar el grafo entre la validación y la
 * escritura de un lote en curso.
 */
class ReviewedProposalService
{
    public const PAYLOAD_VERSION = 'taxonomy-reviewed-proposal/c2-v1';

    public const RESULT_FROZEN = 'FROZEN';

    public const RESULT_APPLIED = 'APPLIED';

    public const RESULT_ALREADY_APPLIED = 'ALREADY_APPLIED';

    public const RESULT_ALREADY_ABORTED = 'ALREADY_ABORTED';

    public const RESULT_ALREADY_HAS_PENDING_PROPOSAL = 'ALREADY_HAS_PENDING_PROPOSAL';

    public const RESULT_NOT_FOUND = 'NOT_FOUND';

    public const RESULT_UNAUTHORIZED = 'UNAUTHORIZED';

    public const RESULT_ALREADY_PROCESSED = 'ALREADY_PROCESSED';

    public const RESULT_ABORTED = 'ABORTED';

    /**
     * TASK-0004, re-audit correction A (Issue #2 comentario `5892711739`): `freeze()` rechaza una
     * decisión cuyo payload explícito no trae un campo requerido no vacío (ej. `new_concept_name`
     * en CREATE_NEW, `context_reason` en CONTEXT_REQUIRED) - nunca lo completa implícitamente desde
     * un campo mutable del candidato. No crea ninguna fila de `taxonomy_reviewed_proposals`.
     */
    public const RESULT_VALIDATION_FAILED = 'VALIDATION_FAILED';

    /**
     * TASK-0006B (Issue #2 comentario `5936206843`), sección A: resultados de `confirm()`, el tercer
     * evento del ciclo de gobernanza - REVIEW/FREEZE, **CONFIRM**, APPLY. Ninguno de ellos escribe
     * taxonomía publicada.
     */
    public const RESULT_CONFIRMED = 'CONFIRMED';

    /** Replay idempotente de `confirm()`: cero escrituras nuevas, cero filas de auditoría nuevas. */
    public const RESULT_ALREADY_CONFIRMED = 'ALREADY_CONFIRMED';

    /** La propuesta no está marcada como pendiente de confirmación humana - no hay nada que confirmar. */
    public const RESULT_NOT_AWAITING_CONFIRMATION = 'NOT_AWAITING_CONFIRMATION';

    /**
     * TASK-0006C (Issue #2 comentario `5939903005` punto 6, aprobado en `5939882569` punto 7):
     * `confirm()` se intentó desde un canal que NO es una petición HTTP autenticada - consola,
     * `tinker`, un comando de artisan o un job. Es el camino que produjo la atribución inválida de
     * #492–#495: el agente autenticó la cuenta #3 con `Auth::login()` desde consola y satisfizo
     * `Auth::id() === $confirmer->id` derrotando justamente el invariante anti-suplantación.
     *
     * Rechazar acá no debilita nada: solo QUITA un camino.
     */
    public const RESULT_CHANNEL_NOT_HUMAN = 'CHANNEL_NOT_HUMAN';

    /**
     * TASK-0006C: la confirmación inválida de una propuesta fue ANULADA (vuelve a estar sin
     * confirmar). Nunca se reasigna a otro confirmador - ver `invalidateConfirmation()`.
     */
    public const RESULT_CONFIRMATION_INVALIDATED = 'CONFIRMATION_INVALIDATED';

    /** No hay confirmación que anular: `invalidateConfirmation()` es idempotente. */
    public const RESULT_NOT_CONFIRMED = 'NOT_CONFIRMED';

    /**
     * TASK-0006E (Issue #2 comentario `5955148859`): resultados de `supersedeStaleProposal()`, la
     * transición de ciclo de vida NO DESTRUCTIVA que retira de la cola una revisión obsoleta sin
     * borrarla, sobrescribirla, abortarla ni aplicarla.
     */
    public const RESULT_SUPERSEDED = 'SUPERSEDED';

    /** Replay idempotente: ya estaba supersedida, cero escrituras nuevas. */
    public const RESULT_ALREADY_SUPERSEDED = 'ALREADY_SUPERSEDED';

    /**
     * La propuesta NO está obsoleta, así que el camino de supersesión-por-obsolescencia la rechaza.
     * Requisito explícito del comentario: «non-stale proposal cannot be superseded through stale-only
     * flow». Retirar de la cola una revisión que sigue siendo válida sería una decisión de gobernanza
     * distinta, con su propia autorización - no este camino.
     */
    public const RESULT_NOT_STALE = 'NOT_STALE';

    /**
     * El `payload_fingerprint` de la propuesta ya no coincide con sus propios campos de decisión. No se
     * supersede: primero hay que entender quién los modificó. Supersedir taparía el hallazgo.
     */
    public const RESULT_TAMPERED_PAYLOAD = 'TAMPERED_PAYLOAD';

    /**
     * La fila fuente ya no está en un estado que permita volver a revisarla (no existe, o ya la
     * resolvió otro camino). Liberar el slot no serviría de nada: el candidato no volvería a la cola.
     */
    public const RESULT_SOURCE_NOT_REVIEWABLE = 'SOURCE_NOT_REVIEWABLE';

    /**
     * TASK-0006B, sección A: `apply()` rechazó la propuesta porque exige confirmación humana y
     * todavía no la tiene. DELIBERADAMENTE **no** es un `ABORT`: abortar es terminal y quemaría la
     * propuesta para siempre (y re-congelar está bloqueado por el índice único parcial), así que un
     * `apply()` prematuro dejaría la decisión humana irrecuperable. Esto deja la propuesta intacta
     * en `PENDING_APPLY` para que pueda aplicarse DESPUÉS de confirmarse. Cero escrituras.
     */
    public const RESULT_HUMAN_CONFIRMATION_REQUIRED = 'HUMAN_CONFIRMATION_REQUIRED';

    public const ABORT_TAMPER_DETECTED = 'TAMPER_DETECTED';

    public const ABORT_STALE_TAXONOMY_STATE = 'STALE_TAXONOMY_STATE';

    public const ABORT_ENTITY_MISSING = 'ENTITY_MISSING';

    public const ABORT_CANDIDATE_ALREADY_RESOLVED = 'CANDIDATE_ALREADY_RESOLVED';

    public const ABORT_RELATION_ALREADY_RESOLVED = 'RELATION_ALREADY_RESOLVED';

    public const ABORT_RELATION_INVALID_AT_APPLY_TIME = 'RELATION_INVALID_AT_APPLY_TIME';

    /**
     * TASK-0004, re-audit HIGH-1 (Issue #2 comentario `5890113782`): un campo fuente
     * decision-relevante (ej. `suggested_term_id`, `suggested_new_concept_name`, o los
     * source/target/relation_type de una relación) cambió en la fila viva DESPUÉS de `freeze()` -
     * el payload congelado ya no describe la misma realidad que un humano revisó. Distinto de
     * `ABORT_STALE_TAXONOMY_STATE` (que cubre el ESTADO GLOBAL de la taxonomía) - este cubre
     * específicamente la fila fuente individual referenciada por ESTE payload.
     */
    public const ABORT_SOURCE_DRIFT = 'SOURCE_FIELD_DRIFTED';

    /**
     * TASK-0006B, sección D: un miembro del grupo bilingüe ya no es aplicable (falta, no está
     * `pending`, o su decisión/grupo no coincide). Abortar el grupo COMPLETO es lo correcto: aplicar
     * solo una mitad crearía el concepto con un único término adjunto y dejaría al hermano huérfano
     * sin forma de converger sin un segundo concepto - exactamente el duplicado que la sección D
     * prohíbe.
     */
    public const ABORT_BILINGUAL_GROUP_NOT_APPLICABLE = 'BILINGUAL_GROUP_NOT_APPLICABLE';

    /**
     * TASK-0006D (Issue #2 comentario `5949253156`), PARTE 1: vocabulario de bloqueo del PREFLIGHT
     * de solo lectura. Son nombres de DIAGNÓSTICO, no estados persistidos: `preflight()` nunca
     * escribe `status`, así que ninguno de estos valores llega jamás a una columna.
     *
     * Por qué un vocabulario propio y no reusar las constantes `ABORT_*`: los `ABORT_*` describen lo
     * que `apply()` ESCRIBIRÍA en `application_result` al quemar la propuesta, y dos de ellos
     * (`ABORT_CANDIDATE_ALREADY_RESOLVED`/`ABORT_RELATION_ALREADY_RESOLVED`) son el MISMO hallazgo
     * para dos tipos de origen. El preflight responde una pregunta distinta - "¿qué impide aplicar
     * esto?" - y el orquestador pidió un vocabulario específico. La correspondencia entre los dos no
     * se deja al lector: `applyOutcomeForBlocker()` la declara explícitamente y un test de paridad la
     * verifica, así que un bloqueo del preflight NO puede dejar de corresponder con el desenlace real
     * de `apply()` sin que el test falle.
     */
    public const PREFLIGHT_READY_TO_APPLY = 'READY_TO_APPLY';

    public const PREFLIGHT_TAMPER_DETECTED = 'TAMPER_DETECTED';

    public const PREFLIGHT_STALE_TAXONOMY_STATE = 'STALE_TAXONOMY_STATE';

    public const PREFLIGHT_ENTITY_MISSING = 'ENTITY_MISSING';

    public const PREFLIGHT_SOURCE_DRIFT = 'SOURCE_DRIFT';

    public const PREFLIGHT_SOURCE_ALREADY_RESOLVED = 'SOURCE_ALREADY_RESOLVED';

    public const PREFLIGHT_HUMAN_CONFIRMATION_REQUIRED = 'HUMAN_CONFIRMATION_REQUIRED';

    public const PREFLIGHT_GROUP_INCOMPLETE_OR_INCONSISTENT = 'GROUP_INCOMPLETE_OR_INCONSISTENT';

    public const PREFLIGHT_RELATION_VALIDATION_FAILED = 'RELATION_VALIDATION_FAILED';

    /**
     * No son "bloqueos" de validación sino estados ya resueltos: la propuesta salió de
     * `PENDING_APPLY` y no hay nada que aplicar. Se distinguen de los bloqueos reales para que el
     * informe no diga "necesita revalidación" sobre algo que ya se ejecutó.
     */
    public const PREFLIGHT_ALREADY_APPLIED = 'ALREADY_APPLIED';

    public const PREFLIGHT_ALREADY_ABORTED = 'ALREADY_ABORTED';

    /**
     * TASK-0006E: la propuesta fue retirada de la cola de forma no destructiva por obsolescencia. Es
     * un estado histórico TERMINAL - ni `READY_TO_APPLY` ni un bloqueo que se pueda corregir en esta
     * fila: la decisión nueva vive en una propuesta NUEVA.
     */
    public const PREFLIGHT_ALREADY_SUPERSEDED = 'ALREADY_SUPERSEDED';

    public const PREFLIGHT_NOT_FOUND = 'NOT_FOUND';

    /**
     * TASK-0006D, PARTE 4: categorías de gobernanza del informe pre-APPLY. Derivadas del bloqueo, no
     * declaradas a mano - ver `governanceCategoryForBlocker()`.
     */
    public const CATEGORY_READY_TO_APPLY = 'READY_TO_APPLY';

    public const CATEGORY_NEEDS_REVALIDATION = 'NEEDS_REVALIDATION';

    public const CATEGORY_BLOCKED_FOR_OTHER_REASON = 'BLOCKED_FOR_OTHER_REASON';

    /**
     * TASK-0007 (Issue #2 comentario `5997693379`), PARTE 1/PARTE 4: vocabulario del LOTE.
     *
     * Por qué un tercer vocabulario y no reusar el del preflight: un bloqueo de lote es una respuesta
     * sobre el CONJUNTO ("este lote no se ejecuta, y esta es la fila que lo impide"), no sobre una
     * propuesta suelta. La diferencia es de gobernanza, no cosmética: el orquestador pidió errores
     * explícitos de lote para que la autorización humana posterior pueda citarlos, y mezclarlos con
     * los del preflight haría ambiguo si lo que falló fue una fila o la ejecución entera.
     *
     * La correspondencia no se deja al lector ni se duplica: `batchBlockerForProposalBlocker()` es el
     * ÚNICO lugar donde se decide, lanza `LogicException` si aparece un bloqueo de propuesta sin
     * mapear, y un test de paridad la recorre bloqueo por bloqueo. Mismo mecanismo anti-divergencia
     * que `applyAbortReasonForBlocker()` en TASK-0006D.
     */
    public const BATCH_READY_TO_APPLY = 'BATCH_READY_TO_APPLY';

    public const BATCH_QUEUE_DRIFT = 'BATCH_QUEUE_DRIFT';

    public const BATCH_BASELINE_STALE = 'BATCH_BASELINE_STALE';

    public const BATCH_TAMPER_DETECTED = 'BATCH_TAMPER_DETECTED';

    public const BATCH_SOURCE_DRIFT = 'BATCH_SOURCE_DRIFT';

    public const BATCH_CONFIRMATION_REQUIRED = 'BATCH_CONFIRMATION_REQUIRED';

    public const BATCH_GROUP_INCOMPLETE = 'BATCH_GROUP_INCOMPLETE';

    public const BATCH_RELATION_INVALID = 'BATCH_RELATION_INVALID';

    public const BATCH_ALREADY_EXECUTED = 'BATCH_ALREADY_EXECUTED';

    /**
     * Dos bloqueos que el comentario no nombra pero que el camino de una sola propuesta SÍ distingue
     * (`ENTITY_MISSING` y `SOURCE_ALREADY_RESOLVED`). Se conservan separados en vez de colapsarlos en
     * `BATCH_SOURCE_DRIFT`: "la entidad ya no existe" y "otro camino ya resolvió el origen" piden
     * acciones humanas distintas, y fundirlos haría que el informe del lote fuera MENOS preciso que
     * el de la propuesta suelta - exactamente la divergencia que la PARTE 3 prohíbe.
     */
    public const BATCH_ENTITY_MISSING = 'BATCH_ENTITY_MISSING';

    public const BATCH_SOURCE_ALREADY_RESOLVED = 'BATCH_SOURCE_ALREADY_RESOLVED';

    /** El entorno auto-capturado no está autorizado para ejecutar un lote (PARTE 8). */
    public const BATCH_ENVIRONMENT_NOT_AUTHORIZED = 'BATCH_ENVIRONMENT_NOT_AUTHORIZED';

    /** Pedido vacío: no hay nada que ejecutar, y un lote vacío no es un lote exitoso. */
    public const BATCH_EMPTY_REQUEST = 'BATCH_EMPTY_REQUEST';

    /**
     * TASK-0007 re-audit (Issue #2 comentario `6011317053`, BLOQUEO 1): el conjunto PEDIDO no es
     * exactamente el conjunto que el manifiesto ATA.
     *
     * EL DEFECTO QUE CIERRA, aceptado sin reservas: el manifiesto verificaba que todas las propuestas
     * que ÉL ata siguieran vivas y que la cola no tuviera ninguna de más, pero NADIE comprobaba que el
     * conjunto a ejecutar fuera ese mismo conjunto. Con eso,
     * `--manifest=<manifiesto FULL aprobado> --id=491 --execute` verificaba el manifiesto **con éxito**
     * y ejecutaba únicamente #491 - justo lo que el manifiesto existe para impedir.
     *
     * Y el daño no es "queda una fila sin aplicar": #491 escribe `taxonomy_term_concepts`, así que
     * después de esa ejecución parcial el fingerprint global cambia y las otras once revisiones quedan
     * obsoletas. El conjunto atómico que el dueño autorizó deja de ser recuperable COMO ESE CONJUNTO.
     *
     * Vale para los dos alcances. `--allow-subset-manifest` significa «el MANIFIESTO ata un
     * subconjunto a propósito», nunca «tomá un subconjunto arbitrario de un manifiesto ya atado».
     */
    public const BATCH_MANIFEST_REQUEST_MISMATCH = 'BATCH_MANIFEST_REQUEST_MISMATCH';

    /**
     * TASK-0007 re-audit (Issue #2 comentario `6011317053`, BLOQUEO 3): la autorización del dueño no
     * estaba atada al hash del manifiesto.
     *
     * Un manifiesto auto-hasheado prueba «este archivo no se editó sin cambiar su hash». NO prueba
     * «este es el hash que el dueño autorizó». El escenario de fallo es concreto: el dueño autoriza el
     * hash A; después se genera un manifiesto B internamente válido; el operador corre B citando el
     * comentario que autorizó A; y si B coincide con el estado vivo, nada detecta que la referencia de
     * autorización y el manifiesto cargado describen conjuntos de ejecución distintos.
     *
     * La corrección exige un SEGUNDO insumo de confianza, independiente del archivo: el fingerprint que
     * el dueño autorizó, provisto aparte. Deliberadamente NO se deduce del propio manifiesto - eso
     * colapsaría los dos insumos en uno y volvería a no probar nada.
     */
    public const BATCH_AUTHORIZATION_FINGERPRINT_MISSING = 'BATCH_AUTHORIZATION_FINGERPRINT_MISSING';

    public const BATCH_AUTHORIZATION_FINGERPRINT_MALFORMED = 'BATCH_AUTHORIZATION_FINGERPRINT_MALFORMED';

    public const BATCH_AUTHORIZATION_FINGERPRINT_MISMATCH = 'BATCH_AUTHORIZATION_FINGERPRINT_MISMATCH';

    /**
     * TASK-0007 re-audit 2 (Issue #2 comentario `6015273402`, BLOQUEO A): en un entorno OPERATIVO, un
     * lote sin manifiesto no se ejecuta.
     *
     * EL BYPASS QUE CIERRA, aceptado sin reservas y sin atenuantes: la compuerta de autorización corría
     * dentro de `if ($manifest !== null)`, así que una invocación DIRECTA del servicio en staging
     * -`applyBatch($ids, $referencia)`, sin tercer ni cuarto argumento- abría la transacción y podía
     * ejecutar **sin manifiesto, sin hash autorizado y sin atadura exacta al conjunto autorizado**.
     * El CLI estaba bien, pero el servicio es público: Tinker, otro comando o un llamador futuro
     * entraban por ahí. Y no era teórico: el propio test de la ronda 2
     * `staging_passes_the_environment_gate_and_testing_is_only_the_fixture_exception` ponía
     * `APP_ENV=staging` y llamaba a `applyBatch()` sin manifiesto con éxito.
     *
     * Una compuerta de gobernanza que protege datos compartidos reales no puede vivir sólo en un
     * envoltorio de CLI. Ahora vive en el único camino de escritura.
     */
    public const BATCH_MANIFEST_REQUIRED = 'BATCH_MANIFEST_REQUIRED';

    public const BATCH_RESULT_APPLIED = 'BATCH_APPLIED';

    public const BATCH_RESULT_BLOCKED = 'BATCH_BLOCKED';

    public const BATCH_RESULT_ALREADY_EXECUTED = 'BATCH_ALREADY_EXECUTED';

    public const BATCH_MODE_PREFLIGHT = 'READ_ONLY_BATCH_PREFLIGHT';

    public const BATCH_MODE_EXECUTE = 'ATOMIC_BATCH_APPLY';

    /**
     * TASK-0007, PARTE 8, corregido por el re-audit `6011317053` (BLOQUEO 2): el ÚNICO entorno
     * operativo donde un lote REAL puede ejecutarse.
     *
     * EL DEFECTO QUE CIERRA, aceptado sin reservas: la lista anterior era
     * `['local', 'testing', 'staging']`, y presentar esos tres como objetivos operativos equivalentes
     * era más amplio de lo que el dueño autorizó. La prueba está en los propios artefactos de la ronda
     * 1: se generaron con `generated_in_environment = local` y leyeron la cola real de 12 filas, así
     * que **el `APP_ENV=local` de esta estación está conectado al dataset compartido REAL**. Con la
     * lista vieja, `--execute --expect-environment=local` habría podido ejecutar datos reales desde una
     * máquina de desarrollo.
     *
     * `local` sigue siendo plenamente capaz de PREVIEW y de generar manifiestos - las dos cosas son de
     * solo lectura y es donde tienen sentido -, pero ya no es capaz de EJECUTAR.
     *
     * El valor no lo provee quien llama: se auto-captura con `app()->environment()`, igual que
     * `$targetEnvironment` desde TASK-0004, así que un operador no puede "declarar" que está en
     * staging.
     */
    public const BATCH_EXECUTABLE_ENVIRONMENTS = ['staging'];

    /**
     * Excepción para TESTS AUTOMATIZADOS, deliberadamente separada de la lista operativa en vez de
     * mezclada con ella.
     *
     * No es un cuarto objetivo de ejecución: es el entorno en el que corre PHPUnit, donde cada lote de
     * fixture vive dentro de una transacción que **nunca commitea** (`DatabaseTransactions`). Tenerla
     * como constante aparte es lo que hace que leer el código no sugiera que `testing` y `staging` son
     * lo mismo. El CLI NO acepta esta excepción -ver `ApplyTaxonomyReviewedProposalBatch`-: una persona
     * corriendo el comando en `testing` no es un test de fixture.
     */
    public const BATCH_FIXTURE_TEST_ENVIRONMENT = 'testing';

    /**
     * ¿Este entorno puede ejecutar un lote? Un solo predicado, para que la regla no quede repetida en
     * el servicio y en el comando con la posibilidad de divergir.
     *
     * `production` devuelve `false` por no estar en ninguna de las dos, que es lo correcto: la
     * prohibición no depende de una lista negra que alguien pueda olvidar de actualizar, sino de que
     * sólo lo explícitamente permitido pasa.
     */
    public static function environmentCanExecuteBatch(string $environment): bool
    {
        return in_array($environment, self::BATCH_EXECUTABLE_ENVIRONMENTS, true)
            || $environment === self::BATCH_FIXTURE_TEST_ENVIRONMENT;
    }

    /**
     * TASK-0004, re-audit HIGH-2 (Issue #2 comentario `5890113782`): bandera de contexto que SOLO
     * `apply()` enciende, alrededor de las dos únicas escrituras que constituyen "publicación" real
     * (`taxonomy_candidate_concept_links.status -> published`,
     * `taxonomy_concept_relations.status -> approved`). Los guards de
     * `TaxonomyCandidateConceptLink::booted()`/`TaxonomyConceptRelation::booted()` exigen que esta
     * bandera esté encendida para permitir esas transiciones específicas - así que
     * `CandidateConceptApprovalService`/una edición directa de Filament que intente esa MISMA
     * transición por fuera de `apply()` la ve apagada y aborta con excepción (y la transacción que
     * la contiene revierte todo, incluida cualquier escritura previa en la misma transacción - ver
     * `docs/orquestador/tasks/0004-phase-c2-immutable-apply.md`, hallazgo HIGH-2). No es
     * thread-local (PHP-FPM es single-threaded por request) - correcto para este propósito.
     */
    private static bool $applyingC2Publication = false;

    public static function isApplyingC2Publication(): bool
    {
        return self::$applyingC2Publication;
    }

    /**
     * Enciende la bandera de contexto alrededor de `$callback` y la apaga siempre al salir (incluso
     * si `$callback` lanza). Único punto donde `$applyingC2Publication` se manipula - tanto
     * `writeCandidateLinkDecision()`/`writeConceptRelationDecision()` (uso real) como los tests que
     * necesitan aislar OTRO guard distinto del de bypass (ej. probar que el guard semántico de
     * `TaxonomyConceptRelation::booted()` sigue funcionando de forma independiente) pasan por acá -
     * nunca escriben la propiedad privada directamente. Público a propósito para que
     * `ReviewedProposalServiceTest`/`TaxonomyConceptRelationValidationTest` puedan aislar el guard
     * semántico de `TaxonomyConceptRelation::booted()` de este guard de bypass en sus propios tests
     * dirigidos - no es una puerta de escape de producción (nada fuera de tests reales la usa para
     * publicar de verdad; sigue siendo SOLO un flag de contexto, no autorización).
     */
    public static function withC2PublicationContext(\Closure $callback): mixed
    {
        self::$applyingC2Publication = true;
        try {
            return $callback();
        } finally {
            self::$applyingC2Publication = false;
        }
    }

    // =====================================================================================
    // PASO 1: FREEZE - congela una decisión de revisión humana ya tomada. Nunca publica nada.
    // =====================================================================================

    /**
     * @param  string  $proposalType  TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK|TYPE_CONCEPT_RELATION
     * @param  int  $targetId  id del candidato (TERM_CONCEPT_LINK) o de la relación (CONCEPT_RELATION)
     * @param  string  $decision  DECISION_MAP_TO_EXISTING|DECISION_CREATE_NEW|DECISION_REJECT|DECISION_PUBLISH_RELATION
     * @param  array  $decisionPayload  Campos explícitos de la decisión (ej. `target_concept_id` para
     *                MAP_TO_EXISTING, `new_concept_name` para CREATE_NEW). Nunca se infiere un valor
     *                que no esté explícito acá - "no invent capabilities or mappings beyond the
     *                reviewed payload" (hallazgo 5 de TASK-0004).
     * @param  string|null  $preparedByActorType  TASK-0006B (Issue #2 comentario `5936206843`),
     *                sección A: quién redactó el CONTENIDO de la decisión, que no es necesariamente
     *                la persona que queda como `reviewer_id`. Por defecto `null` =
     *                `ACTOR_HUMAN_REVIEWER` (el revisor decidió él mismo) - que es el caso de la UI
     *                de Filament y de todo lo histórico, así que el comportamiento existente no
     *                cambia. Pasar `ACTOR_AGENT` marca la propuesta con
     *                `requires_human_confirmation = true`, y entonces `apply()` la rechaza hasta que
     *                un humano la confirme vía `confirm()`. Es una DECLARACIÓN del llamador, no una
     *                deducción: el canal real (`prepared_via`) se auto-captura aparte y no se puede
     *                falsear, así que un auditor puede detectar una fila congelada por consola que
     *                no se declaró como preparada por agente.
     * @return array{result:string, proposal:?TaxonomyReviewedProposal}
     */
    public function freeze(
        string $proposalType,
        int $targetId,
        string $decision,
        ?User $reviewer,
        array $decisionPayload = [],
        ?string $preparedByActorType = null,
    ): array {
        return match ($proposalType) {
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK => $this->freezeCandidateLink($targetId, $decision, $reviewer, $decisionPayload, $preparedByActorType),
            TaxonomyReviewedProposal::TYPE_CONCEPT_RELATION => $this->freezeConceptRelation($targetId, $decision, $reviewer, $decisionPayload, $preparedByActorType),
            default => throw new \InvalidArgumentException("proposal_type desconocido: {$proposalType}"),
        };
    }

    private function freezeCandidateLink(int $candidateId, string $decision, ?User $reviewer, array $decisionPayload, ?string $preparedByActorType = null): array
    {
        if (! in_array($decision, [
            TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING,
            TaxonomyReviewedProposal::DECISION_CREATE_NEW,
            TaxonomyReviewedProposal::DECISION_REJECT,
            TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED,
        ], true)) {
            throw new \InvalidArgumentException("Decisión no soportada para TERM_CONCEPT_LINK: {$decision}");
        }

        return DB::connection('pgsql')->transaction(function () use ($candidateId, $decision, $reviewer, $decisionPayload, $preparedByActorType) {
            $candidate = TaxonomyCandidateConceptLink::query()->lockForUpdate()->find($candidateId);

            if (! $candidate) {
                return ['result' => self::RESULT_NOT_FOUND, 'proposal' => null];
            }

            if (! $reviewer || ! $reviewer->can('update', $candidate)) {
                return ['result' => self::RESULT_UNAUTHORIZED, 'proposal' => null];
            }

            if ($candidate->status !== TaxonomyCandidateConceptLink::STATUS_PENDING) {
                return ['result' => self::RESULT_ALREADY_PROCESSED, 'proposal' => null];
            }

            if ($decision === TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING) {
                $targetConceptId = $decisionPayload['target_concept_id'] ?? null;
                if (! $targetConceptId || ! TaxonomyCanonicalConcept::query()->whereKey($targetConceptId)->exists()) {
                    return ['result' => self::RESULT_NOT_FOUND, 'proposal' => null];
                }
            }

            if ($decision === TaxonomyReviewedProposal::DECISION_CREATE_NEW && ! $candidate->isProposingNewConcept()) {
                // CREATE_NEW solo tiene sentido si el candidato mismo es una propuesta de concepto
                // nuevo (suggested_concept_id NULL) - un candidato con concepto sugerido ya
                // existente se resuelve con MAP_TO_EXISTING (aceptando o redirigiendo ese
                // concepto), nunca creando uno paralelo.
                throw new \InvalidArgumentException('CREATE_NEW solo aplica a candidatos que proponen un concepto nuevo (suggested_concept_id NULL).');
            }

            // TASK-0004, re-audit correction A (Issue #2 comentario `5892711739`): CREATE_NEW exige
            // el nombre revisado EXPLÍCITO en el payload de decisión - "no mutable fallback" (ya
            // corregido en apply() desde el re-audit anterior, ahora también en freeze()). Si el
            // payload no trae `new_concept_name` no vacío, freeze() rechaza sin congelar nada; NUNCA
            // lo completa con `suggested_new_concept_name` (que queda disponible solo como sugerencia
            // para que la UI la muestre, no como fuente de verdad implícita).
            // TASK-0006B (Issue #2 comentario `5936206843`), sección C: CREATE_NEW acepta ahora DOS
            // formas de identidad, nunca mezcladas y nunca inferidas una de la otra:
            //
            // - MONOLINGÜE (histórica, sin cambios): `new_concept_name` explícito. `apply()` lo
            //   publica en el campo del idioma del término, igual que antes de esta tarea.
            // - BILINGÜE (nueva): `canonical_name_es` Y `canonical_name_en`, las DOS explícitas. Sin
            //   traducción implícita, sin fallback de una a la otra, sin derivar ninguna del nombre
            //   sugerido por el Builder. Esto cierra el defecto que el re-audit `5934324928`
            //   (BLOQUEO 2) señaló: con una sola columna de nombre, congelar `CREATE_NEW` sobre un
            //   término `es` dejaba `canonical_name_es` con la palabra INGLESA.
            //
            // El nombre sugerido por el Builder se conserva aparte como EVIDENCIA
            // (`source_suggested_new_concept_name`), nunca como fuente de la identidad publicada -
            // misma separación de roles que la ronda 4 de TASK-0004 introdujo.
            $explicitNewConceptName = null;
            $bilingualNames = null;
            if ($decision === TaxonomyReviewedProposal::DECISION_CREATE_NEW) {
                $bilingualNames = self::validatedBilingualNames($decisionPayload);

                if ($bilingualNames === null) {
                    $explicitNewConceptName = trim((string) ($decisionPayload['new_concept_name'] ?? ''));
                    if ($explicitNewConceptName === '') {
                        return ['result' => self::RESULT_VALIDATION_FAILED, 'proposal' => null];
                    }
                } elseif ($bilingualNames === false) {
                    // Identidad bilingüe presente pero incompleta/inválida (una de las dos vacía, o
                    // más larga que la columna). No se completa la que falta por ningún camino.
                    return ['result' => self::RESULT_VALIDATION_FAILED, 'proposal' => null];
                }
            }

            // TASK-0004, re-audit correction C (Issue #2 comentario `5892711739`): CONTEXT_REQUIRED
            // (término/candidato válido pero insuficientemente específico para un mapeo directo)
            // exige igualmente un motivo explícito no vacío - mismo criterio que `new_concept_name`
            // arriba, nunca inferido.
            $explicitContextReason = null;
            if ($decision === TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED) {
                $explicitContextReason = trim((string) ($decisionPayload['context_reason'] ?? ''));
                if ($explicitContextReason === '') {
                    return ['result' => self::RESULT_VALIDATION_FAILED, 'proposal' => null];
                }
            }

            // TASK-0004, re-audit HIGH-1: congela TODOS los campos fuente decision-relevantes DENTRO
            // del payload, leídos UNA sola vez acá (bajo el lock, en el instante de la revisión) -
            // `apply()` nunca vuelve a leer `suggested_term_id`/`suggested_new_concept_name` de la
            // fila viva para decidir QUÉ escribir, solo para revalidar que no cambiaron (ver
            // `evaluateCandidateLink`).
            $snapshot = $decisionPayload;
            $snapshot['term_id'] = $candidate->suggested_term_id;
            if ($decision === TaxonomyReviewedProposal::DECISION_CREATE_NEW) {
                // TASK-0004, re-audit ronda 4 (Issue #2 comentario `5909267134`, defecto 1): DOS
                // valores distintos, nunca uno solo - `new_concept_name` es el valor REVISADO/elegido
                // por el humano (lo que `apply()` efectivamente publica), `source_suggested_new_concept_name`
                // es la SUGERENCIA del Builder en el instante de freeze() (usada EXCLUSIVAMENTE para
                // detectar drift de la fila fuente, nunca para publicar). Antes de esta corrección,
                // `apply()` comparaba el valor revisado contra la sugerencia viva - eso hacía
                // imposible que un revisor corrigiera/normalizara el nombre sugerido: si el Builder
                // sugirió "X" y el humano aprobó explícitamente "Y", drift-detection abortaba
                // incorrectamente comparando "Y" contra "X" como si "X" hubiera cambiado.
                $snapshot['source_suggested_new_concept_name'] = $candidate->suggested_new_concept_name;

                if (is_array($bilingualNames)) {
                    // Las dos nombres explícitos entran al payload congelado y por lo tanto al
                    // `payload_fingerprint` (se computa sobre `decision_payload` completo), así que
                    // manipular CUALQUIERA de las dos después de congelar rompe la tamper-detection -
                    // requisito explícito de la sección C ("payload fingerprint covers both names").
                    $snapshot['canonical_name_es'] = $bilingualNames['canonical_name_es'];
                    $snapshot['canonical_name_en'] = $bilingualNames['canonical_name_en'];
                    unset($snapshot['new_concept_name']);
                } else {
                    $snapshot['new_concept_name'] = $explicitNewConceptName;
                }
            }
            if ($decision === TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED) {
                $snapshot['context_reason'] = $explicitContextReason;
            }

            return $this->insertFrozenProposal(
                proposalType: TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
                candidateLinkId: $candidateId,
                conceptRelationId: null,
                decision: $decision,
                reviewer: $reviewer,
                decisionPayload: $snapshot,
                preparedByActorType: $preparedByActorType,
            );
        });
    }

    /**
     * TASK-0006B (Issue #2 comentario `5936206843`), sección C. Devuelve:
     * - `null` si el payload no intenta ser bilingüe (ninguna de las dos claves presente) -> el
     *   llamador sigue el camino monolingüe histórico, intacto;
     * - `false` si lo intenta pero es inválido (alguna vacía/blanca, o excede el largo de columna);
     * - el array con las dos nombres recortados si es válido.
     *
     * Presencia PARCIAL cuenta como intento bilingüe inválido a propósito: completar la que falta
     * sería exactamente el fallback implícito que la corrección A de TASK-0004 prohibió, y traducir
     * automáticamente sería inventar identidad que ningún humano revisó.
     */
    private static function validatedBilingualNames(array $decisionPayload): array|false|null
    {
        $hasEs = array_key_exists('canonical_name_es', $decisionPayload);
        $hasEn = array_key_exists('canonical_name_en', $decisionPayload);

        if (! $hasEs && ! $hasEn) {
            return null;
        }

        $es = trim((string) ($decisionPayload['canonical_name_es'] ?? ''));
        $en = trim((string) ($decisionPayload['canonical_name_en'] ?? ''));

        if ($es === '' || $en === '') {
            return false;
        }

        // `taxonomy_canonical_concepts.canonical_name_es/en` son VARCHAR(255) - validar acá evita
        // congelar una identidad que `apply()` no podría escribir después.
        if (mb_strlen($es) > 255 || mb_strlen($en) > 255) {
            return false;
        }

        return ['canonical_name_es' => $es, 'canonical_name_en' => $en];
    }

    private function freezeConceptRelation(int $relationId, string $decision, ?User $reviewer, array $decisionPayload, ?string $preparedByActorType = null): array
    {
        if (! in_array($decision, [
            TaxonomyReviewedProposal::DECISION_PUBLISH_RELATION,
            TaxonomyReviewedProposal::DECISION_REJECT,
        ], true)) {
            throw new \InvalidArgumentException("Decisión no soportada para CONCEPT_RELATION: {$decision}");
        }

        return DB::connection('pgsql')->transaction(function () use ($relationId, $decision, $reviewer, $decisionPayload, $preparedByActorType) {
            $relation = TaxonomyConceptRelation::query()->lockForUpdate()->find($relationId);

            if (! $relation) {
                return ['result' => self::RESULT_NOT_FOUND, 'proposal' => null];
            }

            if (! $reviewer || ! $reviewer->can('update', $relation)) {
                return ['result' => self::RESULT_UNAUTHORIZED, 'proposal' => null];
            }

            if ($relation->status !== TaxonomyConceptRelation::STATUS_CANDIDATE) {
                return ['result' => self::RESULT_ALREADY_PROCESSED, 'proposal' => null];
            }

            // TASK-0004, re-audit HIGH-1: mismo criterio que freezeCandidateLink() - congela los
            // campos fuente decision-relevantes de la relación (source/target/relation_type) DENTRO
            // del payload, leídos una sola vez acá bajo el lock. `apply()` nunca redescubre estos
            // valores de la fila viva para decidir qué publicar.
            $snapshot = $decisionPayload;
            $snapshot['source_concept_id'] = $relation->source_concept_id;
            $snapshot['target_concept_id'] = $relation->target_concept_id;
            $snapshot['relation_type'] = $relation->relation_type;

            return $this->insertFrozenProposal(
                proposalType: TaxonomyReviewedProposal::TYPE_CONCEPT_RELATION,
                candidateLinkId: null,
                conceptRelationId: $relationId,
                decision: $decision,
                reviewer: $reviewer,
                decisionPayload: $snapshot,
                preparedByActorType: $preparedByActorType,
            );
        });
    }

    /**
     * TASK-0006B (Issue #2 comentario `5936206843`), sección D: congela UNA revisión gobernada que
     * hace converger VARIOS candidatos bilingües en UN SOLO concepto canónico futuro, con identidad
     * ES/EN explícita.
     *
     * Por qué varias filas y no una: `candidate_link_id` es una sola columna, con un CHECK XOR y un
     * índice único parcial `WHERE status = 'PENDING_APPLY'`. Si una única fila representara el par,
     * el segundo candidato quedaría FUERA de ese índice y nada impediría congelarle otra propuesta
     * en paralelo - la carrera de concepto duplicado que esta sección prohíbe explícitamente. Con
     * una fila por candidato unidas por `proposal_group_id`, el índice que ya existe protege a
     * TODOS los miembros sin agregar índices ni tablas, y cada fila conserva su propio
     * `payload_fingerprint` (tamper por fila) y su propio snapshot de drift de fuente.
     *
     * Las filas se insertan en UNA transacción, con el MISMO `taxonomy_state_fingerprint` y el mismo
     * `reviewed_at`: así `apply()` no puede abortar el grupo por obsolescencia solo porque los
     * miembros se congelaron a segundos de distancia. Si cualquier miembro falla, la transacción
     * revierte COMPLETA - nunca queda medio grupo congelado.
     *
     * Ningún APPLY es necesario para que la segunda revisión sea expresable (requisito explícito de
     * la sección D): las dos decisiones se congelan a la vez, antes de que el concepto exista.
     *
     * @param  int[]  $candidateIds  Candidatos que participan (>= 2, distintos)
     * @return array{result:string, proposals:array<TaxonomyReviewedProposal>, group_id:?string}
     */
    public function freezeBilingualConceptGroup(
        array $candidateIds,
        string $canonicalNameEs,
        string $canonicalNameEn,
        ?User $reviewer,
        array $decisionPayload = [],
        ?string $preparedByActorType = null,
    ): array {
        $candidateIds = array_values(array_unique(array_map('intval', $candidateIds)));
        sort($candidateIds);

        if (count($candidateIds) < 2) {
            throw new \InvalidArgumentException('freezeBilingualConceptGroup() exige al menos 2 candidatos distintos - para un solo candidato con identidad ES/EN alcanza freeze() con CREATE_NEW bilingüe.');
        }

        $names = self::validatedBilingualNames([
            'canonical_name_es' => $canonicalNameEs,
            'canonical_name_en' => $canonicalNameEn,
        ]);

        if (! is_array($names)) {
            return ['result' => self::RESULT_VALIDATION_FAILED, 'proposals' => [], 'group_id' => null];
        }

        try {
            return DB::connection('pgsql')->transaction(function () use ($candidateIds, $names, $reviewer, $decisionPayload, $preparedByActorType) {
                // 1) Carga y valida TODOS los miembros ANTES de insertar nada. Orden determinístico
                // por id para que dos transacciones concurrentes tomen los locks en el mismo orden.
                $candidates = [];
                $languages = [];
                foreach ($candidateIds as $candidateId) {
                    $candidate = TaxonomyCandidateConceptLink::query()->lockForUpdate()->find($candidateId);

                    if (! $candidate) {
                        return ['result' => self::RESULT_NOT_FOUND, 'proposals' => [], 'group_id' => null];
                    }

                    if (! $reviewer || ! $reviewer->can('update', $candidate)) {
                        return ['result' => self::RESULT_UNAUTHORIZED, 'proposals' => [], 'group_id' => null];
                    }

                    if ($candidate->status !== TaxonomyCandidateConceptLink::STATUS_PENDING) {
                        return ['result' => self::RESULT_ALREADY_PROCESSED, 'proposals' => [], 'group_id' => null];
                    }

                    // Mismo criterio que freeze()/CREATE_NEW: converger en un concepto NUEVO solo
                    // tiene sentido si el candidato propone un concepto nuevo. Un candidato con
                    // concepto existente sugerido se resuelve con MAP_TO_EXISTING.
                    if (! $candidate->isProposingNewConcept()) {
                        return ['result' => self::RESULT_VALIDATION_FAILED, 'proposals' => [], 'group_id' => null];
                    }

                    $candidates[$candidateId] = $candidate;
                    $languages[$candidateId] = $candidate->term?->language;
                }

                // 2) Validación real de identidad bilingüe: el grupo tiene que ABARCAR los dos
                // idiomas. Sin esto, el camino bilingüe podría usarse sobre dos términos ingleses y
                // el nombre español quedaría siendo una decisión sin ninguna fuente que la respalde -
                // una variante del mismo defecto que la sección C vino a cerrar. No se puede validar
                // que una traducción sea CORRECTA (eso es juicio humano, y la UI muestra el idioma de
                // cada término al lado de cada campo), pero sí que el par sea genuinamente bilingüe.
                $distinctLanguages = array_values(array_unique(array_filter($languages)));
                if (! in_array('es', $distinctLanguages, true) || ! in_array('en', $distinctLanguages, true)) {
                    return ['result' => self::RESULT_VALIDATION_FAILED, 'proposals' => [], 'group_id' => null];
                }

                // 3) Un solo fingerprint de estado y un solo `reviewed_at` para todo el grupo.
                $groupId = (string) \Illuminate\Support\Str::uuid();
                $taxonomyStateFingerprint = CanonicalConceptBuilderService::dryRunInputFingerprint();
                $reviewedAt = now();

                $groupedTerms = [];
                $groupedSuggestedNames = [];
                foreach ($candidates as $candidateId => $candidate) {
                    $groupedTerms[(string) $candidateId] = $candidate->suggested_term_id;
                    $groupedSuggestedNames[(string) $candidateId] = $candidate->suggested_new_concept_name;
                }

                $proposals = [];
                foreach ($candidates as $candidateId => $candidate) {
                    // El payload de CADA miembro identifica el grupo COMPLETO (todos los candidatos y
                    // términos participantes) - requisito explícito de la sección D: "the immutable
                    // review state identifies all governed source candidates/terms participating".
                    // Así cualquier miembro, por sí solo, describe la convergencia entera.
                    $snapshot = $decisionPayload;
                    $snapshot['term_id'] = $candidate->suggested_term_id;
                    $snapshot['source_suggested_new_concept_name'] = $candidate->suggested_new_concept_name;
                    $snapshot['canonical_name_es'] = $names['canonical_name_es'];
                    $snapshot['canonical_name_en'] = $names['canonical_name_en'];
                    $snapshot['bilingual_group'] = true;
                    $snapshot['grouped_candidate_link_ids'] = $candidateIds;
                    $snapshot['grouped_term_ids'] = $groupedTerms;
                    $snapshot['grouped_source_suggested_names'] = $groupedSuggestedNames;
                    $snapshot['grouped_term_languages'] = array_map(fn ($l) => $l, $languages);

                    $outcome = $this->insertFrozenProposal(
                        proposalType: TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
                        candidateLinkId: $candidateId,
                        conceptRelationId: null,
                        decision: TaxonomyReviewedProposal::DECISION_CREATE_NEW,
                        reviewer: $reviewer,
                        decisionPayload: $snapshot,
                        preparedByActorType: $preparedByActorType,
                        proposalGroupId: $groupId,
                        taxonomyStateFingerprint: $taxonomyStateFingerprint,
                        reviewedAt: $reviewedAt,
                    );

                    if ($outcome['result'] !== self::RESULT_FROZEN) {
                        // Revierte el grupo COMPLETO. Lanzar es la única forma de abortar una
                        // `DB::transaction()` (un `return` normal commitea), y dejar medio grupo
                        // congelado sería peor que no congelar nada: el hermano sin propuesta podría
                        // recibir después un CREATE_NEW independiente y crear el concepto duplicado.
                        throw new \RuntimeException(self::GROUP_ROLLBACK_SENTINEL.$outcome['result']);
                    }

                    $proposals[] = $outcome['proposal'];
                }

                return ['result' => self::RESULT_FROZEN, 'proposals' => $proposals, 'group_id' => $groupId];
            });
        } catch (\RuntimeException $e) {
            if (! str_starts_with($e->getMessage(), self::GROUP_ROLLBACK_SENTINEL)) {
                throw $e;
            }

            return [
                'result' => substr($e->getMessage(), strlen(self::GROUP_ROLLBACK_SENTINEL)),
                'proposals' => [],
                'group_id' => null,
            ];
        }
    }

    private const GROUP_ROLLBACK_SENTINEL = 'BILINGUAL_GROUP_ROLLBACK:';

    /**
     * Prefijo de namespace del espacio de advisory locks. Va dentro del hash para que esta clave no
     * pueda colisionar por accidente con un advisory lock que use otra parte del sistema.
     */
    private const GROUP_LOCK_NAMESPACE = 'taxonomy_reviewed_proposal_group:';

    /**
     * TASK-0006B re-audit (Issue #2 comentario `5938949812`, «OTHER REVIEW NOTE — GROUP
     * CONCURRENCY»): clave determinística de advisory lock para un grupo bilingüe.
     *
     * El problema que resuelve: `apply()` bloqueaba PRIMERO la fila de entrada y DESPUÉS todas las
     * filas del grupo. Dos `apply()` concurrentes que entran por hermanos distintos del mismo grupo
     * tomaban locks de primera fila OPUESTOS antes de pedir el grupo completo, y quedaban en espera
     * circular -> deadlock de PostgreSQL. Postgres lo detecta y revierte una de las dos, así que
     * nunca se publicaba de más (la garantía de "cero duplicados" se mantenía), pero "una de las dos
     * peticiones muere con un error de deadlock" es más débil que el contrato de concurrencia que
     * TASK-0006B pedía.
     *
     * La corrección: el PRIMER lock que toma cualquier transacción que va a aplicar una propuesta
     * agrupada es este advisory lock, derivado del `proposal_group_id` y por lo tanto IDÉNTICO para
     * todos los hermanos. Con eso desaparece la espera circular por construcción: dos hermanos ya no
     * compiten por filas distintas, se serializan acá antes de tocar una sola fila. El segundo en
     * entrar encuentra el grupo ya aplicado y devuelve `ALREADY_APPLIED`.
     *
     * Por qué la clave se deriva en PHP y no con `hashtextextended()`: así es estable y verificable
     * sin depender de la implementación de hash de una versión concreta de Postgres, y se puede
     * probar por test que dos hermanos producen exactamente el mismo número. Se usan 15 dígitos
     * hexadecimales (60 bits) para que el valor entre siempre en un `bigint` con signo sin desbordar.
     * Una colisión (probabilidad ~2^-60) solo haría que dos grupos NO relacionados se serialicen
     * entre sí: más lento en un caso imposible en la práctica, nunca incorrecto.
     */
    public static function groupAdvisoryLockKey(string $proposalGroupId): int
    {
        return (int) hexdec(substr(hash('sha256', self::GROUP_LOCK_NAMESPACE.$proposalGroupId), 0, 15));
    }

    /**
     * Toma el advisory lock del grupo a NIVEL DE TRANSACCIÓN. `pg_advisory_xact_lock` se libera
     * automáticamente al terminar la transacción de nivel superior (commit o rollback), así que no
     * hay forma de olvidarse de liberarlo ni de filtrarlo si algo lanza - a diferencia de un
     * advisory lock de sesión. Es re-entrante dentro de la misma transacción: llamarlo dos veces
     * (ver `apply()` y `evaluateBilingualGroup()`) es inofensivo.
     *
     * Bloqueante a propósito, no `try`: el segundo hermano DEBE esperar y recién entonces observar
     * el estado ya aplicado. Un `pg_try_advisory_xact_lock` que devolviera false obligaría a inventar
     * un resultado "ocupado, reintentá" que no existe en el contrato de `apply()`.
     */
    private static function acquireGroupAdvisoryLock(string $proposalGroupId): void
    {
        DB::connection('pgsql')->statement(
            'SELECT pg_advisory_xact_lock(?)',
            [self::groupAdvisoryLockKey($proposalGroupId)],
        );
    }

    /**
     * TASK-0007 (Issue #2 comentario `5997693379`), PARTE 5: namespace del advisory lock COMÚN de
     * EJECUCIÓN C2. Deliberadamente distinto del namespace de grupo, así que las dos claves no pueden
     * colisionar y el orden `ejecución -> grupo` es siempre el mismo par de locks distintos.
     */
    private const EXECUTION_LOCK_NAMESPACE = 'taxonomy_c2_execution:';

    /**
     * Identificador fijo del único lock de ejecución C2. No se parametriza a propósito: un lock por
     * "cosa que se ejecuta" no serializaría nada - el punto es que CUALQUIER ejecución C2 (un lote o
     * un apply suelto) espere a cualquier otra.
     */
    private const EXECUTION_LOCK_SCOPE = 'reviewed-proposal-apply';

    /**
     * TASK-0007, PARTE 5: clave determinística del advisory lock COMÚN de ejecución C2.
     *
     * EL PROBLEMA QUE RESUELVE, que es el que abre TASK-0007: un `apply()` suelto concurrente puede
     * cambiar el grafo ENTRE la validación del lote y su fase de escritura. El lote valida las 12
     * propuestas contra UN baseline y recién después escribe; si en esa ventana otro `apply()`
     * publica un `taxonomy_term_concepts`, el baseline con el que el lote validó ya no describe el
     * estado real en el momento de escribir. Los locks de fila no alcanzan: el apply concurrente
     * puede entrar por una propuesta que el lote NO pidió y aun así mover el grafo global.
     *
     * La corrección es un lock común tomado por los DOS caminos como PRIMERA acción de su
     * transacción, antes de cualquier advisory lock de grupo y antes de cualquier `lockForUpdate()`.
     * Con eso la exclusión es total: mientras un lote está entre su validación y su commit, ningún
     * apply suelto puede siquiera empezar.
     *
     * POR QUÉ ESTE ORDEN Y NO OTRO (y por qué no se invierte el deadlock del grupo): el lock de
     * ejecución es ÚNICO y global al servicio, así que es imposible que dos transacciones lo tomen
     * "cruzado". Tomándolo primero, cualquier par de transacciones que después compita por locks de
     * grupo o de fila ya está serializado, de modo que la corrección de TASK-0006B (clave común por
     * grupo antes de los locks de fila) no se debilita: se le agrega un lock ESTRICTAMENTE anterior y
     * común a todos, que es el caso favorable para evitar inversión de orden.
     *
     * NO es un lock de mantenimiento de aplicación: cubre exclusivamente la ejecución de propuestas
     * revisadas C2 (`apply()` y `applyBatch()`). `freeze()`, `confirm()`, `preflight()`,
     * `previewBatch()` y `supersedeStaleProposal()` NO lo toman - los dos primeros no publican
     * taxonomía, los dos siguientes no escriben nada, y la supersesión sólo escribe `status` + rastro
     * de filas `PENDING_APPLY` que ya protege con `lockForUpdate()` (un lote que las esté validando
     * las tiene bloqueadas, así que la supersesión espera por la fila, no por el lock global).
     * Ampliarlo más haría que una revisión humana normal pudiera quedar esperando una ejecución.
     */
    public static function executionAdvisoryLockKey(): int
    {
        return (int) hexdec(substr(hash('sha256', self::EXECUTION_LOCK_NAMESPACE.self::EXECUTION_LOCK_SCOPE), 0, 15));
    }

    /**
     * Toma el lock común de ejecución a NIVEL DE TRANSACCIÓN: se libera solo al terminar la
     * transacción de nivel superior, igual que el de grupo, y es re-entrante dentro de ella.
     * Bloqueante y no `try`, por el mismo motivo que el de grupo: el segundo en entrar DEBE esperar y
     * recién entonces observar el estado ya ejecutado, en vez de recibir un "ocupado" que no existe
     * en el contrato de `apply()`.
     */
    private static function acquireExecutionAdvisoryLock(): void
    {
        DB::connection('pgsql')->statement(
            'SELECT pg_advisory_xact_lock(?)',
            [self::executionAdvisoryLockKey()],
        );
    }

    /**
     * Diagnóstico de solo lectura, mismo propósito que `holdsGroupAdvisoryLock()`: que un test pueda
     * comprobar en `pg_locks` que el camino REALMENTE tomó el lock, en vez de confiar en el código.
     */
    public static function holdsExecutionAdvisoryLock(): bool
    {
        return self::holdsAdvisoryLockKey(self::executionAdvisoryLockKey());
    }

    /**
     * Diagnóstico de solo lectura: ¿esta sesión tiene tomado el advisory lock de este grupo? Existe
     * para que un test pueda comprobar que `apply()` realmente lo tomó, en vez de confiar en que el
     * código lo haga. No se usa para decidir nada en producción.
     */
    public static function holdsGroupAdvisoryLock(string $proposalGroupId): bool
    {
        return self::holdsAdvisoryLockKey(self::groupAdvisoryLockKey($proposalGroupId));
    }

    private static function holdsAdvisoryLockKey(int $key): bool
    {
        // Cómo guarda Postgres un advisory lock de UN bigint: `classid` son los 32 bits altos,
        // `objid` los 32 bits bajos y `objsubid = 1` (la forma de dos enteros usa `objsubid = 2`).
        // Se reconstruye el bigint y se filtra por `objsubid = 1` para no confundir las dos formas.
        // Se cuenta en vez de devolver un booleano de Postgres, porque PDO puede entregarlo como
        // `'t'`/`'f'` según configuración y un string `'f'` es truthy en PHP.
        $row = DB::connection('pgsql')->selectOne(
            "SELECT COUNT(*) AS n FROM pg_locks
             WHERE locktype = 'advisory'
               AND pid = pg_backend_pid()
               AND granted
               AND objsubid = 1
               AND ((classid::bigint << 32) | objid::bigint) = ?",
            [$key],
        );

        return ((int) ($row->n ?? 0)) > 0;
    }

    private function insertFrozenProposal(
        string $proposalType,
        ?int $candidateLinkId,
        ?int $conceptRelationId,
        string $decision,
        User $reviewer,
        array $decisionPayload,
        ?string $preparedByActorType = null,
        ?string $proposalGroupId = null,
        ?string $taxonomyStateFingerprint = null,
        ?\DateTimeInterface $reviewedAt = null,
    ): array {
        // TASK-0006B, sección D: un grupo bilingüe congela sus filas en UNA transacción compartiendo
        // el MISMO fingerprint de estado y el MISMO `reviewed_at`. Si cada miembro recalculara el
        // fingerprint por su cuenta, dos miembros congelados a segundos de distancia podrían quedar
        // con fingerprints distintos y `apply()` abortaría el grupo por obsolescencia aunque nada
        // hubiera cambiado realmente.
        $taxonomyStateFingerprint ??= CanonicalConceptBuilderService::dryRunInputFingerprint();
        $reviewedAt = $reviewedAt ? \Illuminate\Support\Carbon::instance(\DateTimeImmutable::createFromInterface($reviewedAt)) : now();

        $fingerprintFields = [
            'proposal_type' => $proposalType,
            'candidate_link_id' => $candidateLinkId,
            'concept_relation_id' => $conceptRelationId,
            'decision' => $decision,
            'decision_payload' => $decisionPayload,
            'payload_version' => self::PAYLOAD_VERSION,
            'taxonomy_state_fingerprint' => $taxonomyStateFingerprint,
            'reviewer_id' => $reviewer->id,
            // Segundos, no microsegundos: el query builder bindea el `DateTime` hacia Postgres con
            // el `$dateFormat` de la grammar (precisión de segundo), así que un `reviewed_at` con
            // microsegundos acá NUNCA coincidiría con lo que se lee de vuelta en apply() - se
            // rompería como tamper detection en TODO payload, no solo en uno manipulado.
            'reviewed_at' => $reviewedAt->format('Y-m-d H:i:s'),
        ];
        $payloadFingerprint = self::computePayloadFingerprint($fingerprintFields);

        // DB-enforced (índice único parcial de la migración), no TOCTOU: si otra transacción ya
        // congeló una propuesta PENDING_APPLY para el mismo candidato/relación entre el
        // lockForUpdate() de arriba y este insert, `insertOrIgnore` devuelve 0 filas afectadas en
        // vez de violar la constraint con una excepción SQL cruda.
        // TASK-0006B, sección A. `prepared_by_actor_type` es una DECLARACIÓN del llamador (por
        // defecto, el revisor decidió él mismo - el caso de la UI y de todo lo histórico).
        // `prepared_via` es el canal REAL, auto-capturado y no falseable, exactamente igual que
        // `target_environment` en `apply()`: así un auditor puede detectar una fila congelada por
        // consola que NO se declaró como preparada por agente, sin confiar en la declaración.
        $preparedBy = $preparedByActorType ?: TaxonomyReviewedProposal::ACTOR_HUMAN_REVIEWER;
        $requiresHumanConfirmation = $preparedBy === TaxonomyReviewedProposal::ACTOR_AGENT;

        $affected = DB::connection('pgsql')->table('taxonomy_reviewed_proposals')->insertOrIgnore([
            'proposal_type' => $proposalType,
            'candidate_link_id' => $candidateLinkId,
            'concept_relation_id' => $conceptRelationId,
            'decision' => $decision,
            'decision_payload' => json_encode($decisionPayload),
            'payload_version' => self::PAYLOAD_VERSION,
            'taxonomy_state_fingerprint' => $taxonomyStateFingerprint,
            'payload_fingerprint' => $payloadFingerprint,
            'reviewer_id' => $reviewer->id,
            'reviewed_at' => $reviewedAt,
            'status' => TaxonomyReviewedProposal::STATUS_PENDING_APPLY,
            'requires_human_confirmation' => $requiresHumanConfirmation,
            'prepared_by_actor_type' => $preparedBy,
            'prepared_via' => self::currentChannel(),
            'proposal_group_id' => $proposalGroupId,
            'created_at' => $reviewedAt,
            'updated_at' => $reviewedAt,
        ]);

        if ($affected === 0) {
            return ['result' => self::RESULT_ALREADY_HAS_PENDING_PROPOSAL, 'proposal' => null];
        }

        $proposal = TaxonomyReviewedProposal::query()
            ->when($candidateLinkId !== null, fn ($q) => $q->where('candidate_link_id', $candidateLinkId), fn ($q) => $q->whereNull('candidate_link_id'))
            ->when($conceptRelationId !== null, fn ($q) => $q->where('concept_relation_id', $conceptRelationId), fn ($q) => $q->whereNull('concept_relation_id'))
            ->where('status', TaxonomyReviewedProposal::STATUS_PENDING_APPLY)
            ->latest('id')
            ->firstOrFail();

        // Auditoría de REVISIÓN: SIN authorization_reference/target_environment (esos solo existen
        // tras un apply() real) - es lo que distingue en `taxonomy_audit_log` una decisión revisada
        // de una decisión ejecutada, sin necesidad de leer ninguna otra tabla.
        TaxonomyAuditLogger::record(
            entityType: TaxonomyReviewedProposal::class,
            entityId: $proposal->id,
            field: 'status',
            oldValue: null,
            newValue: TaxonomyReviewedProposal::STATUS_PENDING_APPLY,
            reason: "Decisión de revisión congelada: {$decision}"
                .($candidateLinkId ? " (candidate_link_id={$candidateLinkId})" : " (concept_relation_id={$conceptRelationId})")
                // TASK-0006B, sección A: la bitácora distingue PREPARED/FROZEN de HUMAN_CONFIRMED
                // (ver `confirm()`), así que leyendo solo `taxonomy_audit_log` se puede reconstruir
                // quién redactó la decisión y quién la confirmó, sin inferirlo de texto libre.
                .($requiresHumanConfirmation ? ' [PREPARED_BY_AGENT - pendiente de CONFIRMACION HUMANA antes de poder aplicarse]' : '')
                .($proposalGroupId ? " [grupo bilingue {$proposalGroupId}]" : ''),
            actorType: TaxonomyAuditLogger::ACTOR_USER,
            algorithmVersion: self::PAYLOAD_VERSION,
        );

        return ['result' => self::RESULT_FROZEN, 'proposal' => $proposal];
    }

    /**
     * Canal de ejecución real, auto-capturado y nunca provisto por quien llama - mismo criterio que
     * `target_environment` en `apply()`.
     *
     * TASK-0006C (Issue #2 comentarios `5939882569` punto 7 y `5939903005` punto 6): el
     * discriminante es **si hay una RUTA RESUELTA**, es decir si esto se está ejecutando mientras se
     * sirve una petición HTTP enrutada. No se usa `runningInConsole()`, y la razón es concreta y
     * verificada empíricamente, no una preferencia de estilo: `runningInConsole()` responde por el
     * SAPI del proceso, así que bajo PHPUnit devuelve `true` incluso cuando la petición SÍ pasó por
     * el router (medido: en un test HTTP y en un test de Livewire da `true`, mientras que
     * `request()->route()` devuelve la ruta real). Usarlo habría rechazado el camino legítimo de la
     * UI y aceptado... nada mejor.
     *
     * Con esta regla:
     * - petición web real (incluida la UI de Filament/Livewire) -> hay ruta -> `http`;
     * - script de consola, `tinker`, comando de artisan, job en cola -> no hay ruta -> `console`.
     *
     * Es exactamente la distinción que hace falta: "¿se invocó esto sirviendo una petición
     * enrutada?".
     *
     * LÍMITE, dicho explícitamente en vez de dejarlo implícito: lo que se mide es si el contenedor
     * tiene una petición con ruta resuelta. En este despliegue eso equivale a "se está sirviendo una
     * petición HTTP", porque corre sobre PHP-FPM, donde cada petición vive en su propio ciclo de
     * proceso y el contenedor se destruye al terminar (verificado: no hay Octane, no hay
     * `config/octane.php`, el workflow de despliegue sirve con PHP-FPM). En un servidor de proceso
     * largo (Octane/Swoole/RoadRunner) una petición ya servida podría quedar en el contenedor y
     * hacer que trabajo posterior NO-HTTP del mismo proceso se viera como `http`; si alguna vez se
     * adopta ese modelo, este discriminante hay que revisarlo. Dentro de un test ocurre lo mismo por
     * la misma razón (el contenedor sobrevive entre la petición y el resto del test), y por eso el
     * canal de una operación posterior a una petición se registra como `http` - que es la verdad
     * sobre ese contexto, no un error.
     */
    private static function currentChannel(): string
    {
        $hasRoutedRequest = app()->bound('request') && app('request')->route() !== null;

        return $hasRoutedRequest
            ? TaxonomyReviewedProposal::CHANNEL_HTTP
            : TaxonomyReviewedProposal::CHANNEL_CONSOLE;
    }

    // =====================================================================================
    // PASO 1-bis: CONFIRM - un humano confirma explícitamente una decisión YA CONGELADA que él
    // mismo no redactó. Nunca publica nada, nunca toca la decisión. TASK-0006B, sección A.
    // =====================================================================================

    /**
     * TASK-0006B (Issue #2 comentario `5936206843`), sección A: tercer evento del ciclo de
     * gobernanza, separado a propósito de los otros dos.
     *
     *   REVIEW/FREEZE (quién redactó)  ->  CONFIRM (quién lo asume como decisión humana)
     *                                  ->  APPLY (quién autoriza la ejecución)
     *
     * Existe porque el re-audit `5934324928` dictaminó que una propuesta preparada por el agente y
     * congelada bajo la cuenta de una persona NO equivale a una decisión humana, y que la atribución
     * contradictoria (`reviewer_id=3` + nota en texto libre diciendo otra cosa) no es base válida
     * para una auditoría de gobernanza.
     *
     * INMUTABILIDAD: escribe EXCLUSIVAMENTE las columnas de confirmación. No toca `reviewer_id`,
     * `reviewed_at`, `decision`, `decision_payload`, `payload_version`, `payload_fingerprint`,
     * `taxonomy_state_fingerprint`, ni el snapshot de fuente. `payload_fingerprint` se computa sobre
     * una lista FIJA de 9 campos de decisión que no incluye ninguna columna de confirmación, así que
     * confirmar NO puede invalidar la tamper-detection de la propuesta (probado por test).
     *
     * ANTI-SUPLANTACIÓN (requisito "do not allow an agent/service actor to masquerade as a human
     * confirmer"): el confirmador tiene que ser el usuario AUTENTICADO - no se puede confirmar en
     * nombre de otra cuenta, ni pasando un `User` cualquiera. Además el canal
     * (`confirmation_channel`) se auto-captura y no se puede falsear. Lo que esto NO pretende es
     * garantizar criptográficamente que ningún código del propio proceso pueda actuar como un
     * humano: dentro de una misma aplicación confiable eso no es evitable, y afirmarlo sería falso.
     * La garantía real es que la confirmación queda atada a una identidad autenticada con permiso,
     * a una referencia de gobernanza verificable, y a un canal registrado con la verdad.
     *
     * IDEMPOTENCIA/CONCURRENCIA: `lockForUpdate()` + re-chequeo de `confirmed_at` DENTRO de la
     * transacción (mismo patrón que `apply()`). Un segundo `confirm()` ve la confirmación ya grabada
     * y devuelve `RESULT_ALREADY_CONFIRMED` sin escribir nada ni auditar de nuevo. La primera
     * confirmación gana y es inmutable - reforzado además por el trigger
     * `taxonomy_reviewed_proposals_guard_confirmation_trg` a nivel de base de datos.
     *
     * @param  string  $confirmationReference  Referencia de gobernanza verificable de ESTA
     *                confirmación (ej. el comentario del Issue donde el dueño registró la decisión).
     *                Mismo criterio de formato que `apply()`: no vacía y con al menos un dígito, para
     *                que sea una REFERENCIA y no un nombre libre.
     * @return array{result:string, proposal:?TaxonomyReviewedProposal}
     */
    public function confirm(int $proposalId, ?User $confirmer, string $confirmationReference, ?string $note = null): array
    {
        if (trim($confirmationReference) === '') {
            throw new \InvalidArgumentException('confirm() requiere $confirmationReference no vacío - la referencia de gobernanza de ESTA confirmación.');
        }

        if (! preg_match('/\d/', $confirmationReference)) {
            throw new \InvalidArgumentException('confirm() requiere que $confirmationReference sea una REFERENCIA (con al menos un dígito), no un nombre libre - mismo criterio que apply().');
        }

        if (! $confirmer) {
            return ['result' => self::RESULT_UNAUTHORIZED, 'proposal' => null];
        }

        // TASK-0006C (Issue #2 `5939903005` punto 6 / `5939882569` punto 7): SOLO por petición HTTP
        // autenticada. Esta es la corrección del defecto real del re-audit `5938949812`: el chequeo
        // `Auth::id() === $confirmer->id` de abajo es correcto pero insuficiente por sí solo, porque
        // un script de consola puede llamar `Auth::login($user)` y satisfacerlo, que es exactamente
        // cómo se produjo la atribución inválida de #492–#495. El canal se auto-captura (no lo
        // declara quien llama), así que este rechazo no se puede sortear pasando un parámetro.
        if (self::currentChannel() !== TaxonomyReviewedProposal::CHANNEL_HTTP) {
            return ['result' => self::RESULT_CHANNEL_NOT_HUMAN, 'proposal' => null];
        }

        // El confirmador tiene que ser el usuario autenticado de esta sesión. Esto es lo que impide
        // "confirmar en nombre de" otra cuenta: ningún llamador puede construir un `User` y
        // atribuirle una confirmación que esa persona no hizo.
        if (Auth::id() === null || (int) Auth::id() !== (int) $confirmer->id) {
            return ['result' => self::RESULT_UNAUTHORIZED, 'proposal' => null];
        }

        // Confirmar DENTRO de un apply() mezclaría los dos eventos que todo este diseño separa.
        if (self::isApplyingC2Publication()) {
            return ['result' => self::RESULT_UNAUTHORIZED, 'proposal' => null];
        }

        return DB::connection('pgsql')->transaction(function () use ($proposalId, $confirmer, $confirmationReference, $note) {
            $proposal = TaxonomyReviewedProposal::query()->lockForUpdate()->find($proposalId);

            if (! $proposal) {
                return ['result' => self::RESULT_NOT_FOUND, 'proposal' => null];
            }

            // Confirmar algo ya aplicado o abortado no tiene sentido: el momento de la confirmación
            // es ANTES de la ejecución, nunca después.
            if ($proposal->status !== TaxonomyReviewedProposal::STATUS_PENDING_APPLY) {
                return ['result' => self::RESULT_ALREADY_PROCESSED, 'proposal' => $proposal];
            }

            if ($proposal->isHumanConfirmed()) {
                return ['result' => self::RESULT_ALREADY_CONFIRMED, 'proposal' => $proposal];
            }

            if (! $proposal->requires_human_confirmation) {
                return ['result' => self::RESULT_NOT_AWAITING_CONFIRMATION, 'proposal' => $proposal];
            }

            // Autorización sobre la FILA FUENTE, con la misma policy `update` que gobierna `freeze()`
            // para ese tipo de origen - confirmar es un acto de revisión, así que exige el mismo
            // permiso que tomar la decisión, resuelto por tipo (sin fuga entre candidatos y
            // relaciones).
            $source = $proposal->proposal_type === TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK
                ? TaxonomyCandidateConceptLink::query()->find($proposal->candidate_link_id)
                : TaxonomyConceptRelation::query()->find($proposal->concept_relation_id);

            if (! $source) {
                return ['result' => self::RESULT_NOT_FOUND, 'proposal' => $proposal];
            }

            if (! $confirmer->can('update', $source)) {
                return ['result' => self::RESULT_UNAUTHORIZED, 'proposal' => $proposal];
            }

            $confirmedAt = now();

            $proposal->update([
                'confirmed_by_id' => $confirmer->id,
                'confirmed_at' => $confirmedAt,
                'confirmation_reference' => $confirmationReference,
                'confirmation_channel' => self::currentChannel(),
                'confirmation_note' => $note,
            ]);

            // Evento de auditoría propio y distinguible: `field = confirmed_at` (no `status`, que es
            // lo que usan freeze/apply), así que la bitácora separa sin ambigüedad
            // PREPARED/FROZEN -> HUMAN_CONFIRMED -> APPLIED. Sin `authorization_reference`/
            // `target_environment`: confirmar no es ejecutar.
            TaxonomyAuditLogger::record(
                entityType: TaxonomyReviewedProposal::class,
                entityId: $proposal->id,
                field: 'confirmed_at',
                oldValue: null,
                newValue: $confirmedAt->format('Y-m-d H:i:s'),
                reason: "HUMAN_CONFIRMED: confirmación humana explícita de una decisión preparada (prepared_by={$proposal->prepared_by_actor_type}, decision={$proposal->decision}, ref={$confirmationReference}, canal=".self::currentChannel().'). No publica ni aplica nada.',
                actorType: TaxonomyAuditLogger::ACTOR_USER,
                algorithmVersion: self::PAYLOAD_VERSION,
            );

            return ['result' => self::RESULT_CONFIRMED, 'proposal' => $proposal->fresh()];
        });
    }

    /**
     * TASK-0006C (Issue #2 `5939882569` «PASS FOR IMPLEMENTATION» + `5939903005` autorización
     * explícita del dueño): ANULA una confirmación cuya procedencia resultó inválida, devolviendo la
     * propuesta al estado «sin confirmar». **Nunca** reasigna la confirmación a otro confirmador -
     * punto 2 del contrato aceptado.
     *
     * Por qué hace falta una operación aparte y no alcanza `confirm()`: el trigger de TASK-0006B hace
     * inmutables los campos de confirmación a propósito, así que la atribución incorrecta no se puede
     * sobrescribir por ningún camino existente. Esta operación enciende, SOLO dentro de su propia
     * transacción (`SET LOCAL`), la única excepción que el trigger reconoce - y esa excepción exige
     * que los cuatro campos queden en NULL y que no cambie ningún campo de decisión.
     *
     * LO QUE NO TOCA (punto 3 del contrato, verificado por test campo por campo): `decision`,
     * `decision_payload`, `payload_version`, `payload_fingerprint`, `taxonomy_state_fingerprint`,
     * `reviewer_id`, `reviewed_at`, `requires_human_confirmation`, `prepared_by_actor_type`,
     * `prepared_via`, `status`, ni la fila fuente (candidato o relación).
     *
     * ATRIBUCIÓN, con la lección del propio defecto que repara: si la corrección la ejecuta el
     * agente, `confirmation_invalidated_by_id` queda **NULL**. Poner ahí la cuenta de una persona
     * repetiría exactamente el error que se está corrigiendo. El actor queda registrado con la verdad
     * en `confirmation_invalidation_actor_type` y el canal se auto-captura; la autorización del dueño
     * vive en la referencia de gobernanza.
     *
     * DELIBERADAMENTE **no** está restringida a canal HTTP, al contrario que `confirm()`: es una
     * operación de corrección de gobernanza que se ejecuta por consola bajo autorización explícita, y
     * su resultado no puede ser nunca «alguien quedó como confirmador» - solo «ya nadie lo es».
     *
     * IDEMPOTENTE: si no hay confirmación que anular devuelve `RESULT_NOT_CONFIRMED` sin escribir
     * nada. CONCURRENCY-SAFE: `lockForUpdate()` + re-chequeo dentro de la transacción.
     *
     * @param  string  $correctionReference  Referencia de gobernanza de ESTA corrección (no vacía y
     *                con al menos un dígito, misma convención que `apply()`/`confirm()`).
     * @param  User|null  $authorizedBy  La PERSONA que ejecuta la corrección, si la ejecuta una
     *                persona autenticada. Si lo ejecuta el agente/un script, se deja en `null` a
     *                propósito y el actor queda como `agent`.
     * @return array{result:string, proposal:?TaxonomyReviewedProposal}
     */
    public function invalidateConfirmation(
        int $proposalId,
        string $correctionReference,
        string $reason,
        ?User $authorizedBy = null,
    ): array {
        if (trim($correctionReference) === '') {
            throw new \InvalidArgumentException('invalidateConfirmation() requiere $correctionReference no vacía - la referencia de gobernanza de ESTA corrección.');
        }

        if (! preg_match('/\d/', $correctionReference)) {
            throw new \InvalidArgumentException('invalidateConfirmation() requiere que $correctionReference sea una REFERENCIA (con al menos un dígito), no un nombre libre - mismo criterio que apply()/confirm().');
        }

        if (trim($reason) === '') {
            throw new \InvalidArgumentException('invalidateConfirmation() requiere un $reason explícito no vacío - una corrección de procedencia sin motivo registrado no es auditable.');
        }

        // Si quien ejecuta declara una persona, tiene que ser la autenticada: mismo criterio
        // anti-suplantación que `confirm()`. Si no declara ninguna, el actor es el agente y se
        // registra como tal.
        if ($authorizedBy !== null && (Auth::id() === null || (int) Auth::id() !== (int) $authorizedBy->id)) {
            return ['result' => self::RESULT_UNAUTHORIZED, 'proposal' => null];
        }

        return DB::connection('pgsql')->transaction(function () use ($proposalId, $correctionReference, $reason, $authorizedBy) {
            $proposal = TaxonomyReviewedProposal::query()->lockForUpdate()->find($proposalId);

            if (! $proposal) {
                return ['result' => self::RESULT_NOT_FOUND, 'proposal' => null];
            }

            // Nunca se corrige algo ya ejecutado: una propuesta aplicada o abortada queda fuera del
            // alcance de esta operación por completo.
            if ($proposal->status !== TaxonomyReviewedProposal::STATUS_PENDING_APPLY) {
                return ['result' => self::RESULT_ALREADY_PROCESSED, 'proposal' => $proposal];
            }

            if (! $proposal->isHumanConfirmed()) {
                return ['result' => self::RESULT_NOT_CONFIRMED, 'proposal' => $proposal];
            }

            $snapshot = [
                'confirmed_by_id' => $proposal->confirmed_by_id,
                'confirmed_at' => $proposal->confirmed_at?->format('Y-m-d H:i:s'),
                'confirmation_reference' => $proposal->confirmation_reference,
                'confirmation_channel' => $proposal->confirmation_channel,
                'confirmation_note' => $proposal->confirmation_note,
            ];
            $invalidatedAt = now();

            // Enciende la excepción del trigger SOLO para esta transacción. `SET LOCAL` se revierte
            // al terminarla, así que no puede quedar habilitada para una transacción posterior.
            DB::connection('pgsql')->statement(
                'SET LOCAL app.taxonomy_confirmation_correction = '.DB::connection('pgsql')->getPdo()->quote($correctionReference)
            );

            $proposal->update([
                // Vuelve a «sin confirmar». NUNCA a «confirmado por otro».
                'confirmed_by_id' => null,
                'confirmed_at' => null,
                'confirmation_reference' => null,
                'confirmation_channel' => null,
                'confirmation_note' => null,
                // Rastro durable de QUÉ se anuló, QUIÉN/QUÉ lo ejecutó, CUÁNDO y BAJO QUÉ autorización.
                'confirmation_invalidated_at' => $invalidatedAt,
                'confirmation_invalidated_by_id' => $authorizedBy?->id,
                'confirmation_invalidation_actor_type' => $authorizedBy
                    ? TaxonomyReviewedProposal::ACTOR_HUMAN_REVIEWER
                    : TaxonomyReviewedProposal::ACTOR_AGENT,
                'confirmation_invalidation_channel' => self::currentChannel(),
                'confirmation_invalidation_reference' => $correctionReference,
                'confirmation_invalidation_reason' => $reason,
                'invalidated_confirmation_snapshot' => $snapshot,
            ]);

            // Evento de auditoría propio y distinguible: `field = confirmation_invalidated_at`, con
            // el `confirmed_at` anulado como valor viejo. Sin `authorization_reference`/
            // `target_environment`: corregir no es ejecutar.
            TaxonomyAuditLogger::record(
                entityType: TaxonomyReviewedProposal::class,
                entityId: $proposal->id,
                field: 'confirmation_invalidated_at',
                oldValue: $snapshot['confirmed_at'],
                newValue: $invalidatedAt->format('Y-m-d H:i:s'),
                reason: 'CONFIRMATION_INVALIDATED: procedencia de confirmación anulada por corrección de gobernanza'
                    .' (ref='.$correctionReference.', actor='.($authorizedBy ? 'human#'.$authorizedBy->id : 'agent')
                    .', canal='.self::currentChannel().'). La decisión, su payload y sus fingerprints NO se modificaron;'
                    .' la propuesta vuelve a exigir confirmación humana y apply() la sigue rechazando. Motivo: '.$reason,
                actorType: $authorizedBy ? TaxonomyAuditLogger::ACTOR_USER : TaxonomyAuditLogger::ACTOR_SYSTEM,
                algorithmVersion: self::PAYLOAD_VERSION,
            );

            return ['result' => self::RESULT_CONFIRMATION_INVALIDATED, 'proposal' => $proposal->fresh()];
        });
    }

    // =====================================================================================
    // PASO 1-ter: SUPERSEDE - retira de la cola una revisión OBSOLETA sin destruirla, para que el
    // candidato pueda volver a revisarse contra el estado ACTUAL. TASK-0006E.
    // =====================================================================================

    /**
     * TASK-0006E (Issue #2 comentario `5955148859`, autorización explícita del dueño tras el PASS de
     * TASK-0006D en `5954835892`): transición de ciclo de vida **no destructiva** que saca de
     * `PENDING_APPLY` una revisión cuya validez caducó porque el estado de la taxonomía cambió.
     *
     * EL PROBLEMA QUE RESUELVE, dicho sin rodeos: hasta acá una propuesta obsoleta estaba en un
     * callejón sin salida. `apply()` la habría registrado como `ABORTED` -terminal- y re-congelar está
     * bloqueado por el índice único parcial `WHERE status = 'PENDING_APPLY'`, así que no existía
     * ninguna forma de volver a pedirle la decisión a un humano sin destruir la anterior. Lo que hace
     * falta no es saltear la revalidación, sino **volver a preguntar sin borrar la respuesta vieja**.
     *
     * LO QUE NO TOCA, verificado campo por campo por test: `decision`, `decision_payload`,
     * `payload_version`, `payload_fingerprint`, `taxonomy_state_fingerprint`, `reviewer_id`,
     * `reviewed_at`, `requires_human_confirmation`, `prepared_by_actor_type`, `prepared_via`,
     * `proposal_group_id`, los campos de confirmación, los de anulación, `applied_at`,
     * `authorization_reference`, `target_environment`, `application_result`, ni la fila fuente. La
     * propuesta queda como registro histórico ÍNTEGRO: se puede seguir demostrando qué decidió un
     * humano, cuándo y sobre qué estado.
     *
     * LO QUE SÍ ESCRIBE: `status -> SUPERSEDED` y el rastro de supersesión (momento, referencia de
     * gobernanza, motivo, actor, canal auto-capturado y el delta de estado durable), más una fila de
     * auditoría propia y distinguible (`field = superseded_at`).
     *
     * POR QUÉ EL `status` ES LA PIEZA CLAVE: los dos índices únicos parciales filtran por
     * `status = 'PENDING_APPLY'`, así que salir de ese estado **libera el slot único del candidato**.
     * Eso -y nada más que eso- es lo que devuelve al candidato a la cola de revisión humana normal. No
     * se debilita ni se modifica ningún índice.
     *
     * SIN SUCESOR, por diseño en esta firma: este método **no crea** una propuesta nueva ni arrastra
     * la decisión vieja hacia adelante. Es la variante de máxima agencia humana, y es exactamente la
     * que el dueño autorizó para #420/#421/#422: la pregunta correcta no es «¿confirmás la decisión
     * vieja?» sino «¿sigue siendo demasiado genérico ahora que existen `oleoducto` y `gasoducto`?» -
     * eso es una revisión nueva, no una confirmación. El camino con sucesor existe en el diseño y
     * queda deliberadamente sin implementar hasta que haga falta y se autorice, porque exige además el
     * diff en la UI para que la confirmación no sea ceremonial.
     *
     * SOLO OBSOLETAS: si la propuesta NO está obsoleta, devuelve `RESULT_NOT_STALE` sin escribir nada.
     * Retirar de la cola una revisión que sigue siendo válida es una decisión de gobernanza distinta y
     * necesita su propia autorización - no se cuela por acá.
     *
     * GRUPOS BILINGÜES: un grupo describe UNA convergencia indivisible, así que se supersede COMPLETO
     * en la misma transacción, serializado por el advisory lock del grupo. Medio grupo supersedido
     * dejaría al hermano aplicable por su cuenta, creando el concepto con un único término adjunto -
     * el duplicado que la sección D de TASK-0006B prohíbe.
     *
     * IDEMPOTENTE y CONCURRENCY-SAFE: `lockForUpdate()` + re-chequeo dentro de la transacción, igual
     * que `apply()`/`confirm()`. Un segundo llamado ve `SUPERSEDED` bajo el lock y devuelve
     * `RESULT_ALREADY_SUPERSEDED` sin escribir ni auditar de nuevo.
     *
     * ATRIBUCIÓN, con la lección de TASK-0006C aplicada: si lo ejecuta el agente bajo autorización del
     * dueño, `supersession_by_id` queda **NULL**. Poner ahí la cuenta de una persona que no ejecutó la
     * acción repetiría el error de procedencia que TASK-0006C vino a reparar. El actor va con la verdad
     * en `supersession_actor_type`, el canal se auto-captura y la autorización vive en la referencia.
     *
     * @param  string  $supersessionReference  Referencia de gobernanza de ESTA supersesión (no vacía y
     *                con al menos un dígito, misma convención que `apply()`/`confirm()`).
     * @param  User|null  $authorizedBy  La PERSONA que la ejecuta, si la ejecuta una persona
     *                autenticada. Si la ejecuta el agente/un script, se deja en `null` a propósito.
     * @return array{result:string, proposals:array<TaxonomyReviewedProposal>, state_delta:?array}
     */
    public function supersedeStaleProposal(
        int $proposalId,
        string $supersessionReference,
        string $reason,
        ?User $authorizedBy = null,
    ): array {
        if (trim($supersessionReference) === '') {
            throw new \InvalidArgumentException('supersedeStaleProposal() requiere $supersessionReference no vacía - la referencia de gobernanza de ESTA supersesión.');
        }

        if (! preg_match('/\d/', $supersessionReference)) {
            throw new \InvalidArgumentException('supersedeStaleProposal() requiere que $supersessionReference sea una REFERENCIA (con al menos un dígito), no un nombre libre - misma convención que apply()/confirm().');
        }

        if (trim($reason) === '') {
            throw new \InvalidArgumentException('supersedeStaleProposal() requiere un $reason explícito no vacío - retirar una decisión humana de la cola sin motivo registrado no es auditable.');
        }

        // Mismo criterio anti-suplantación que `confirm()`/`invalidateConfirmation()`: si quien
        // ejecuta declara una persona, tiene que ser la autenticada.
        if ($authorizedBy !== null && (Auth::id() === null || (int) Auth::id() !== (int) $authorizedBy->id)) {
            return ['result' => self::RESULT_UNAUTHORIZED, 'proposals' => [], 'state_delta' => null];
        }

        // Lectura SIN lock, usada EXCLUSIVAMENTE para elegir la clave de serialización antes de tomar
        // cualquier lock de fila - mismo patrón que `apply()`. `proposal_group_id` se escribe al
        // insertar y nunca se actualiza, así que no puede cambiar entre esta lectura y el lock.
        $proposalGroupId = TaxonomyReviewedProposal::query()->whereKey($proposalId)->value('proposal_group_id');

        return DB::connection('pgsql')->transaction(function () use ($proposalId, $proposalGroupId, $supersessionReference, $reason, $authorizedBy) {
            if ($proposalGroupId !== null) {
                self::acquireGroupAdvisoryLock($proposalGroupId);
            }

            $proposal = TaxonomyReviewedProposal::query()->lockForUpdate()->find($proposalId);

            if (! $proposal) {
                return ['result' => self::RESULT_NOT_FOUND, 'proposals' => [], 'state_delta' => null];
            }

            if ($proposal->isSuperseded()) {
                return ['result' => self::RESULT_ALREADY_SUPERSEDED, 'proposals' => [$proposal], 'state_delta' => $proposal->supersession_state_delta];
            }

            if ($proposal->status !== TaxonomyReviewedProposal::STATUS_PENDING_APPLY) {
                return ['result' => self::RESULT_ALREADY_PROCESSED, 'proposals' => [$proposal], 'state_delta' => null];
            }

            // El grupo completo, o la propuesta sola. Orden determinístico por id.
            $members = $proposal->isGrouped()
                ? TaxonomyReviewedProposal::query()
                    ->where('proposal_group_id', $proposal->proposal_group_id)
                    ->where('status', TaxonomyReviewedProposal::STATUS_PENDING_APPLY)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->all()
                : [$proposal];

            // ---------------------------------------------------------------------------------
            // REVALIDACIÓN ANTES DE TRANSICIONAR (requisito 4 del comentario). Se valida TODO el
            // conjunto ANTES de escribir una sola fila: si un miembro falla, no se supersede ninguno.
            // ---------------------------------------------------------------------------------
            $currentFingerprint = CanonicalConceptBuilderService::dryRunInputFingerprint();

            foreach ($members as $member) {
                // Tamper: supersedir una propuesta manipulada taparía el hallazgo de seguridad.
                if (! self::payloadFingerprintIsValid($member)) {
                    return ['result' => self::RESULT_TAMPERED_PAYLOAD, 'proposals' => [$member], 'state_delta' => null];
                }

                // Obsolescencia REAL contra el fingerprint actual exacto, computado por código de
                // aplicación - no deducido de que dos hashes guardados difieran entre sí.
                if ($member->taxonomy_state_fingerprint === $currentFingerprint) {
                    return ['result' => self::RESULT_NOT_STALE, 'proposals' => [$member], 'state_delta' => null];
                }

                // Ninguna ejecución ocurrió sobre esta fila.
                if ($member->applied_at !== null || $member->authorization_reference !== null) {
                    return ['result' => self::RESULT_ALREADY_PROCESSED, 'proposals' => [$member], 'state_delta' => null];
                }

                // La fila fuente tiene que seguir existiendo Y seguir siendo revisable: liberar el
                // slot no sirve de nada si el candidato ya lo resolvió otro camino.
                if (! $this->sourceRowIsStillReviewable($member)) {
                    return ['result' => self::RESULT_SOURCE_NOT_REVIEWABLE, 'proposals' => [$member], 'state_delta' => null];
                }
            }

            $supersededAt = now();
            $actorType = $authorizedBy
                ? TaxonomyReviewedProposal::ACTOR_HUMAN_REVIEWER
                : TaxonomyReviewedProposal::ACTOR_AGENT;
            $channel = self::currentChannel();

            $superseded = [];
            foreach ($members as $member) {
                $stateDelta = $this->supersessionStateDelta($member, $currentFingerprint, $supersededAt);

                $member->update([
                    'status' => TaxonomyReviewedProposal::STATUS_SUPERSEDED,
                    'superseded_at' => $supersededAt,
                    'supersession_reference' => $supersessionReference,
                    'supersession_reason' => $reason,
                    'supersession_actor_type' => $actorType,
                    'supersession_by_id' => $authorizedBy?->id,
                    'supersession_channel' => $channel,
                    'supersession_state_delta' => $stateDelta,
                    // SIN SUCESOR: explícito, no un olvido. Ver el docblock.
                    'superseded_by_proposal_id' => null,
                ]);

                // Evento de auditoría propio y distinguible: `field = superseded_at`, así que la
                // bitácora separa sin ambigüedad FROZEN -> HUMAN_CONFIRMED -> SUPERSEDED -> (revisión
                // nueva). SIN `authorization_reference`/`target_environment`: supersedir NO es
                // ejecutar, y esos dos campos están reservados para el APPLY real - es la distinción
                // sobre la que se apoya todo el contrato C2.
                TaxonomyAuditLogger::record(
                    entityType: TaxonomyReviewedProposal::class,
                    entityId: $member->id,
                    field: 'superseded_at',
                    oldValue: TaxonomyReviewedProposal::STATUS_PENDING_APPLY,
                    newValue: TaxonomyReviewedProposal::STATUS_SUPERSEDED,
                    reason: 'SUPERSEDED: revisión obsoleta retirada de la cola SIN destruirla y SIN sucesor'
                        .' (ref='.$supersessionReference.', actor='.($authorizedBy ? 'human#'.$authorizedBy->id : 'agent')
                        .', canal='.$channel.($member->isGrouped() ? ', grupo '.$member->proposal_group_id : '').').'
                        .' La decisión, su payload y sus DOS fingerprints quedaron intactos; no se aplicó ni se publicó nada.'
                        .' El slot PENDING_APPLY del origen queda libre para una revisión humana nueva contra el estado actual. Motivo: '.$reason,
                    actorType: $authorizedBy ? TaxonomyAuditLogger::ACTOR_USER : TaxonomyAuditLogger::ACTOR_SYSTEM,
                    algorithmVersion: self::PAYLOAD_VERSION,
                );

                $superseded[] = $member->fresh();
            }

            return [
                'result' => self::RESULT_SUPERSEDED,
                'proposals' => $superseded,
                'state_delta' => $superseded[0]->supersession_state_delta ?? null,
            ];
        });
    }

    /**
     * ¿La fila fuente de esta propuesta sigue en un estado que permita volver a revisarla? Es la
     * contraparte de la compuerta de `apply()`: ahí se exige para poder PUBLICAR, acá para que liberar
     * el slot sirva de algo.
     */
    private function sourceRowIsStillReviewable(TaxonomyReviewedProposal $proposal): bool
    {
        if ($proposal->proposal_type === TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK) {
            $candidate = TaxonomyCandidateConceptLink::query()->lockForUpdate()->find($proposal->candidate_link_id);

            return $candidate !== null && $candidate->status === TaxonomyCandidateConceptLink::STATUS_PENDING;
        }

        $relation = TaxonomyConceptRelation::query()->lockForUpdate()->find($proposal->concept_relation_id);

        return $relation !== null && $relation->status === TaxonomyConceptRelation::STATUS_CANDIDATE;
    }

    /**
     * TASK-0006E (requisito 6 del comentario `5955148859`): la EVIDENCIA durable de por qué esta
     * revisión quedó obsoleta, calculada en el instante de la transición y guardada en
     * `supersession_state_delta`.
     *
     * POR QUÉ SE PERSISTE Y NO SE CALCULA DESPUÉS: el `taxonomy_state_fingerprint` es un **hash**, así
     * que el estado viejo no se puede reconstruir desde él. Si este delta no se guarda ahora, la
     * explicación de por qué la decisión caducó se pierde para siempre.
     *
     * LÍMITE DECLARADO, en vez de presentar una reconstrucción como si fuera exacta: lo que sigue NO es
     * el estado viejo recuperado. Es (a) los dos hashes, que sí son exactos, y (b) los cambios de la
     * taxonomía acotados por `reviewed_at` usando `created_at`/`updated_at`. Eso es evidencia real y
     * suficiente para que un humano entienda qué apareció desde que revisó -para #420/#421/#422, los
     * conceptos `oleoducto` y `gasoducto`-, pero una fila modificada sin tocar `updated_at` no
     * aparecería. El propio delta lleva esa advertencia escrita.
     */
    private function supersessionStateDelta(TaxonomyReviewedProposal $proposal, string $currentFingerprint, \DateTimeInterface $supersededAt): array
    {
        $reviewedAt = $proposal->reviewed_at;

        $conceptsCreatedSince = DB::connection('pgsql')->table('taxonomy_canonical_concepts')
            ->where('created_at', '>', $reviewedAt)
            ->orderBy('id')
            ->get(['id', 'canonical_name_es', 'canonical_name_en', 'status', 'created_at'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'canonical_name_es' => $row->canonical_name_es,
                'canonical_name_en' => $row->canonical_name_en,
                'status' => $row->status,
                'created_at' => (string) $row->created_at,
            ])
            ->all();

        $conceptsUpdatedSince = DB::connection('pgsql')->table('taxonomy_canonical_concepts')
            ->where('updated_at', '>', $reviewedAt)
            ->where(function ($q) use ($reviewedAt) {
                $q->whereNull('created_at')->orWhere('created_at', '<=', $reviewedAt);
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $signals = [];
        foreach ([
            'taxonomy_terms',
            'taxonomy_term_aliases',
            'taxonomy_term_cpv_relations',
            'taxonomy_term_embeddings',
            'taxonomy_term_service_relations',
            'taxonomy_term_source_bindings',
            'taxonomy_settings',
            'taxonomy_concept_relations',
        ] as $table) {
            $signals[$table] = CanonicalConceptBuilderService::tableVersionSignal($table);
        }

        return [
            'computed_at' => $supersededAt->format('Y-m-d H:i:s'),
            'stale' => true,
            'frozen_taxonomy_state_fingerprint' => $proposal->taxonomy_state_fingerprint,
            'current_taxonomy_state_fingerprint' => $currentFingerprint,
            'review_window' => [
                'reviewed_at' => $reviewedAt?->format('Y-m-d H:i:s'),
                'superseded_at' => $supersededAt->format('Y-m-d H:i:s'),
            ],
            // La evidencia decision-relevante: qué conceptos existen hoy que NO existían cuando el
            // humano decidió. Para los tres casos reales esto es lo que vuelve a abrir la pregunta.
            'concepts_created_since_review' => $conceptsCreatedSince,
            'concepts_created_since_review_count' => count($conceptsCreatedSince),
            'concepts_updated_since_review_ids' => $conceptsUpdatedSince,
            'current_concept_graph_fingerprint' => CanonicalConceptBuilderService::conceptGraphFingerprint(),
            'current_table_version_signals' => $signals,
            'protected_counts_at_supersession' => [
                'taxonomy_term_concepts' => DB::connection('pgsql')->table('taxonomy_term_concepts')->count(),
                'taxonomy_canonical_concepts' => DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count(),
                'taxonomy_term_cpv_relations' => DB::connection('pgsql')->table('taxonomy_term_cpv_relations')->count(),
            ],
            'limitation' => 'El fingerprint congelado es un HASH: la composición por tabla del estado VIEJO no se puede reconstruir desde él. Los dos hashes de arriba son exactos; los cambios listados se derivaron de created_at/updated_at acotados por reviewed_at, que es evidencia real pero no una reconstrucción del estado anterior. Una fila modificada sin tocar updated_at no aparecería acá (mismo límite ya documentado en dryRunInputFingerprint()).',
        ];
    }

    // =====================================================================================
    // PASO 2: APPLY - toma un payload YA congelado, revalida contra el estado REAL, y recién ahí
    // escribe, con autorización de ejecución explícita y separada de la revisión.
    // =====================================================================================

    /**
     * @return array{result:string, proposal:?TaxonomyReviewedProposal, abort_reason:?string, application_result:?array}
     */
    public function apply(int $proposalId, string $authorizationReference): array
    {
        if (trim($authorizationReference) === '') {
            throw new \InvalidArgumentException('apply() requiere $authorizationReference no vacío - la referencia de autorización de ESTA ejecución (distinta de quién revisó).');
        }

        if (! preg_match('/\d/', $authorizationReference)) {
            throw new \InvalidArgumentException('apply() requiere que $authorizationReference sea una REFERENCIA (con al menos un dígito), no un nombre libre - mismo criterio que CanonicalConceptApplyService::apply().');
        }

        // Auto-capturado, nunca provisto por quien llama - mismo criterio que Phase C1.
        $targetEnvironment = app()->environment();

        // TASK-0006B re-audit (Issue #2 comentario `5938949812`, nota de concurrencia de grupo):
        // lectura SIN lock, usada EXCLUSIVAMENTE para elegir la clave de serialización antes de
        // tomar cualquier lock de fila. No se decide nada con este valor - toda decisión sigue
        // saliendo de la relectura CON lock de más abajo. `proposal_group_id` se escribe al insertar
        // y nunca se actualiza, así que no puede cambiar entre esta lectura y el lock; y si la
        // propuesta no existe, simplemente no hay advisory lock que tomar y la relectura con lock
        // devuelve `NOT_FOUND` como siempre.
        $proposalGroupId = TaxonomyReviewedProposal::query()->whereKey($proposalId)->value('proposal_group_id');

        return DB::connection('pgsql')->transaction(function () use ($proposalId, $authorizationReference, $targetEnvironment, $proposalGroupId) {
            // TASK-0007 (Issue #2 comentario `5997693379`), PARTE 5: EL PRIMER LOCK DE TODA EJECUCIÓN
            // C2, antes incluso del advisory lock de grupo. Es el mismo lock que toma `applyBatch()`,
            // y es lo que impide que un apply suelto se intercale entre la validación y la escritura
            // de un lote en curso (ver `executionAdvisoryLockKey()`). El orden
            // `ejecución -> grupo -> filas` es idéntico en los dos caminos, así que no hay inversión
            // posible con el lock de grupo de TASK-0006B.
            self::acquireExecutionAdvisoryLock();

            // Antes de cualquier `lockForUpdate()`. Es lo que elimina la espera circular: la clave se
            // deriva del GRUPO, así que es idéntica para todos los hermanos y dos `apply()` que
            // entren por hermanos distintos se serializan acá en vez de quedarse cada uno con el lock
            // de la fila que el otro necesita.
            if ($proposalGroupId !== null) {
                self::acquireGroupAdvisoryLock($proposalGroupId);
            }

            $proposal = TaxonomyReviewedProposal::query()->lockForUpdate()->find($proposalId);

            if (! $proposal) {
                return ['result' => self::RESULT_NOT_FOUND, 'proposal' => null, 'abort_reason' => null, 'application_result' => null];
            }

            // TASK-0006D (Issue #2 comentario `5949253156`), PARTE 1: toda la cadena de validación
            // (estado ya resuelto, tamper, obsolescencia, confirmación humana, existencia/estado/
            // drift de la fila fuente, coherencia del grupo bilingüe, validación server-side de la
            // relación) ya NO vive acá: vive en `evaluateApplicability()`, que es EXACTAMENTE la
            // misma función que corre `preflight()`.
            //
            // Lo único que difiere entre los dos llamadores es CÓMO se leen las filas fuente:
            // `apply()` pasa `lockRows: true` (con `lockForUpdate()`, igual que siempre) y
            // `preflight()` pasa `false` (sin locks y sin transacción de escritura). QUÉ se valida,
            // en qué ORDEN y con qué desenlace es un solo cuerpo de código - así que el preflight no
            // puede quedar "más flojo" ni "más estricto" que el apply real por divergencia de
            // implementación, que es justamente lo que el orquestador pidió evitar.
            $evaluation = $this->evaluateApplicability($proposal, lockRows: true);

            if ($evaluation['blocker'] !== null) {
                return $this->applyOutcomeForBlocker($proposal, $evaluation, $authorizationReference, $targetEnvironment);
            }

            // A partir de acá SOLO quedan escrituras: la validación ya pasó entera y las filas fuente
            // vienen leídas CON lock dentro de `$evaluation['context']`, así que estos métodos no
            // vuelven a decidir nada - escriben el payload congelado tal como se revisó.
            return $proposal->proposal_type === TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK
                ? $this->writeCandidateLinkDecision($proposal, $evaluation['context'], $authorizationReference, $targetEnvironment)
                : $this->writeConceptRelationDecision($proposal, $evaluation['context'], $authorizationReference, $targetEnvironment);
        });
    }

    /**
     * TASK-0006D: traduce un bloqueo de `evaluateApplicability()` al desenlace REAL de `apply()`.
     *
     * Tres clases de desenlace, deliberadamente distintas:
     *
     * 1. ESTADO YA RESUELTO (`ALREADY_APPLIED`/`ALREADY_ABORTED`): replay idempotente, CERO
     *    escrituras nuevas (ni mapeo, ni concepto, ni relación, ni fila de auditoría) - hallazgo 4
     *    de TASK-0004.
     * 2. FALTA CONFIRMACIÓN HUMANA: NO es un abort - ver el docblock de
     *    `RESULT_HUMAN_CONFIRMATION_REQUIRED`. Cero escrituras, `status` intacto en `PENDING_APPLY`,
     *    la propuesta sigue siendo aplicable una vez confirmada.
     * 3. CUALQUIER OTRO BLOQUEO: `abort()` terminal, con la MISMA constante `ABORT_*` que escribía
     *    la versión anterior de `apply()` y el mismo detalle en `application_result`. Si la propuesta
     *    pertenece a un grupo bilingüe, el aborto es DE GRUPO - ver `abortWholeGroup()`.
     */
    private function applyOutcomeForBlocker(TaxonomyReviewedProposal $proposal, array $evaluation, string $authorizationReference, string $targetEnvironment): array
    {
        $blocker = $evaluation['blocker'];
        $detail = $evaluation['detail'];

        if ($blocker === self::PREFLIGHT_ALREADY_APPLIED) {
            return ['result' => self::RESULT_ALREADY_APPLIED, 'proposal' => $proposal, 'abort_reason' => null, 'application_result' => $proposal->application_result];
        }

        if ($blocker === self::PREFLIGHT_ALREADY_ABORTED) {
            return ['result' => self::RESULT_ALREADY_ABORTED, 'proposal' => $proposal, 'abort_reason' => $proposal->application_result['abort_reason'] ?? null, 'application_result' => $proposal->application_result];
        }

        // TASK-0006E: CERO escrituras. `apply()` sobre una propuesta supersedida no la quema ni la
        // revive - informa y se va, que es lo que mantiene el rastro de la supersesión intacto.
        if ($blocker === self::PREFLIGHT_ALREADY_SUPERSEDED) {
            return ['result' => self::RESULT_ALREADY_SUPERSEDED, 'proposal' => $proposal, 'abort_reason' => null, 'application_result' => $detail];
        }

        // TASK-0006D re-audit (comentario `5952211890`, punto B): la compuerta de confirmación humana
        // sigue siendo NO TERMINAL y NO aborta el grupo. Cero escrituras: todos los miembros siguen
        // `PENDING_APPLY` y el grupo sigue aplicable una vez confirmado.
        if ($blocker === self::PREFLIGHT_HUMAN_CONFIRMATION_REQUIRED) {
            return ['result' => self::RESULT_HUMAN_CONFIRMATION_REQUIRED, 'proposal' => $proposal, 'abort_reason' => null, 'application_result' => $detail];
        }

        $abortReason = self::applyAbortReasonForBlocker($blocker, $proposal->proposal_type);

        if ($proposal->isGrouped()) {
            return $this->abortWholeGroup($proposal, $abortReason, $authorizationReference, $targetEnvironment, $detail);
        }

        return $this->abort($proposal, $abortReason, $authorizationReference, $targetEnvironment, $detail);
    }

    /**
     * TASK-0006D re-audit (Issue #2 comentario `5952211890`, punto B — «Group-terminal failure
     * semantics must be coherent»): un bloqueo TERMINAL en un grupo bilingüe aborta el grupo COMPLETO,
     * atómicamente y con rastro de auditoría por miembro.
     *
     * EL DEFECTO QUE CIERRA: antes, un bloqueo terminal descubierto en un HERMANO se enrutaba por
     * `applyOutcomeForBlocker($entryProposal, ...)`, que abortaba únicamente la propuesta de ENTRADA.
     * Eso dejaba al hermano en `PENDING_APPLY` dentro de un grupo que ya había fallado COMO GRUPO - un
     * hermano varado en silencio, aparentemente aplicable, cuando aplicarlo solo crearía el concepto
     * con un único término adjunto (exactamente el duplicado que la sección D de TASK-0006B prohíbe).
     *
     * POR QUÉ TODO BLOQUEO TERMINAL DE UN MIEMBRO DE GRUPO ES GRUPAL, sin excepciones: un grupo
     * describe UNA convergencia indivisible - un concepto, varios términos. Si cualquier parte de esa
     * descripción deja de ser válida (payload manipulado, estado obsoleto, fuente drifteada, entidad
     * faltante, identidad incoherente), la convergencia entera dejó de ser aplicable. No hay un
     * subconjunto del grupo que siga siendo correcto aplicar. Por eso no hace falta distinguir si el
     * bloqueo se detectó en la entrada o en un hermano: el desenlace es el mismo, y eso es lo que hace
     * que entrar por #629 o por #630 dé el MISMO resultado de seguridad.
     *
     * ATOMICIDAD: corre dentro de la transacción de `apply()`, que ya tomó el advisory lock del grupo
     * como PRIMERA acción (antes de cualquier `lockForUpdate()`), así que ningún `apply()` concurrente
     * de un hermano puede intercalarse. Se re-toma por defensa en profundidad, igual que en
     * `evaluateBilingualGroup()`: es re-entrante dentro de la misma transacción.
     *
     * AUDITABILIDAD: cada miembro recibe su propia fila de `taxonomy_audit_log` vía `abort()`, con el
     * mismo `abort_reason` y una referencia explícita a la propuesta donde se detectó el problema. Así
     * la bitácora sola explica por qué un hermano sin ningún defecto propio quedó abortado.
     *
     * Los miembros que ya están `APPLIED` o `ABORTED` no se tocan: abortar algo ya ejecutado sería
     * reescribir historia, y un `ABORTED` previo ya es terminal.
     */
    private function abortWholeGroup(
        TaxonomyReviewedProposal $proposal,
        string $abortReason,
        string $authorizationReference,
        string $targetEnvironment,
        array $detail,
    ): array {
        self::acquireGroupAdvisoryLock($proposal->proposal_group_id);

        $stillPending = TaxonomyReviewedProposal::query()
            ->where('proposal_group_id', $proposal->proposal_group_id)
            ->where('status', TaxonomyReviewedProposal::STATUS_PENDING_APPLY)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $abortedIds = $stillPending->pluck('id')->map(fn ($id) => (int) $id)->all();

        $entryOutcome = null;
        foreach ($stillPending as $member) {
            $memberDetail = array_merge($detail, [
                'group_terminal_failure' => true,
                'proposal_group_id' => $proposal->proposal_group_id,
                // TASK-0006E (requisito 10 del comentario `5955148859`, limpieza no bloqueante
                // señalada en `5954835892`): atribución NORMALIZADA del hallazgo.
                //
                // Antes sólo el bloqueo de tamper traía un id de propuesta ofensora
                // (`tampered_proposal_id`), así que para CUALQUIER otro bloqueo de hermano -estado,
                // fingerprint de grupo, entidad faltante, fuente ya resuelta, drift, identidad
                // incoherente- este campo caía al de ENTRADA y atribuía el problema a la fila
                // equivocada. Ahora todos los bloqueos del bucle de miembros emiten
                // `offending_proposal_id`, así que la atribución es correcta en todos los casos y el
                // fallback a la entrada queda sólo para bloqueos de nivel de entrada, donde la entrada
                // ES la ofensora.
                'detected_on_proposal_id' => (int) ($detail['offending_proposal_id'] ?? $detail['tampered_proposal_id'] ?? $proposal->id),
                'group_aborted_proposal_ids' => $abortedIds,
                'group_abort_note' => $member->id === $proposal->id
                    ? 'Bloqueo terminal en este grupo bilingüe: el grupo se aborta COMPLETO en la misma transacción.'
                    : "Abortada como parte del grupo bilingüe {$proposal->proposal_group_id}: el grupo falló como grupo, así que ningún hermano queda aplicable por separado (aplicar solo uno crearía el concepto con un único término adjunto). El problema se detectó en la propuesta #".($detail['tampered_proposal_id'] ?? $proposal->id).'.',
            ]);

            $outcome = $this->abort($member, $abortReason, $authorizationReference, $targetEnvironment, $memberDetail);

            if ((int) $member->id === (int) $proposal->id) {
                $entryOutcome = $outcome;
            }
        }

        // Si la propuesta de entrada no estaba entre las pendientes, su desenlace ya lo resolvió una
        // compuerta anterior (`ALREADY_APPLIED`/`ALREADY_ABORTED`), así que este camino no se alcanza
        // para ella. Se devuelve igual un resultado coherente en vez de `null`.
        return $entryOutcome ?? [
            'result' => self::RESULT_ABORTED,
            'proposal' => $proposal->fresh(),
            'abort_reason' => $abortReason,
            'application_result' => array_merge($detail, ['group_aborted_proposal_ids' => $abortedIds]),
        ];
    }

    /**
     * TASK-0006D: correspondencia EXPLÍCITA entre el vocabulario de bloqueo del preflight y la
     * constante `ABORT_*` que `apply()` escribe de verdad. No es documentación: es el único lugar
     * donde se decide, lo usa `apply()` en producción, y `ReviewedProposalPreflightTest` lo verifica
     * bloqueo por bloqueo. Si alguien agrega un bloqueo nuevo al preflight y se olvida de mapearlo,
     * este método lanza en vez de abortar con un motivo inventado.
     *
     * `SOURCE_ALREADY_RESOLVED` es el único que depende del tipo: el preflight lo reporta como UN
     * hallazgo ("la fila fuente ya la resolvió otro camino"), mientras `apply()` conserva las dos
     * constantes históricas distintas por tipo de origen - que es lo que ya está escrito en las filas
     * `application_result` existentes y en los tests de TASK-0004.
     */
    private static function applyAbortReasonForBlocker(string $blocker, string $proposalType): string
    {
        return match ($blocker) {
            self::PREFLIGHT_TAMPER_DETECTED => self::ABORT_TAMPER_DETECTED,
            self::PREFLIGHT_STALE_TAXONOMY_STATE => self::ABORT_STALE_TAXONOMY_STATE,
            self::PREFLIGHT_ENTITY_MISSING => self::ABORT_ENTITY_MISSING,
            self::PREFLIGHT_SOURCE_DRIFT => self::ABORT_SOURCE_DRIFT,
            self::PREFLIGHT_SOURCE_ALREADY_RESOLVED => $proposalType === TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK
                ? self::ABORT_CANDIDATE_ALREADY_RESOLVED
                : self::ABORT_RELATION_ALREADY_RESOLVED,
            self::PREFLIGHT_GROUP_INCOMPLETE_OR_INCONSISTENT => self::ABORT_BILINGUAL_GROUP_NOT_APPLICABLE,
            self::PREFLIGHT_RELATION_VALIDATION_FAILED => self::ABORT_RELATION_INVALID_AT_APPLY_TIME,
            default => throw new \LogicException("Bloqueo de preflight sin desenlace de apply() mapeado: {$blocker}. Agregalo a applyAbortReasonForBlocker() en vez de dejar que apply() aborte con un motivo inventado."),
        };
    }

    /**
     * TASK-0006D: misma correspondencia que usa `apply()`, expuesta para el test de paridad y para el
     * informe pre-APPLY. Devuelve `null` para los bloqueos que NO producen un abort (los dos estados
     * ya resueltos y la compuerta de confirmación humana) - eso también es parte de la paridad: un
     * `apply()` prematuro sobre una propuesta sin confirmar NO la quema.
     */
    public static function applyAbortReasonForPreflightBlocker(string $blocker, string $proposalType): ?string
    {
        if (in_array($blocker, [
            self::PREFLIGHT_READY_TO_APPLY,
            self::PREFLIGHT_ALREADY_APPLIED,
            self::PREFLIGHT_ALREADY_ABORTED,
            self::PREFLIGHT_ALREADY_SUPERSEDED,
            self::PREFLIGHT_HUMAN_CONFIRMATION_REQUIRED,
            self::PREFLIGHT_NOT_FOUND,
        ], true)) {
            return null;
        }

        return self::applyAbortReasonForBlocker($blocker, $proposalType);
    }

    // =====================================================================================
    // PASO 2-bis: PREFLIGHT - TASK-0006D (Issue #2 comentario `5949253156`), PARTE 1.
    // =====================================================================================

    /**
     * Valida si una propuesta congelada se podría aplicar HOY, **sin escribir absolutamente nada**.
     *
     * POR QUÉ EXISTE. Hasta TASK-0006D la única forma de saber si una propuesta seguía siendo
     * aplicable era llamar a `apply()`, y `apply()` es destructivo cuando falla: la obsolescencia, el
     * tamper, el drift y la invalidez de una relación se registran con `abort()`, que pasa la
     * propuesta a `ABORTED` **de forma terminal**. Y como re-congelar está bloqueado por el índice
     * único parcial `WHERE status = 'PENDING_APPLY'`, "probar con apply() para ver qué pasa" puede
     * quemar una decisión humana sin recuperación posible. El orquestador lo dijo así:
     * «apply() is NOT a safe preflight API». Esto lo es.
     *
     * LO QUE NO HACE, y está garantizado por construcción (no por disciplina del llamador): no abre
     * transacción de escritura, no toca `status`, `application_result`, `authorization_reference`,
     * `target_environment` ni `applied_at`, no escribe la fila fuente, no inserta auditoría, no crea
     * conceptos/mapeos/relaciones, y **no llama a `apply()` por ningún camino**. Solo ejecuta
     * `evaluateApplicability(..., lockRows: false)` -que son SELECTs- y describe lo que `apply()`
     * escribiría.
     *
     * LO QUE SÍ ES, dicho sin exagerar: una foto sin lock, válida en el instante en que se tomó. No
     * reserva nada ni autoriza nada; entre este preflight y un `apply()` posterior el estado puede
     * cambiar, y por eso `apply()` revalida todo otra vez con locks. Un `READY_TO_APPLY` acá
     * significa «hoy no hay nada que lo impida», nunca «ya está aprobado para ejecutarse» - la
     * autorización de ejecución sigue siendo un acto humano separado.
     *
     * @return array Informe de una propuesta. `blocker` es `READY_TO_APPLY` o el impedimento concreto.
     */
    public function preflight(int $proposalId): array
    {
        $proposal = TaxonomyReviewedProposal::query()->find($proposalId);

        if (! $proposal) {
            return [
                'proposal_id' => $proposalId,
                'blocker' => self::PREFLIGHT_NOT_FOUND,
                'governance_category' => self::CATEGORY_BLOCKED_FOR_OTHER_REASON,
                'detail' => ['note' => "No existe ninguna propuesta con id={$proposalId}."],
                'action_required' => 'Verificar el id - no hay ninguna propuesta revisada con ese identificador.',
                'expected_write_set' => [],
                'expected_write_count' => 0,
            ];
        }

        return $this->preflightProposal($proposal);
    }

    /**
     * TASK-0006D: el preflight de TODAS las propuestas congeladas, en orden de id - la base del
     * informe pre-APPLY de la PARTE 4. Igual de read-only que `preflight()`: una propuesta por
     * iteración, cada una con su propia evaluación independiente.
     *
     * @param  int[]  $onlyIds  Vacío = todas.
     * @return array<int, array>
     */
    public function preflightAll(array $onlyIds = []): array
    {
        return TaxonomyReviewedProposal::query()
            ->when($onlyIds !== [], fn ($q) => $q->whereIn('id', $onlyIds))
            ->orderBy('id')
            ->get()
            ->map(fn (TaxonomyReviewedProposal $proposal) => $this->preflightProposal($proposal))
            ->all();
    }

    private function preflightProposal(TaxonomyReviewedProposal $proposal): array
    {
        $evaluation = $this->evaluateApplicability($proposal, lockRows: false);

        $blocker = $evaluation['blocker'] ?? self::PREFLIGHT_READY_TO_APPLY;
        $context = $evaluation['context'];

        return array_merge(
            [
                'proposal_id' => (int) $proposal->id,
                'proposal_type' => $proposal->proposal_type,
                'decision' => $proposal->decision,
                'proposal_status' => $proposal->status,
                'candidate_link_id' => $proposal->candidate_link_id,
                'concept_relation_id' => $proposal->concept_relation_id,
                'source_label' => self::preflightSourceLabel($proposal),
                'review_provenance' => [
                    'reviewer_id' => $proposal->reviewer_id,
                    'reviewed_at' => $proposal->reviewed_at?->format('Y-m-d H:i:s'),
                    'prepared_by_actor_type' => $proposal->prepared_by_actor_type,
                    'prepared_via' => $proposal->prepared_via,
                ],
                'confirmation' => [
                    'required' => (bool) $proposal->requires_human_confirmation,
                    'confirmed_at' => $proposal->confirmed_at?->format('Y-m-d H:i:s'),
                    'confirmed_by_id' => $proposal->confirmed_by_id,
                    'channel' => $proposal->confirmation_channel,
                    'reference' => $proposal->confirmation_reference,
                    'previously_invalidated' => $proposal->hasInvalidatedConfirmation(),
                ],
                'blocker' => $blocker,
                'governance_category' => self::governanceCategoryForBlocker($blocker),
                'would_apply_abort_with' => self::applyAbortReasonForPreflightBlocker($blocker, $proposal->proposal_type),
                'action_required' => self::actionRequiredForBlocker($blocker),
                'detail' => $evaluation['detail'],
            ],
            $this->preflightChecks($proposal, $blocker, $context),
            $this->expectedWriteSet($proposal, $blocker, $context),
        );
    }

    /**
     * TASK-0006D: el estado de cada compuerta, con una regla de honestidad explícita - `null`
     * significa **«no se evaluó»**, nunca «pasó».
     *
     * La cadena de `apply()` corta en el PRIMER impedimento (y debe hacerlo: seguir validando sobre
     * una premisa ya falsa daría respuestas inventadas - no se puede buscar drift en una fila fuente
     * que no existe). El preflight no simula las compuertas que no corrieron: informa exactamente las
     * que `apply()` habría llegado a evaluar, y deja las demás en `null`. Lo que una compuerta SÍ
     * evaluó se deduce de la evidencia que quedó en el contexto, no de una segunda pasada paralela
     * que podría divergir.
     */
    private function preflightChecks(TaxonomyReviewedProposal $proposal, string $blocker, array $context): array
    {
        $reachedStaleGate = ! in_array($blocker, [
            self::PREFLIGHT_ALREADY_APPLIED,
            self::PREFLIGHT_ALREADY_ABORTED,
            self::PREFLIGHT_ALREADY_SUPERSEDED,
            self::PREFLIGHT_TAMPER_DETECTED,
        ], true);
        $currentFingerprint = $context['current_taxonomy_fingerprint'] ?? null;

        $sourceRow = $context['candidate'] ?? $context['relation'] ?? null;
        $reachedSourceGate = $reachedStaleGate
            && $blocker !== self::PREFLIGHT_STALE_TAXONOMY_STATE
            && $blocker !== self::PREFLIGHT_HUMAN_CONFIRMATION_REQUIRED;

        // REJECT es la única decisión que deliberadamente NO depende de ningún campo fuente
        // congelado, así que para ella el drift no es "ok", es "no aplica" - ver el comentario de
        // `evaluateCandidateLink()`.
        $driftApplicable = $proposal->decision !== TaxonomyReviewedProposal::DECISION_REJECT;
        $driftChecked = isset($context['frozen_term_id']) || isset($context['frozen_source_concept_id']);

        return [
            'payload_fingerprint' => $proposal->payload_fingerprint,
            // Evidencia registrada por la compuerta, NO deducida del bloqueo: en un grupo bilingüe el
            // tamper puede estar en un hermano, y en ese caso el payload de ESTA fila sí es válido.
            // Deducirlo de `blocker === TAMPER_DETECTED` reportaría como manipulada una fila intacta.
            'payload_fingerprint_valid' => $context['entry_payload_valid'] ?? null,
            // TASK-0006E: estado histórico, visible en el informe sin tener que cruzar tablas.
            'superseded' => $proposal->isSuperseded(),
            'supersession' => $proposal->hasSupersessionTrail() ? [
                'superseded_at' => $proposal->superseded_at?->format('Y-m-d H:i:s'),
                'reference' => $proposal->supersession_reference,
                'reason' => $proposal->supersession_reason,
                'actor_type' => $proposal->supersession_actor_type,
                'channel' => $proposal->supersession_channel,
                'superseded_by_proposal_id' => $proposal->superseded_by_proposal_id,
                'has_state_delta' => $proposal->supersession_state_delta !== null,
            ] : null,
            'taxonomy_state_fingerprint_frozen' => $proposal->taxonomy_state_fingerprint,
            'taxonomy_state_fingerprint_current' => $currentFingerprint,
            'stale' => $currentFingerprint === null ? null : $currentFingerprint !== $proposal->taxonomy_state_fingerprint,
            'human_confirmation_satisfied' => $reachedStaleGate && $blocker !== self::PREFLIGHT_STALE_TAXONOMY_STATE
                ? $blocker !== self::PREFLIGHT_HUMAN_CONFIRMATION_REQUIRED
                : null,
            'source_entity_exists' => $reachedSourceGate ? $sourceRow !== null : null,
            'source_state' => $sourceRow?->status,
            'source_state_compatible' => $sourceRow === null
                ? ($reachedSourceGate ? false : null)
                : $blocker !== self::PREFLIGHT_SOURCE_ALREADY_RESOLVED,
            'source_snapshot_drift' => match (true) {
                ! $driftApplicable => null,
                $blocker === self::PREFLIGHT_SOURCE_DRIFT => true,
                $driftChecked => false,
                default => null,
            },
            'source_snapshot_drift_applicable' => $driftApplicable,
            'group' => $proposal->isGrouped() ? [
                'proposal_group_id' => $proposal->proposal_group_id,
                'member_ids' => $context['group_member_ids'] ?? null,
                'members_pending_apply' => isset($context['group_pending'])
                    ? array_map(fn (array $entry) => (int) $entry['proposal']->id, $context['group_pending'])
                    : null,
                'consistent' => array_key_exists('group_pending', $context)
                    ? $blocker !== self::PREFLIGHT_GROUP_INCOMPLETE_OR_INCONSISTENT
                    : ($blocker === self::PREFLIGHT_GROUP_INCOMPLETE_OR_INCONSISTENT ? false : null),
                'reuses_concept_id' => $context['group_reuse_concept_id'] ?? null,
                // TASK-0006D re-audit (comentario `5952211890`, punto D): integridad del payload de
                // TODO el grupo, no sólo de la fila de entrada. Mapa id => true|false|null, con la
                // misma convención: `null` = no evaluado (una compuerta anterior cortó el recorrido),
                // nunca "válido".
                'member_payload_fingerprint_valid' => $context['group_member_payload_valid'] ?? null,
                'tampered_member_ids' => isset($context['group_member_payload_valid'])
                    ? array_map('intval', array_keys(array_filter($context['group_member_payload_valid'], fn ($v) => $v === false)))
                    : null,
                'all_member_payloads_valid' => self::aggregateMemberPayloadValidity($context['group_member_payload_valid'] ?? null),
            ] : null,
            'relation_validation' => $context['relation_validation'] ?? null,
            'checks_null_means' => 'null = la compuerta NO se evaluó porque una anterior bloqueó primero (apply() corta igual), o no aplica a esta decisión. Nunca significa "pasó".',
        ];
    }

    /**
     * TASK-0006D re-audit (comentario `5952211890`, punto D): agregado de la validez de payload de un
     * grupo. `false` si CUALQUIER miembro resultó manipulado; `true` sólo si TODOS se verificaron y
     * dieron válidos; `null` si quedó alguno sin evaluar - nunca se asume que un miembro no verificado
     * esté bien.
     */
    private static function aggregateMemberPayloadValidity(?array $perMember): ?bool
    {
        if ($perMember === null || $perMember === []) {
            return null;
        }

        if (in_array(false, $perMember, true)) {
            return false;
        }

        return in_array(null, $perMember, true) ? null : true;
    }

    private static function preflightSourceLabel(TaxonomyReviewedProposal $proposal): string
    {
        if ($proposal->proposal_type === TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK) {
            $term = $proposal->candidateLink?->term;

            return $term
                ? sprintf('candidato #%s / término #%s `%s` (%s)', $proposal->candidate_link_id, $term->id, $term->term, $term->language)
                : sprintf('candidato #%s', $proposal->candidate_link_id);
        }

        $relation = $proposal->conceptRelation;

        return $relation
            ? sprintf('relación #%s / %s → %s (%s)', $relation->id, $relation->source_concept_id, $relation->target_concept_id, $relation->relation_type)
            : sprintf('relación #%s', $proposal->concept_relation_id);
    }

    /**
     * TASK-0006D, PARTE 4: las tres categorías de gobernanza del informe, derivadas del bloqueo.
     *
     * `NEEDS_REVALIDATION` es exactamente «el mundo cambió y la decisión congelada ya no describe la
     * realidad, así que ningún reintento la puede arreglar - hace falta revisar contra el estado
     * actual»: obsolescencia global, drift de la fila fuente, o una relación que dejó de ser válida.
     *
     * `TAMPER_DETECTED` NO entra ahí a propósito: no es un problema de frescura sino un hallazgo de
     * seguridad (alguien modificó campos de decisión después de congelarlos), y tratarlo como «hay
     * que revalidar» invitaría a resolverlo re-congelando en vez de investigando.
     * `HUMAN_CONFIRMATION_REQUIRED` tampoco: no falta revalidar nada, falta que una persona confirme.
     */
    public static function governanceCategoryForBlocker(string $blocker): string
    {
        return match ($blocker) {
            self::PREFLIGHT_READY_TO_APPLY => self::CATEGORY_READY_TO_APPLY,
            self::PREFLIGHT_STALE_TAXONOMY_STATE,
            self::PREFLIGHT_SOURCE_DRIFT,
            self::PREFLIGHT_RELATION_VALIDATION_FAILED => self::CATEGORY_NEEDS_REVALIDATION,
            default => self::CATEGORY_BLOCKED_FOR_OTHER_REASON,
        };
    }

    private static function actionRequiredForBlocker(string $blocker): string
    {
        return match ($blocker) {
            self::PREFLIGHT_READY_TO_APPLY => 'Ninguna acción correctiva. Falta SOLO la autorización de ejecución humana explícita (TASK-0007), que esta tarea no otorga.',
            self::PREFLIGHT_STALE_TAXONOMY_STATE => 'Revalidación contra el estado actual ANTES de cualquier apply(). No pasar por apply() para "probar": abortaría la propuesta de forma terminal. Ver el diseño de supersesión de TASK-0006D.',
            self::PREFLIGHT_SOURCE_DRIFT => 'Revisión nueva: la fila fuente cambió después de congelar, así que el payload ya no describe lo que un humano revisó.',
            self::PREFLIGHT_RELATION_VALIDATION_FAILED => 'Revisión nueva de la relación: dejó de ser válida contra el grafo actual (duplicado/simétrica/inversa/ciclo).',
            self::PREFLIGHT_TAMPER_DETECTED => 'INVESTIGAR: los campos de decisión se modificaron después de freeze(). No re-congelar ni aplicar hasta entender quién y cuándo (ver taxonomy_audit_log).',
            self::PREFLIGHT_HUMAN_CONFIRMATION_REQUIRED => 'Confirmación humana explícita por la UI autenticada (Propuestas Revisadas → "Confirmar decisión preparada"). Ni consola ni agente pueden confirmar.',
            self::PREFLIGHT_ENTITY_MISSING => 'La entidad referenciada por el payload congelado ya no existe - hace falta decidir de nuevo sobre el estado actual.',
            self::PREFLIGHT_SOURCE_ALREADY_RESOLVED => 'La fila fuente ya la resolvió otro camino - revisar por qué y si esta propuesta sigue teniendo sentido.',
            self::PREFLIGHT_GROUP_INCOMPLETE_OR_INCONSISTENT => 'El grupo bilingüe no describe una convergencia coherente - revisar los miembros antes de cualquier apply().',
            self::PREFLIGHT_ALREADY_APPLIED => 'Ninguna: ya se aplicó.',
            self::PREFLIGHT_ALREADY_ABORTED => 'Ninguna sobre esta fila: abortar es terminal. Si la decisión sigue siendo necesaria, hace falta una revisión nueva.',
            self::PREFLIGHT_ALREADY_SUPERSEDED => 'Ninguna sobre esta fila: quedó como registro histórico íntegro. La acción pendiente es HUMANA y vive en el candidato, que volvió a la cola de revisión normal - una persona decide de nuevo desde la evidencia actual.',
            default => 'Sin acción definida para este bloqueo.',
        };
    }

    /**
     * TASK-0006D: QUÉ escribiría `apply()`, descrito sin escribir nada.
     *
     * Incluye deliberadamente el caso BLOQUEADO, y eso es lo más importante de este método: el
     * orquestador advirtió que las propuestas obsoletas «MUST NOT be passed to apply() merely to
     * discover whether they are stale, because apply() writes ABORTED on stale-state failure». El
     * informe lo dice fila por fila - para un bloqueo terminal el write-set de `apply()` no está
     * vacío: son DOS escrituras que QUEMAN la propuesta (status -> ABORTED + la fila de auditoría del
     * intento). Así nadie tiene que deducirlo.
     */
    private function expectedWriteSet(TaxonomyReviewedProposal $proposal, string $blocker, array $context): array
    {
        $abortReason = self::applyAbortReasonForPreflightBlocker($blocker, $proposal->proposal_type);

        if ($abortReason !== null) {
            // TASK-0006D re-audit (comentario `5952211890`, punto B): en un grupo bilingüe un bloqueo
            // terminal aborta el grupo COMPLETO, así que el write-set NO son 2 filas sino 2 POR CADA
            // miembro todavía pendiente. Decirlo mal haría parecer barato un apply() de prueba que en
            // realidad quemaría varias decisiones humanas de una sola vez.
            $terminalRows = $proposal->isGrouped()
                ? max(1, TaxonomyReviewedProposal::query()
                    ->where('proposal_group_id', $proposal->proposal_group_id)
                    ->where('status', TaxonomyReviewedProposal::STATUS_PENDING_APPLY)
                    ->count())
                : 1;

            return [
                'expected_write_set' => [
                    ['table' => 'taxonomy_reviewed_proposals', 'operation' => 'UPDATE', 'rows' => $terminalRows, 'description' => "status PENDING_APPLY -> ABORTED (terminal), con application_result.abort_reason = {$abortReason}".($terminalRows > 1 ? " - en los {$terminalRows} miembros del grupo bilingüe que siguen pendientes, atómicamente" : '')],
                    ['table' => 'taxonomy_audit_log', 'operation' => 'INSERT', 'rows' => $terminalRows, 'description' => 'registro del intento de apply() abortado, con authorization_reference y target_environment, uno por miembro abortado'],
                ],
                'expected_write_count' => $terminalRows * 2,
                'expected_write_set_note' => 'ATENCIÓN: pasar esta propuesta por apply() NO es una prueba inocua - la quemaría de forma terminal, y re-congelar está bloqueado por el índice único parcial. Cero publicación de taxonomía, pero la decisión humana quedaría irrecuperable.'
                    .($terminalRows > 1 ? " Y no sería una sola: el grupo falla como grupo, así que las {$terminalRows} propuestas pendientes del grupo quedarían abortadas juntas." : ''),
            ];
        }

        if ($blocker !== self::PREFLIGHT_READY_TO_APPLY) {
            return [
                'expected_write_set' => [],
                'expected_write_count' => 0,
                'expected_write_set_note' => match ($blocker) {
                    self::PREFLIGHT_HUMAN_CONFIRMATION_REQUIRED => 'Cero escrituras: la compuerta de confirmación humana NO es un abort, así que un apply() prematuro deja la propuesta intacta en PENDING_APPLY (ver RESULT_HUMAN_CONFIRMATION_REQUIRED).',
                    self::PREFLIGHT_ALREADY_SUPERSEDED => 'Cero escrituras: un apply() sobre una propuesta supersedida no la quema ni la revive, así que el rastro de la supersesión queda intacto.',
                    default => 'Cero escrituras: replay idempotente de un estado ya resuelto.',
                },
            ];
        }

        $writes = [];

        if ($proposal->proposal_type === TaxonomyReviewedProposal::TYPE_CONCEPT_RELATION) {
            $writes[] = $proposal->decision === TaxonomyReviewedProposal::DECISION_REJECT
                ? ['table' => 'taxonomy_concept_relations', 'operation' => 'UPDATE', 'rows' => 1, 'description' => "relación #{$proposal->concept_relation_id}: status candidate -> rejected (NO publica el grafo)"]
                : ['table' => 'taxonomy_concept_relations', 'operation' => 'UPDATE', 'rows' => 1, 'description' => "relación #{$proposal->concept_relation_id}: status candidate -> approved (PUBLICA el grafo de conceptos)"];

            return self::writeSetWithProposalBookkeeping($writes, 1);
        }

        $candidateId = $proposal->candidate_link_id;

        if ($proposal->decision === TaxonomyReviewedProposal::DECISION_REJECT) {
            $writes[] = ['table' => 'taxonomy_candidate_concept_links', 'operation' => 'UPDATE', 'rows' => 1, 'description' => "candidato #{$candidateId}: status pending -> rejected (CERO escrituras de taxonomía publicada)"];

            return self::writeSetWithProposalBookkeeping($writes, 1);
        }

        if ($proposal->decision === TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED) {
            $writes[] = ['table' => 'taxonomy_candidate_concept_links', 'operation' => 'UPDATE', 'rows' => 1, 'description' => "candidato #{$candidateId}: status pending -> context_required (CERO taxonomy_term_concepts, CERO conceptos nuevos)"];

            return self::writeSetWithProposalBookkeeping($writes, 1);
        }

        if ($proposal->decision === TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING) {
            $conceptId = $context['target_concept_id'];
            $writes[] = self::termConceptWrite((int) $context['frozen_term_id'], (int) $conceptId);
            $writes[] = ['table' => 'taxonomy_candidate_concept_links', 'operation' => 'UPDATE', 'rows' => 1, 'description' => "candidato #{$candidateId}: status pending -> published (+ published_term_concept_id)"];

            return self::writeSetWithProposalBookkeeping($writes, 1);
        }

        // CREATE_NEW - suelta o agrupada.
        if (! $proposal->isGrouped()) {
            $writes[] = ['table' => 'taxonomy_canonical_concepts', 'operation' => 'INSERT', 'rows' => 1, 'description' => 'un concepto canónico nuevo con la identidad del payload congelado'];
            $writes[] = ['table' => 'taxonomy_term_concepts', 'operation' => 'INSERT', 'rows' => 1, 'description' => "término #{$context['frozen_term_id']} → el concepto nuevo"];
            $writes[] = ['table' => 'taxonomy_candidate_concept_links', 'operation' => 'UPDATE', 'rows' => 1, 'description' => "candidato #{$candidateId}: status pending -> published (+ published_term_concept_id)"];

            return self::writeSetWithProposalBookkeeping($writes, 1);
        }

        $pending = $context['group_pending'] ?? [];
        $memberCount = count($pending);

        if (($context['group_reuse_concept_id'] ?? null) === null) {
            $writes[] = ['table' => 'taxonomy_canonical_concepts', 'operation' => 'INSERT', 'rows' => 1, 'description' => sprintf('UN solo concepto canónico bilingüe (ES/EN) para los %d miembros del grupo - nunca uno por candidato', $memberCount)];
        }

        foreach ($pending as $entry) {
            $termId = (int) ($entry['proposal']->decision_payload['term_id'] ?? 0);
            $writes[] = self::termConceptWrite($termId, null);
            $writes[] = ['table' => 'taxonomy_candidate_concept_links', 'operation' => 'UPDATE', 'rows' => 1, 'description' => sprintf('candidato #%d: status pending -> published (+ published_term_concept_id)', $entry['candidate']->id)];
        }

        return self::writeSetWithProposalBookkeeping($writes, $memberCount);
    }

    /**
     * `taxonomy_term_concepts` es idempotente (`UNIQUE(term_id, concept_id)`): si el link ya existe
     * `apply()` lo REUSA en vez de insertar. El preflight lo consulta de verdad -es una lectura- así
     * que el informe dice «INSERT» o «reuso» con el dato real, no una suposición.
     */
    private static function termConceptWrite(int $termId, ?int $conceptId): array
    {
        if ($conceptId === null) {
            return ['table' => 'taxonomy_term_concepts', 'operation' => 'INSERT', 'rows' => 1, 'description' => "término #{$termId} → el concepto nuevo del grupo"];
        }

        $exists = DB::connection('pgsql')->table('taxonomy_term_concepts')
            ->where('term_id', $termId)
            ->where('concept_id', $conceptId)
            ->exists();

        return $exists
            ? ['table' => 'taxonomy_term_concepts', 'operation' => 'REUSE', 'rows' => 0, 'description' => "el link término #{$termId} → concepto #{$conceptId} YA existe: apply() lo reutiliza sin insertar"]
            : ['table' => 'taxonomy_term_concepts', 'operation' => 'INSERT', 'rows' => 1, 'description' => "término #{$termId} → concepto #{$conceptId}"];
    }

    /**
     * Las dos escrituras de contabilidad que `apply()` hace SIEMPRE que ejecuta, una por propuesta
     * resuelta: marcar la propuesta `APPLIED` (con `applied_at`/`authorization_reference`/
     * `target_environment`) y su fila de auditoría de EJECUCIÓN.
     */
    private static function writeSetWithProposalBookkeeping(array $writes, int $proposalRows): array
    {
        $writes[] = ['table' => 'taxonomy_reviewed_proposals', 'operation' => 'UPDATE', 'rows' => $proposalRows, 'description' => 'status PENDING_APPLY -> APPLIED, con applied_at, authorization_reference y target_environment'];
        $writes[] = ['table' => 'taxonomy_audit_log', 'operation' => 'INSERT', 'rows' => $proposalRows, 'description' => 'auditoría de EJECUCIÓN (con authorization_reference y target_environment, lo que la distingue de la auditoría de revisión)'];

        return [
            'expected_write_set' => $writes,
            'expected_write_count' => array_sum(array_column($writes, 'rows')),
            'expected_write_set_note' => 'Descripción, no ejecución: el preflight NO escribió ninguna de estas filas.',
        ];
    }

    /**
     * Ids de propuestas que todavía exigen confirmación humana. Para una propuesta suelta es ella
     * misma o nada; para un grupo bilingüe es CUALQUIER miembro sin confirmar - aplicar medio grupo
     * confirmado crearía el concepto con un solo término adjunto (sección D).
     *
     * @return int[]
     */
    private function unconfirmedMembers(TaxonomyReviewedProposal $proposal): array
    {
        if (! $proposal->isGrouped()) {
            return $proposal->awaitsHumanConfirmation() ? [$proposal->id] : [];
        }

        return TaxonomyReviewedProposal::query()
            ->where('proposal_group_id', $proposal->proposal_group_id)
            // TASK-0006E: sólo los miembros que todavía están en la cola. Un hermano `SUPERSEDED`
            // (retirado por obsolescencia) o `ABORTED` ya no se va a aplicar, así que exigir su
            // confirmación bloquearía el grupo por una fila que nadie va a ejecutar. Para un
            // `APPLIED` la pregunta no existe: aplicar ya exigió la confirmación.
            ->where('status', TaxonomyReviewedProposal::STATUS_PENDING_APPLY)
            ->where('requires_human_confirmation', true)
            ->whereNull('confirmed_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    // =====================================================================================
    // PASO 2-ter: BATCH APPLY ATÓMICO - TASK-0007 (Issue #2 comentario `5997693379`).
    // =====================================================================================

    /**
     * Aplica un CONJUNTO de propuestas revisadas como UNA sola ejecución atómica.
     *
     * EL HUECO SEMÁNTICO QUE CIERRA, que es la razón por la que existe TASK-0007. Encadenar
     * `apply(491) -> apply(492) -> ...` NO es una estrategia de ejecución válida, y no por un defecto
     * de `apply()` sino por lo que `apply()` es: el contrato de una sola propuesta exige que el estado
     * de la taxonomía siga siendo EXACTAMENTE el que el humano revisó
     * (`taxonomy_state_fingerprint == dryRunInputFingerprint()` en el instante de ejecutar). Varias
     * propuestas de la cola real mutan deliberadamente las entradas de ese fingerprint - una
     * MAP_TO_EXISTING inserta un `taxonomy_term_concepts`, un grupo CREATE_NEW crea un concepto
     * canónico más dos links, un REJECT de relación escribe `taxonomy_concept_relations` - así que
     * **la primera escritura que cambia el grafo deja obsoletas a todas las demás**. Con el camino de
     * una sola propuesta eso no sería un error recuperable: `apply()` registra la obsolescencia con
     * `abort()`, que es TERMINAL, y re-congelar está bloqueado por el índice único parcial. Un loop
     * ingenuo quemaría decisiones humanas de una en una.
     *
     * Las 12 propuestas no son 12 ejecuciones independientes: son UN conjunto revisado contra UN
     * snapshot compartido. Este método ejecuta eso como lo que es.
     *
     * EL CONTRATO, en el orden exacto en que se obtiene (PARTE 1 del comentario):
     *
     * 1. UNA transacción para todo el lote.
     * 2. El fingerprint de baseline se computa UNA sola vez, antes de cualquier escritura.
     * 3. El conjunto pedido se carga y bloquea completo, en orden determinístico de id.
     * 4. TODA unidad de ejecución se valida contra ESE mismo baseline ANTES de la primera escritura.
     * 5. Recién si el lote ENTERO pasa la validación ocurre alguna escritura.
     * 6. Una vez empezadas las escrituras no se recomputa el fingerprint global entre miembros - y no
     *    porque se "desactive" una compuerta, sino porque en la fase de escritura ya no queda ninguna
     *    validación por correr: toda la validación terminó en el paso 4. Los cambios de grafo que
     *    produce un miembro de ESTE lote autorizado son esperados, no drift externo.
     * 7. Cualquier fallo de validación devuelve un bloqueo de lote con CERO escrituras y CERO
     *    propuestas ABORTADAS. Este método no llama a `abort()` por ningún camino: a diferencia de
     *    `apply()`, un lote bloqueado no quema nada - informar no cuesta una decisión humana.
     * 8. Cualquier excepción en la fase de escritura revierte la transacción entera. No existe una
     *    cola parcialmente APPLIED como desenlace aceptable, así que un desenlace inesperado de un
     *    escritor se convierte en excepción a propósito.
     * 9. El `apply()` de una sola propuesta sigue existiendo con su semántica intacta, para el uso
     *    legítimo de una sola propuesta. Lo único que cambió ahí es que ahora toma el lock común de
     *    ejecución (PARTE 5).
     *
     * NO ES UN SEGUNDO MOTOR DE REGLAS (PARTE 3). La validación de cada unidad es exactamente
     * `evaluateApplicability()`, la misma función que corren `apply()` y `preflight()`, con
     * `lockRows: true` y el baseline del lote. Lo único propio del lote es la validación de la FORMA
     * del conjunto (que nada falte, que nada sobre, que ningún grupo venga partido) y la traducción
     * del bloqueo de una propuesta al vocabulario de lote, que vive en un único `match`.
     *
     * @param  int[]  $proposalIds  El conjunto autorizado. El orden no importa: se normaliza y ordena.
     * @param  string  $authorizationReference  La autorización de ESTA ejecución (no vacía, con al
     *                menos un dígito) - mismo criterio que `apply()`. Nunca es "quién revisó".
     * @param  array|null  $manifest  Manifiesto de lote (`ReviewedProposalBatchManifest`) a verificar
     *                contra el estado vivo DENTRO de la transacción y con los locks ya tomados. Es el
     *                mecanismo de la PARTE 4: sin él un lote ejecuta "los ids que le pasaron", con él
     *                ejecuta "exactamente la cola que se autorizó o nada".
     * @param  string|null  $expectedManifestFingerprint  Re-audit `6011317053`, BLOQUEO 3: el
     *                fingerprint que el DUEÑO autorizó, provisto como un insumo de confianza SEPARADO
     *                del archivo. OBLIGATORIO cuando viene `$manifest`: sin él el manifiesto sólo
     *                prueba que no se editó, no que sea el que se autorizó. Nunca se deduce del propio
     *                manifiesto - eso colapsaría los dos insumos en uno.
     */
    public function applyBatch(array $proposalIds, string $authorizationReference, ?array $manifest = null, ?string $expectedManifestFingerprint = null): array
    {
        if (trim($authorizationReference) === '') {
            throw new \InvalidArgumentException('applyBatch() requiere $authorizationReference no vacío - la referencia de autorización de ESTA ejecución (distinta de quién revisó).');
        }

        if (! preg_match('/\d/', $authorizationReference)) {
            throw new \InvalidArgumentException('applyBatch() requiere que $authorizationReference sea una REFERENCIA (con al menos un dígito), no un nombre libre - mismo criterio que apply().');
        }

        // Auto-capturado, nunca provisto por quien llama - mismo criterio que `apply()` desde
        // TASK-0004. PARTE 8: un entorno no autorizado se rechaza ANTES de abrir la transacción.
        $targetEnvironment = app()->environment();

        $preTransactionBase = [
            'mode' => self::BATCH_MODE_EXECUTE,
            'authorization_reference' => $authorizationReference,
            'target_environment' => $targetEnvironment,
            'requested_proposal_ids' => self::normalisedBatchIds($proposalIds),
        ];

        if (! self::environmentCanExecuteBatch($targetEnvironment)) {
            return $this->batchResult(self::BATCH_RESULT_BLOCKED, self::BATCH_ENVIRONMENT_NOT_AUTHORIZED, [
                'note' => "El entorno auto-capturado ({$targetEnvironment}) no puede ejecutar un lote. El único entorno OPERATIVO autorizado para esta fase es staging; `local` conserva preview y generación de manifiestos (las dos son de solo lectura) pero no ejecución, `testing` es la excepción de tests automatizados, y production está prohibido.",
                'target_environment' => $targetEnvironment,
                'executable_environments' => self::BATCH_EXECUTABLE_ENVIRONMENTS,
                'fixture_test_environment' => self::BATCH_FIXTURE_TEST_ENVIRONMENT,
            ], $preTransactionBase);
        }

        // =====================================================================================
        // Re-audit 2 `6015273402`, BLOQUEO A: en un entorno OPERATIVO el manifiesto es OBLIGATORIO,
        // y la compuerta vive acá -en el único camino de escritura- y no en el CLI.
        //
        // La excepción de `testing` se conserva deliberadamente: ahí cada lote de fixture vive en una
        // transacción que nunca commitea, y exigir un manifiesto en cada test haría la suite más
        // ceremoniosa sin agregar ninguna garantía sobre datos reales. Es una excepción SÓLO DE TESTS,
        // y por eso se deriva de `BATCH_EXECUTABLE_ENVIRONMENTS` (la lista operativa) y no de
        // `environmentCanExecuteBatch()` (que incluye la excepción): si mañana se agrega otro entorno
        // operativo, hereda la exigencia sin que nadie tenga que acordarse.
        // =====================================================================================
        $isOperationalEnvironment = in_array($targetEnvironment, self::BATCH_EXECUTABLE_ENVIRONMENTS, true);

        if ($isOperationalEnvironment && $manifest === null) {
            return $this->batchResult(self::BATCH_RESULT_BLOCKED, self::BATCH_MANIFEST_REQUIRED, [
                'note' => "Un lote en el entorno operativo `{$targetEnvironment}` exige un manifiesto autorizado y el fingerprint que el dueño autorizó. Sin manifiesto no hay conjunto atómico atado ni atadura a una autorización humana, así que no se abre transacción ni se toma ningún lock. Cero escrituras.",
                'target_environment' => $targetEnvironment,
                'executable_environments' => self::BATCH_EXECUTABLE_ENVIRONMENTS,
                'fixture_test_environment_note' => 'La excepción sin manifiesto existe SÓLO para el entorno `'.self::BATCH_FIXTURE_TEST_ENVIRONMENT.'`, donde cada lote de fixture corre dentro de una transacción que nunca commitea.',
            ], $preTransactionBase);
        }

        // Re-audit `6011317053`, BLOQUEO 3: la compuerta de autorización corre ANTES de la transacción
        // y antes de cualquier confirmación - un hash que no es el autorizado no debe ni abrir una
        // transacción, mucho menos tomar locks. Desde el re-audit 2, en un entorno operativo el
        // manifiesto ya está garantizado no nulo por la compuerta de arriba, así que esta compuerta
        // SIEMPRE corre ahí; el `if` sólo deja pasar los lotes de fixture sin manifiesto de `testing`.
        if ($manifest !== null) {
            $fingerprintBlocker = self::authorizationFingerprintBlocker($manifest, $expectedManifestFingerprint);

            if ($fingerprintBlocker !== null) {
                return $this->batchResult(self::BATCH_RESULT_BLOCKED, $fingerprintBlocker['blocker'], $fingerprintBlocker['detail'], $preTransactionBase);
            }
        }

        // Lectura SIN lock, usada EXCLUSIVAMENTE para elegir las claves de serialización antes de
        // tomar cualquier lock de fila - mismo patrón y misma justificación que `apply()`:
        // `proposal_group_id` se escribe al insertar y nunca se actualiza, así que no puede cambiar
        // entre esta lectura y el lock, y no se decide NADA con este valor.
        $groupIds = $this->requestedGroupIds($proposalIds);

        return DB::connection('pgsql')->transaction(function () use ($proposalIds, $authorizationReference, $targetEnvironment, $groupIds, $manifest, $expectedManifestFingerprint) {
            // ORDEN DE LOCKS (PARTE 5), idéntico al de `apply()` y determinístico:
            // 1) lock común de ejecución C2 -> 2) advisory locks de grupo ordenados -> 3) filas de
            // propuesta ordenadas por id -> 4) filas fuente, en el orden de las unidades.
            self::acquireExecutionAdvisoryLock();

            foreach ($groupIds as $groupId) {
                self::acquireGroupAdvisoryLock($groupId);
            }

            $evaluation = $this->evaluateBatch($proposalIds, lockRows: true, manifest: $manifest);

            $base = [
                'mode' => self::BATCH_MODE_EXECUTE,
                'authorization_reference' => $authorizationReference,
                'target_environment' => $targetEnvironment,
                'baseline_taxonomy_fingerprint' => $evaluation['baseline_taxonomy_fingerprint'],
                'requested_proposal_ids' => $evaluation['requested_proposal_ids'],
                'accepted_proposal_ids' => $evaluation['accepted_proposal_ids'],
                'execution_unit_count' => count($evaluation['units']),
                'manifest_verification' => $evaluation['manifest_verification'],
                // Re-audit `6011317053`, BLOQUEO 3: queda en el resultado -y por lo tanto en el
                // artefacto de auditoría- CUÁL fingerprint se exigió, no sólo cuál traía el archivo.
                'expected_manifest_fingerprint' => $expectedManifestFingerprint,
            ];

            if ($evaluation['blocker'] !== null) {
                // Requisito 7: CERO escrituras y CERO aborts. Se devuelve sin escribir una sola fila;
                // la transacción commitea vacía (no hay nada que revertir) y ninguna propuesta cambia
                // de estado. Un lote bloqueado NO es un lote que quema su contenido.
                return $this->batchResult(
                    $evaluation['already_executed'] ? self::BATCH_RESULT_ALREADY_EXECUTED : self::BATCH_RESULT_BLOCKED,
                    $evaluation['blocker'],
                    $evaluation['detail'],
                    $base,
                );
            }

            // =============================================================================
            // FASE DE ESCRITURA. A partir de acá NO queda ninguna validación por correr: la
            // cadena entera ya pasó para TODAS las unidades contra el mismo baseline, y las
            // filas fuente vienen leídas CON lock dentro del contexto de cada unidad. Los
            // escritores reciben ese contexto y no vuelven a decidir nada.
            // =============================================================================
            $unitOutcomes = [];
            $appliedIds = [];

            foreach ($evaluation['units'] as $unit) {
                /** @var TaxonomyReviewedProposal $entry */
                $entry = $unit['proposal'];

                $outcome = $entry->proposal_type === TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK
                    ? $this->writeCandidateLinkDecision($entry, $unit['context'], $authorizationReference, $targetEnvironment)
                    : $this->writeConceptRelationDecision($entry, $unit['context'], $authorizationReference, $targetEnvironment);

                // Requisito 8: una cola parcialmente aplicada no es un desenlace aceptable. Los
                // escritores sólo pueden devolver `APPLIED` (no contienen ninguna llamada a
                // `abort()` - verificado estructuralmente por test), así que cualquier otra cosa es
                // un invariante roto: se lanza y la transacción revierte el lote COMPLETO en vez de
                // dejar la mitad de la cola ejecutada.
                if ($outcome['result'] !== self::RESULT_APPLIED) {
                    throw new \LogicException("applyBatch(): la unidad de la propuesta #{$entry->id} devolvió {$outcome['result']} en la fase de escritura, cuando la validación del lote ya había pasado. Se revierte el lote completo - una cola parcialmente APPLIED no es un desenlace aceptable.");
                }

                $unitOutcomes[] = [
                    'kind' => $unit['kind'],
                    'entry_proposal_id' => (int) $entry->id,
                    'proposal_ids' => $unit['proposal_ids'],
                    'proposal_group_id' => $unit['proposal_group_id'],
                    'decision' => $entry->decision,
                    'result' => $outcome['result'],
                    'application_result' => $outcome['application_result'],
                ];

                $appliedIds = array_merge($appliedIds, $unit['proposal_ids']);
            }

            sort($appliedIds);

            return $this->batchResult(self::BATCH_RESULT_APPLIED, null, [], array_merge($base, [
                'units' => $unitOutcomes,
                'applied_proposal_ids' => $appliedIds,
                'applied_proposal_count' => count($appliedIds),
            ]));
        });
    }

    /**
     * TASK-0007, PARTE 6: el MISMO camino de validación del lote, sin entrar nunca a la fase de
     * escritura.
     *
     * Es a `applyBatch()` lo que `preflight()` es a `apply()`, y por el mismo motivo: la única forma
     * de saber si el lote se podría ejecutar hoy no puede ser intentarlo. La diferencia con
     * `preflightAll()` -que ya existía- no es cosmética: `preflightAll()` evalúa 12 propuestas por
     * separado, cada una contra el fingerprint del instante, y su write-set reporta el write-set
     * COMPLETO del grupo en CADA miembro del grupo (sumar las filas del JSON da de más). Este informe
     * evalúa el CONJUNTO: un baseline único, unidades de ejecución deduplicadas (el grupo cuenta UNA
     * vez) y una proyección agregada de las escrituras y de los conteos protegidos finales.
     *
     * GARANTÍA DE NO-EFECTOS, medida y no declarada: el método instala un listener de consultas y
     * reporta `write_statements_observed`. Si una refactorización futura introdujera un efecto, el
     * número deja de ser 0 y tanto el comando como el test fallan. No toma locks (ni el de ejecución
     * ni los de grupo): un informe de diagnóstico que pudiera demorar una ejecución real ya sería un
     * efecto observable.
     */
    public function previewBatch(array $proposalIds, ?array $manifest = null): array
    {
        $writeStatements = [];
        $recording = true;
        DB::listen(function ($query) use (&$writeStatements, &$recording) {
            if ($recording && preg_match('/^\s*(insert|update|delete|truncate|alter|create|drop)\b/i', $query->sql)) {
                $writeStatements[] = $query->sql;
            }
        });

        try {
            $evaluation = $this->evaluateBatch($proposalIds, lockRows: false, manifest: $manifest);

            $units = [];
            $writes = [];
            foreach ($evaluation['units'] as $unit) {
                /** @var TaxonomyReviewedProposal $entry */
                $entry = $unit['proposal'];
                $unitBlocker = $unit['blocker'] ?? self::PREFLIGHT_READY_TO_APPLY;

                if ($unitBlocker === self::PREFLIGHT_READY_TO_APPLY) {
                    $writeSet = $this->expectedWriteSet($entry, $unitBlocker, $unit['context']);
                    $writes = array_merge($writes, $writeSet['expected_write_set']);
                } else {
                    // IMPORTANTE, y por eso no se reusa `expectedWriteSet()` acá: para una propuesta
                    // suelta, ese método describe correctamente que un `apply()` bloqueado ESCRIBE dos
                    // filas (status -> ABORTED + auditoría) y quema la decisión. En un LOTE eso sería
                    // falso: el requisito 7 es cero escrituras y cero ABORTs, así que un lote bloqueado
                    // deja la propuesta exactamente como estaba. Copiar el write-set del camino suelto
                    // acá haría que el informe contradijera el contrato que el lote cumple.
                    $writeSet = [
                        'expected_write_set' => [],
                        'expected_write_count' => 0,
                        'expected_write_set_note' => 'Cero escrituras: un lote bloqueado no escribe nada y NO aborta ninguna propuesta - a diferencia de un apply() de una sola propuesta, que sí la quemaría. Esta unidad queda exactamente como estaba.',
                    ];
                }

                $units[] = array_merge([
                    'kind' => $unit['kind'],
                    'entry_proposal_id' => (int) $entry->id,
                    'proposal_ids' => $unit['proposal_ids'],
                    'proposal_group_id' => $unit['proposal_group_id'],
                    'proposal_type' => $entry->proposal_type,
                    'decision' => $entry->decision,
                    'source_label' => self::preflightSourceLabel($entry),
                    'blocker' => $unitBlocker,
                    'batch_blocker' => $unitBlocker === self::PREFLIGHT_READY_TO_APPLY
                        ? null
                        : self::batchBlockerForProposalBlocker($unitBlocker),
                ], $this->preflightChecks($entry, $unitBlocker, $unit['context']), $writeSet);
            }

            $countsNow = self::protectedQueueShape();

            return [
                'mode' => self::BATCH_MODE_PREFLIGHT,
                'generated_at' => now()->format('Y-m-d H:i:s'),
                'task' => 'TASK-0007',
                'governance_reference' => 'Issue #2 comentario 5997693379',
                'target_environment' => app()->environment(),
                'environment_authorized_for_execution' => self::environmentCanExecuteBatch(app()->environment()),
                'manifest_fingerprint' => $evaluation['manifest_fingerprint'],
                'manifest_verification' => $evaluation['manifest_verification'],
                'baseline_taxonomy_fingerprint' => $evaluation['baseline_taxonomy_fingerprint'],
                // El mismo valor que el baseline, y se reporta por separado a propósito: el
                // comentario pide los dos, y en un informe de lote "baseline" y "actual" son
                // conceptos distintos aunque hoy coincidan - si alguna vez difirieran, el lote
                // estaría bloqueado por BATCH_BASELINE_STALE y el lector tiene que poder verlo.
                'current_taxonomy_fingerprint' => $evaluation['baseline_taxonomy_fingerprint'],
                'requested_proposal_ids' => $evaluation['requested_proposal_ids'],
                'accepted_proposal_ids' => $evaluation['accepted_proposal_ids'],
                'accepted_proposal_count' => count($evaluation['accepted_proposal_ids']),
                'execution_unit_count' => count($units),
                'execution_units' => $units,
                'blocker' => $evaluation['blocker'],
                'blockers' => $evaluation['blocker'] === null ? [] : [[
                    'blocker' => $evaluation['blocker'],
                    'detail' => $evaluation['detail'],
                ]],
                'detail' => $evaluation['detail'],
                'already_executed' => $evaluation['already_executed'],
                'projected_write_set' => self::aggregateWriteSet($writes),
                'projected_write_count' => array_sum(array_column($writes, 'rows')),
                'protected_counts_now' => $countsNow,
                'projected_protected_counts' => $evaluation['blocker'] === null
                    ? $this->projectedProtectedCounts($evaluation['units'], $writes, $countsNow)
                    : null,
                'write_statements_observed' => count($writeStatements),
                'write_statements' => array_slice($writeStatements, 0, 10),
                'read_only_contract' => 'Este informe no escribió nada: no abre transacción de escritura, no toma locks, no llama a apply() ni a applyBatch() por ningún camino, y mide sus propios statements. Un lote sin bloqueos significa «hoy nada impide ejecutarlo», NUNCA una autorización de ejecución - esa sigue siendo un acto humano separado.',
            ];
        } finally {
            $recording = false;
        }
    }

    /**
     * TASK-0007, PARTES 2/3/4: la validación del CONJUNTO. Una sola implementación, usada por
     * `applyBatch()` (con locks, antes de escribir) y por `previewBatch()` (sin locks, sin escribir).
     *
     * Las compuertas van en este orden y el orden importa: primero la FORMA del conjunto -que lo
     * pedido exista y esté ejecutable-, después la identidad autorizada (manifiesto), después la
     * integridad de los grupos, y recién entonces la validación propuesta por propuesta, que es la
     * cadena compartida de siempre. Validar aplicabilidad de un conjunto cuya forma todavía no se
     * sabe correcta daría diagnósticos sobre premisas falsas.
     *
     * @return array{blocker:?string, detail:array, baseline_taxonomy_fingerprint:string, requested_proposal_ids:int[], accepted_proposal_ids:int[], units:array, already_executed:bool, manifest_verification:?array, manifest_fingerprint:?string}
     */
    private function evaluateBatch(array $proposalIds, bool $lockRows, ?array $manifest = null): array
    {
        $requested = self::normalisedBatchIds($proposalIds);

        // El baseline se computa UNA sola vez por lote (requisito 2) y es el ÚNICO fingerprint contra
        // el que se valida obsolescencia de acá en adelante.
        $baseline = CanonicalConceptBuilderService::dryRunInputFingerprint();

        $empty = [
            'baseline_taxonomy_fingerprint' => $baseline,
            'requested_proposal_ids' => $requested,
            'accepted_proposal_ids' => [],
            'units' => [],
            'already_executed' => false,
            'manifest_verification' => null,
            'manifest_fingerprint' => $manifest === null ? null : ($manifest['manifest_fingerprint'] ?? null),
        ];

        if ($requested === []) {
            return array_merge($empty, [
                'blocker' => self::BATCH_EMPTY_REQUEST,
                'detail' => ['note' => 'No se pidió ninguna propuesta. Un lote vacío no es un lote ejecutado con éxito: no hay nada que validar ni que escribir.'],
            ]);
        }

        $proposals = TaxonomyReviewedProposal::query()
            ->whereIn('id', $requested)
            ->orderBy('id')
            ->when($lockRows, fn ($q) => $q->lockForUpdate())
            ->get();

        $found = $proposals->pluck('id')->map(fn ($id) => (int) $id)->all();
        $missing = array_values(array_diff($requested, $found));

        if ($missing !== []) {
            return array_merge($empty, [
                'blocker' => self::BATCH_QUEUE_DRIFT,
                'detail' => [
                    'note' => 'Alguna propuesta del conjunto pedido ya no existe. El lote no se ejecuta parcialmente: se bloquea completo y sin escribir nada.',
                    'missing_proposal_ids' => $missing,
                    'found_proposal_ids' => $found,
                ],
            ]);
        }

        $byStatus = [];
        foreach ($proposals as $proposal) {
            $byStatus[$proposal->status][] = (int) $proposal->id;
        }

        // Replay idempotente del MISMO lote ya ejecutado (requisito E de la PARTE 9): no es drift ni
        // un error, es que no queda nada por hacer. Cero escrituras, y ningún concepto/link/auditoría
        // duplicado - que es exactamente lo que garantiza que un reintento sea seguro.
        if (($byStatus[TaxonomyReviewedProposal::STATUS_APPLIED] ?? []) === $found) {
            return array_merge($empty, [
                'blocker' => self::BATCH_ALREADY_EXECUTED,
                'already_executed' => true,
                'detail' => [
                    'note' => 'Todas las propuestas del lote ya están APPLIED: este lote ya se ejecutó. Replay idempotente, cero escrituras nuevas, cero conceptos/links/auditoría duplicados.',
                    'applied_proposal_ids' => $found,
                ],
            ]);
        }

        $notPending = [];
        foreach ($proposals as $proposal) {
            if ($proposal->status !== TaxonomyReviewedProposal::STATUS_PENDING_APPLY) {
                $notPending[] = ['proposal_id' => (int) $proposal->id, 'status' => $proposal->status];
            }
        }

        if ($notPending !== []) {
            return array_merge($empty, [
                'blocker' => self::BATCH_QUEUE_DRIFT,
                'detail' => [
                    'note' => 'Alguna propuesta del conjunto pedido ya no está PENDING_APPLY, así que el conjunto no describe la cola ejecutable. Las filas SUPERSEDED son registro histórico ÍNTEGRO y no son entradas ejecutables por diseño (TASK-0006E); las APPLIED/ABORTED ya son terminales. Cero escrituras.',
                    'non_executable_proposals' => $notPending,
                    'status_breakdown' => array_map('count', $byStatus),
                ],
            ]);
        }

        // PARTE 4: identidad autorizada. Se verifica acá -con las filas YA bloqueadas por el lote
        // cuando corre desde `applyBatch()`- y no antes de tomar los locks: "la cola sigue siendo la
        // que se autorizó" sólo es una afirmación útil si se hace cuando nadie más puede ejecutar.
        $manifestVerification = null;
        if ($manifest !== null) {
            // Re-audit `6011317053`, BLOQUEO 1: el conjunto PEDIDO se le pasa a la verificación, que
            // exige igualdad exacta con el conjunto ATADO. Es parámetro obligatorio y no opcional a
            // propósito: con un valor por defecto, un llamador futuro podría omitirlo y volver a
            // habilitar en silencio la ejecución de un subconjunto de un manifiesto autorizado.
            $manifestVerification = ReviewedProposalBatchManifest::verify($manifest, $baseline, $requested);

            if (! $manifestVerification['ok']) {
                return array_merge($empty, [
                    'blocker' => $manifestVerification['blocker'],
                    // Un manifiesto cuyo conjunto atado ya está ejecutado es un REPLAY, no un fallo -
                    // el desenlace del lote tiene que decirlo igual que lo dice la compuerta de forma.
                    'already_executed' => $manifestVerification['blocker'] === self::BATCH_ALREADY_EXECUTED,
                    'detail' => array_merge($manifestVerification['detail'], [
                        'manifest_fingerprint' => $manifestVerification['manifest_fingerprint'],
                        'note' => 'El manifiesto autorizado no coincide con el estado vivo. El lote no se ejecuta: ejecutar "los ids que llegaron" en vez de "exactamente la cola autorizada" es justamente lo que esta compuerta impide. Cero escrituras.',
                    ]),
                    'manifest_verification' => $manifestVerification,
                ]);
            }
        }

        $empty['manifest_verification'] = $manifestVerification;

        // PARTE 2: integridad de grupo. Un grupo bilingüe es UNA unidad indivisible, así que pedir
        // medio grupo se rechaza en SOLO LECTURA en vez de ejecutar la mitad.
        $groupIncomplete = $this->incompleteBatchGroups($proposals, $requested, $lockRows);
        if ($groupIncomplete !== null) {
            return array_merge($empty, [
                'blocker' => self::BATCH_GROUP_INCOMPLETE,
                'detail' => $groupIncomplete,
            ]);
        }

        // PARTE 2: unidades de ejecución deduplicadas y determinísticas. 12 filas -> 11 unidades,
        // porque #629/#630 son un solo grupo. La entrada de un grupo es su miembro de id más bajo, no
        // "el que pidieron primero": así entrar por cualquiera de los dos produce exactamente la
        // misma ejecución.
        $units = [];
        $seenGroups = [];
        foreach ($proposals as $proposal) {
            if ($proposal->isGrouped()) {
                if (isset($seenGroups[$proposal->proposal_group_id])) {
                    continue;
                }
                $seenGroups[$proposal->proposal_group_id] = true;

                $memberIds = array_values(array_filter(
                    $proposals->where('proposal_group_id', $proposal->proposal_group_id)
                        ->pluck('id')->map(fn ($id) => (int) $id)->all()
                ));
                sort($memberIds);

                $units[] = [
                    'kind' => 'BILINGUAL_GROUP',
                    'proposal' => $proposal,
                    'proposal_ids' => $memberIds,
                    'proposal_group_id' => $proposal->proposal_group_id,
                ];

                continue;
            }

            $units[] = [
                'kind' => 'SINGLE_PROPOSAL',
                'proposal' => $proposal,
                'proposal_ids' => [(int) $proposal->id],
                'proposal_group_id' => null,
            ];
        }

        // PARTE 3: la validación por unidad es la cadena COMPARTIDA, con el baseline del lote. No hay
        // acá ninguna regla propia que pueda divergir de `apply()`.
        $blocker = null;
        $detail = [];
        foreach ($units as $index => $unit) {
            /** @var TaxonomyReviewedProposal $entry */
            $entry = $unit['proposal'];
            $evaluation = $this->evaluateApplicability($entry, $lockRows, $baseline);

            $units[$index]['context'] = $evaluation['context'];
            $units[$index]['blocker'] = $evaluation['blocker'];
            $units[$index]['detail'] = $evaluation['detail'];

            if ($evaluation['blocker'] !== null && $blocker === null) {
                $blocker = self::batchBlockerForProposalBlocker($evaluation['blocker']);
                $detail = array_merge($evaluation['detail'], [
                    // «The batch validation result must identify the exact proposal/unit that blocks
                    // execution and why»: el id que BLOQUEA, que en un grupo puede ser un hermano y
                    // no la entrada - por eso se lee el campo normalizado antes del fallback.
                    'blocking_proposal_id' => (int) ($evaluation['detail']['offending_proposal_id'] ?? $evaluation['detail']['tampered_proposal_id'] ?? $entry->id),
                    'blocking_unit_entry_proposal_id' => (int) $entry->id,
                    'blocking_unit_kind' => $unit['kind'],
                    'blocking_unit_proposal_ids' => $unit['proposal_ids'],
                    'proposal_blocker' => $evaluation['blocker'],
                    'batch_is_atomic_note' => 'Un solo bloqueo detiene el lote COMPLETO: cero escrituras, cero propuestas abortadas. Ninguna otra unidad se ejecuta "porque ella sí estaba bien".',
                ]);
            }
        }

        return [
            'blocker' => $blocker,
            'detail' => $detail,
            'baseline_taxonomy_fingerprint' => $baseline,
            'requested_proposal_ids' => $requested,
            'accepted_proposal_ids' => $found,
            'units' => $units,
            'already_executed' => false,
            'manifest_verification' => $manifestVerification,
            'manifest_fingerprint' => $manifestVerification['manifest_fingerprint'] ?? ($manifest['manifest_fingerprint'] ?? null),
        ];
    }

    /**
     * PARTE 2: ¿algún grupo bilingüe del conjunto viene PARTIDO? Se compara contra los miembros VIVOS
     * (`PENDING_APPLY`), no contra todos los históricos: un hermano `SUPERSEDED` o `ABORTED` no se va a
     * ejecutar, así que exigirlo bloquearía el lote por una fila que nadie va a aplicar - mismo
     * criterio que `unconfirmedMembers()` desde TASK-0006E.
     */
    private function incompleteBatchGroups(\Illuminate\Support\Collection $proposals, array $requested, bool $lockRows): ?array
    {
        foreach ($proposals->pluck('proposal_group_id')->filter()->unique() as $groupId) {
            $liveMembers = TaxonomyReviewedProposal::query()
                ->where('proposal_group_id', $groupId)
                ->where('status', TaxonomyReviewedProposal::STATUS_PENDING_APPLY)
                ->orderBy('id')
                ->when($lockRows, fn ($q) => $q->lockForUpdate())
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $absent = array_values(array_diff($liveMembers, $requested));

            if ($absent !== []) {
                return [
                    'note' => "El grupo bilingüe {$groupId} vendría PARTIDO en este lote: faltan miembros vivos del grupo. Un grupo describe UNA convergencia indivisible (un concepto, varios términos), así que ejecutar un subconjunto crearía el concepto con un solo término adjunto. Se rechaza en solo lectura, con cero escrituras.",
                    'proposal_group_id' => $groupId,
                    'live_group_member_ids' => $liveMembers,
                    'absent_from_batch_proposal_ids' => $absent,
                    'blocking_proposal_id' => $absent[0],
                ];
            }
        }

        return null;
    }

    /**
     * Ids de grupo distintos de las propuestas pedidas, ordenados por su CLAVE de advisory lock.
     *
     * El orden es por clave y no por uuid a propósito: lo que tiene que ser consistente entre dos
     * transacciones concurrentes es el orden en que se PIDEN los locks, y el lock se identifica por su
     * clave. Ordenar por otra cosa podría dar dos secuencias distintas para el mismo conjunto de
     * grupos. (Con el lock común de ejecución tomado antes, dos lotes ya no pueden competir acá; esto
     * es defensa en profundidad, no la garantía principal.)
     *
     * @return string[]
     */
    private function requestedGroupIds(array $proposalIds): array
    {
        $groupIds = TaxonomyReviewedProposal::query()
            ->whereIn('id', self::normalisedBatchIds($proposalIds))
            ->whereNotNull('proposal_group_id')
            ->distinct()
            ->pluck('proposal_group_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        usort($groupIds, fn (string $a, string $b) => self::groupAdvisoryLockKey($a) <=> self::groupAdvisoryLockKey($b));

        return $groupIds;
    }

    /** @return int[] Ids únicos, enteros y ordenados - para que un mismo conjunto se comporte igual sin importar cómo llegó. */
    public static function normalisedBatchIds(array $proposalIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $proposalIds)));
        $ids = array_values(array_filter($ids, fn (int $id) => $id > 0));
        sort($ids);

        return $ids;
    }

    /**
     * TASK-0007, PARTE 3/PARTE 4: ÚNICO punto donde un bloqueo de propuesta se traduce al vocabulario
     * de lote. Igual que `applyAbortReasonForBlocker()` en TASK-0006D, lanza en vez de inventar un
     * código si alguien agrega un bloqueo nuevo y se olvida de mapearlo - así el informe de un lote no
     * puede quedar diciendo menos de lo que la validación realmente encontró.
     *
     * Los tres estados ya resueltos caen en `BATCH_QUEUE_DRIFT` y no en `BATCH_ALREADY_EXECUTED`: ese
     * último está reservado para el lote ENTERO ya ejecutado (replay idempotente). Una propuesta ya
     * resuelta DENTRO de un lote que se pide como ejecutable significa que la cola cambió respecto de
     * lo autorizado, que es exactamente drift.
     */
    /**
     * TASK-0007 re-audit (Issue #2 comentario `6011317053`, BLOQUEO 3): compuerta de AUTORIZACIÓN.
     *
     * Son tres comprobaciones distintas y con nombres distintos a propósito, porque piden acciones
     * distintas: falta el dato (el operador no citó el hash autorizado), el dato no tiene forma de
     * sha256 (typo o copia truncada), o el dato no coincide con el manifiesto cargado (el archivo no es
     * el que se autorizó). Colapsarlas en un solo código haría que el operador no supiera qué
     * corregir, y la tercera es la única que es un hallazgo de gobernanza real.
     *
     * Se compara con `hash_equals()` y en minúsculas: la comparación no debería depender de cómo quedó
     * el copiado/pegado desde el comentario del Issue, y un hash no se compara con `===` cuando existe
     * la función que lo hace en tiempo constante.
     *
     * TASK-0007 re-audit 2 (Issue #2 comentario `6015273402`, BLOQUEO B): esta función es **la única**
     * validación de hash autorizado del sistema, y es PÚBLICA para que el CLI la llame ANTES de
     * imprimir la confirmación destructiva, en vez de tener su propia versión.
     *
     * EL DEFECTO QUE CIERRA: el CLI sólo comprobaba que la opción no estuviera vacía antes de mostrar
     * el banner y preguntar «¿Ejecutar el lote con esta autorización?»; las comprobaciones de FORMA y
     * de COINCIDENCIA vivían en `applyBatch()`, invocado DESPUÉS de esa confirmación. El servicio
     * validaba bien -antes de la transacción-, pero se le podía pedir a una persona que confirmara un
     * APPLY destructivo con un hash mal copiado o directamente equivocado, para rechazarlo recién
     * después. Eso no cumplía el orden de compuertas pedido, y el audit de la ronda 2 sobreafirmó al
     * decir que corría «antes de cualquier confirmación».
     *
     * La corrección NO duplica validadores: hay uno solo, lo llama el CLI antes de preguntar y lo
     * vuelve a llamar el servicio antes de la transacción, por defensa en profundidad. Es de SOLO
     * LECTURA -no toca la base, no escribe, no abre transacción- así que llamarla dos veces es
     * inofensivo, y que el servicio siga siendo la autoridad final es lo que hace que saltearse el CLI
     * no sirva de nada.
     *
     * @return array{blocker:string, detail:array}|null  `null` = la compuerta pasa.
     */
    public static function authorizationFingerprintBlocker(array $manifest, ?string $expectedManifestFingerprint): ?array
    {
        $manifestFingerprint = (string) ($manifest['manifest_fingerprint'] ?? '');

        if ($expectedManifestFingerprint === null || trim($expectedManifestFingerprint) === '') {
            return ['blocker' => self::BATCH_AUTHORIZATION_FINGERPRINT_MISSING, 'detail' => [
                'note' => 'Falta el fingerprint de manifiesto AUTORIZADO. Un manifiesto auto-hasheado sólo prueba que el archivo no se editó; para probar que es el que el dueño autorizó hace falta ese hash citado aparte, como segundo insumo de confianza. Cero escrituras.',
                'manifest_fingerprint_in_file' => $manifestFingerprint,
            ]];
        }

        $expected = strtolower(trim($expectedManifestFingerprint));

        if (preg_match('/^[0-9a-f]{64}$/', $expected) !== 1) {
            return ['blocker' => self::BATCH_AUTHORIZATION_FINGERPRINT_MALFORMED, 'detail' => [
                'note' => 'El fingerprint autorizado que se pasó no tiene forma de sha256 (64 dígitos hexadecimales). No se ejecutó nada.',
                'expected_manifest_fingerprint' => $expectedManifestFingerprint,
            ]];
        }

        if (! hash_equals(strtolower($manifestFingerprint), $expected)) {
            return ['blocker' => self::BATCH_AUTHORIZATION_FINGERPRINT_MISMATCH, 'detail' => [
                'note' => 'El manifiesto cargado NO es el que se autorizó: su fingerprint no coincide con el autorizado. Es exactamente el escenario que esta compuerta existe para detectar - un manifiesto internamente válido pero distinto del que cita la referencia de autorización. Cero escrituras.',
                'authorized_manifest_fingerprint' => $expected,
                'loaded_manifest_fingerprint' => $manifestFingerprint,
            ]];
        }

        return null;
    }

    public static function batchBlockerForProposalBlocker(string $blocker): string
    {
        return match ($blocker) {
            self::PREFLIGHT_TAMPER_DETECTED => self::BATCH_TAMPER_DETECTED,
            self::PREFLIGHT_STALE_TAXONOMY_STATE => self::BATCH_BASELINE_STALE,
            self::PREFLIGHT_ENTITY_MISSING => self::BATCH_ENTITY_MISSING,
            self::PREFLIGHT_SOURCE_DRIFT => self::BATCH_SOURCE_DRIFT,
            self::PREFLIGHT_SOURCE_ALREADY_RESOLVED => self::BATCH_SOURCE_ALREADY_RESOLVED,
            self::PREFLIGHT_HUMAN_CONFIRMATION_REQUIRED => self::BATCH_CONFIRMATION_REQUIRED,
            self::PREFLIGHT_GROUP_INCOMPLETE_OR_INCONSISTENT => self::BATCH_GROUP_INCOMPLETE,
            self::PREFLIGHT_RELATION_VALIDATION_FAILED => self::BATCH_RELATION_INVALID,
            self::PREFLIGHT_ALREADY_APPLIED,
            self::PREFLIGHT_ALREADY_ABORTED,
            self::PREFLIGHT_ALREADY_SUPERSEDED,
            self::PREFLIGHT_NOT_FOUND => self::BATCH_QUEUE_DRIFT,
            default => throw new \LogicException("Bloqueo de propuesta sin traducción de lote mapeada: {$blocker}. Agregalo a batchBlockerForProposalBlocker() en vez de dejar que el informe del lote invente un código."),
        };
    }

    private function batchResult(string $result, ?string $blocker, array $detail, array $base): array
    {
        return array_merge([
            'result' => $result,
            'blocker' => $blocker,
            'detail' => $detail,
            'units' => [],
            'applied_proposal_ids' => [],
            'applied_proposal_count' => 0,
        ], $base);
    }

    /**
     * Las escrituras de varias unidades, agrupadas por tabla+operación. Es lo que hace legible la
     * proyección de un lote: 40 filas en 8 líneas en vez de 40 líneas sueltas.
     */
    private static function aggregateWriteSet(array $writes): array
    {
        $byKey = [];
        foreach ($writes as $write) {
            $key = $write['table'].'|'.$write['operation'];
            if (! isset($byKey[$key])) {
                $byKey[$key] = ['table' => $write['table'], 'operation' => $write['operation'], 'rows' => 0, 'descriptions' => []];
            }
            $byKey[$key]['rows'] += (int) $write['rows'];
            $byKey[$key]['descriptions'][] = $write['description'];
        }

        ksort($byKey);

        return array_values($byKey);
    }

    /**
     * TASK-0007, PARTE 6: los conteos protegidos que el lote dejaría, derivados de la DECISIÓN
     * congelada de cada unidad y del write-set real - no de una tabla de resultados escrita a mano.
     *
     * `taxonomy_term_cpv_relations` aparece con delta CERO explícito y no por omisión: "TERM→CPV MUST
     * remain 9749" es un invariante del pedido, y un informe que simplemente no mencionara esa tabla
     * no sería evidencia de nada.
     */
    private function projectedProtectedCounts(array $units, array $writes, array $now): array
    {
        $delta = array_fill_keys(array_keys($now), 0);

        foreach ($writes as $write) {
            if ($write['table'] === 'taxonomy_canonical_concepts' && $write['operation'] === 'INSERT') {
                $delta['canonical_concepts'] += (int) $write['rows'];
            }
            if ($write['table'] === 'taxonomy_term_concepts' && $write['operation'] === 'INSERT') {
                $delta['term_concepts'] += (int) $write['rows'];
            }
        }

        foreach ($units as $unit) {
            /** @var TaxonomyReviewedProposal $entry */
            $entry = $unit['proposal'];
            $memberCount = count($unit['proposal_ids']);

            $delta['reviewed_proposals_pending_apply'] -= $memberCount;
            $delta['reviewed_proposals_applied'] += $memberCount;

            if ($entry->proposal_type === TaxonomyReviewedProposal::TYPE_CONCEPT_RELATION) {
                $delta['concept_relations_candidate'] -= 1;

                if ($entry->decision === TaxonomyReviewedProposal::DECISION_REJECT) {
                    $delta['concept_relations_rejected'] += 1;
                } else {
                    $delta['concept_relations_approved'] += 1;
                }

                continue;
            }

            $delta['candidate_links_pending'] -= $memberCount;

            if ($entry->decision === TaxonomyReviewedProposal::DECISION_REJECT) {
                $delta['candidate_links_rejected'] += $memberCount;
            } elseif ($entry->decision === TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED) {
                $delta['candidate_links_context_required'] += $memberCount;
            } else {
                // MAP_TO_EXISTING y CREATE_NEW (suelta o agrupada) son los dos caminos que PUBLICAN.
                $delta['candidate_links_published'] += $memberCount;
            }
        }

        $projected = [];
        foreach ($now as $key => $value) {
            $projected[$key] = $value + $delta[$key];
        }

        return [
            'counts' => $projected,
            'delta' => $delta,
            'term_cpv_invariant' => [
                'table' => 'taxonomy_term_cpv_relations',
                'before' => $now['term_cpv_relations'],
                'after' => $projected['term_cpv_relations'],
                'delta' => $delta['term_cpv_relations'],
                'note' => 'CERO escrituras TÉRMINO→CPV por construcción: ningún camino de escritura de este servicio toca esa tabla (ver writeCandidateLinkDecision/writeBilingualGroupCreateNew/writeConceptRelationDecision).',
            ],
        ];
    }

    /**
     * TASK-0007, PARTE 4/PARTE 6: la FORMA de la cola protegida, en un solo lugar.
     *
     * Lo usan el manifiesto (que la ata para poder rechazar una ejecución si cambió) y el informe de
     * lote (que proyecta cómo quedaría). Que sea la misma función para los dos es el punto: un
     * manifiesto que atara conteos calculados distinto de los que el informe proyecta no probaría
     * nada.
     */
    public static function protectedQueueShape(): array
    {
        $candidates = DB::connection('pgsql')->table('taxonomy_candidate_concept_links')
            ->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $relations = DB::connection('pgsql')->table('taxonomy_concept_relations')
            ->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $proposals = DB::connection('pgsql')->table('taxonomy_reviewed_proposals')
            ->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');

        $count = fn ($collection, string $key) => (int) ($collection[$key] ?? 0);

        return [
            'candidate_links' => (int) $candidates->sum(),
            'candidate_links_pending' => $count($candidates, TaxonomyCandidateConceptLink::STATUS_PENDING),
            'candidate_links_published' => $count($candidates, TaxonomyCandidateConceptLink::STATUS_PUBLISHED),
            'candidate_links_context_required' => $count($candidates, TaxonomyCandidateConceptLink::STATUS_CONTEXT_REQUIRED),
            'candidate_links_rejected' => $count($candidates, TaxonomyCandidateConceptLink::STATUS_REJECTED),
            'candidate_links_approved' => $count($candidates, TaxonomyCandidateConceptLink::STATUS_APPROVED),
            'concept_relations' => (int) $relations->sum(),
            'concept_relations_candidate' => $count($relations, TaxonomyConceptRelation::STATUS_CANDIDATE),
            'concept_relations_approved' => $count($relations, TaxonomyConceptRelation::STATUS_APPROVED),
            'concept_relations_rejected' => $count($relations, TaxonomyConceptRelation::STATUS_REJECTED),
            'canonical_concepts' => (int) DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count(),
            'term_concepts' => (int) DB::connection('pgsql')->table('taxonomy_term_concepts')->count(),
            'term_cpv_relations' => (int) DB::connection('pgsql')->table('taxonomy_term_cpv_relations')->count(),
            'reviewed_proposals' => (int) $proposals->sum(),
            'reviewed_proposals_pending_apply' => $count($proposals, TaxonomyReviewedProposal::STATUS_PENDING_APPLY),
            'reviewed_proposals_applied' => $count($proposals, TaxonomyReviewedProposal::STATUS_APPLIED),
            'reviewed_proposals_aborted' => $count($proposals, TaxonomyReviewedProposal::STATUS_ABORTED),
            'reviewed_proposals_superseded' => $count($proposals, TaxonomyReviewedProposal::STATUS_SUPERSEDED),
        ];
    }

    // =====================================================================================
    // TASK-0006D (Issue #2 comentario `5949253156`), PARTE 1: primitivas de validación COMPARTIDAS
    // entre `apply()` (que escribe después) y `preflight()` (que no escribe nunca).
    // =====================================================================================

    /**
     * Resultado "hay un bloqueo": el PRIMER impedimento encontrado, en el mismo orden en que
     * `apply()` siempre los evaluó. Se devuelve el primero y no se sigue buscando, a propósito -
     * reportar varios obligaría a seguir validando sobre premisas que ya no se cumplen (ej. buscar
     * drift de una fila fuente que no existe).
     */
    private function blocker(string $blocker, array $detail = [], array $context = []): array
    {
        return ['blocker' => $blocker, 'detail' => $detail, 'context' => $context];
    }

    /** Resultado "nada impide aplicar": el contexto trae las filas fuente ya resueltas. */
    private function applicable(array $context): array
    {
        return ['blocker' => null, 'detail' => [], 'context' => $context];
    }

    /**
     * TASK-0006D: la cadena de validación COMPLETA de `apply()`, sin una sola escritura.
     *
     * `$lockRows` es la ÚNICA diferencia entre los dos llamadores, y es deliberadamente una
     * diferencia de LECTURA, no de validación:
     * - `apply()` pasa `true`: las filas fuente se leen con `lockForUpdate()` dentro de su
     *   transacción, porque inmediatamente después las va a escribir y necesita que nadie las mueva
     *   en el medio;
     * - `preflight()` pasa `false`: lee sin locks y fuera de cualquier transacción de escritura,
     *   porque no va a escribir nada y no tiene por qué bloquear a un apply real que corra en
     *   paralelo.
     *
     * Consecuencia honesta de esa diferencia, dicha explícitamente en vez de dejarla implícita: el
     * resultado del preflight es una FOTO sin lock, así que es válido en el instante en que se tomó y
     * no reserva nada. Entre el preflight y un apply posterior el estado puede cambiar, y por eso
     * `apply()` revalida TODO otra vez con locks - el preflight no autoriza ni habilita nada, solo
     * informa. Un preflight que tomara locks sería peor: serializaría lecturas de diagnóstico contra
     * la ejecución real.
     *
     * «TODO» incluye, desde el re-audit `5952211890`, el `payload_fingerprint` de **cada miembro
     * pendiente** de un grupo bilingüe, no sólo el de la fila de entrada - ver
     * `evaluateBilingualGroup()`. Antes de esa corrección la afirmación era una sobre-afirmación para
     * el camino agrupado: `apply(#629)` podía escribir #630 sin revalidar el payload de #630, y el
     * hecho de que el preflight lo hubiera evaluado por separado no lo cubría, precisamente porque el
     * preflight es sólo una foto.
     *
     * TASK-0007 (Issue #2 comentario `5997693379`), PARTE 1 requisito 2 y PARTE 3: `$baselineFingerprint`
     * permite que un LOTE valide todas sus unidades contra UN MISMO fingerprint de baseline, computado
     * UNA sola vez antes de la primera escritura.
     *
     * Por qué hacía falta tocar esto y no se podía resolver en el lote: la compuerta de obsolescencia
     * computa `dryRunInputFingerprint()` en CADA llamada. Varias propuestas de la cola real mutan
     * justamente las entradas de ese fingerprint (#491 inserta un TERM→CONCEPT, #629/#630 crean un
     * concepto + dos links, #631/#632 tocan `taxonomy_concept_relations`), así que un lote que
     * revalidara entre miembros vería un fingerprint distinto después de la primera escritura y
     * declararía obsoletas propuestas legítimamente revisadas contra el MISMO snapshot - y, con el
     * camino de una sola propuesta, las habría ABORTADO de forma terminal. Ese es el hueco semántico
     * que abre TASK-0007.
     *
     * Con `null` -que es lo que pasan `apply()` y `preflight()`- el comportamiento es EXACTAMENTE el
     * anterior: se computa el fingerprint acá y la compuerta compara contra el estado real del
     * instante. No hay forma de usar este parámetro para relajar la compuerta: el lote computa su
     * baseline con la misma función, antes de escribir, y además verifica que el baseline siga siendo
     * el actual (`BATCH_BASELINE_STALE`). No existe ningún camino que permita pasar un fingerprint
     * arbitrario desde afuera del servicio.
     *
     * @param  bool  $lockRows  `true` solo desde `apply()`/`applyBatch()`.
     * @param  string|null  $baselineFingerprint  `null` = computar el actual (comportamiento de
     *                `apply()`/`preflight()`); un valor = el baseline único del lote.
     * @return array{blocker:?string, detail:array, context:array}
     */
    private function evaluateApplicability(TaxonomyReviewedProposal $proposal, bool $lockRows, ?string $baselineFingerprint = null): array
    {
        // 0) Estado ya resuelto: no es un hallazgo de validación, es que no hay nada que aplicar.
        if ($proposal->status === TaxonomyReviewedProposal::STATUS_APPLIED) {
            return $this->blocker(self::PREFLIGHT_ALREADY_APPLIED, [
                'note' => 'La propuesta ya fue aplicada - un segundo apply() es un replay idempotente sin ninguna escritura nueva.',
            ]);
        }

        if ($proposal->status === TaxonomyReviewedProposal::STATUS_ABORTED) {
            return $this->blocker(self::PREFLIGHT_ALREADY_ABORTED, [
                'note' => 'La propuesta ya fue abortada - abortar es terminal y este payload no se reintenta (se requiere una revisión nueva).',
                'abort_reason' => $proposal->application_result['abort_reason'] ?? null,
            ]);
        }

        // TASK-0006E (Issue #2 comentario `5955148859`, requisito «preflight understands SUPERSEDED as
        // terminal historical state, not READY_TO_APPLY»): estado histórico TERMINAL, y esta compuerta
        // no es decorativa - es lo que impide que `apply()` pise una supersesión.
        //
        // Sin ella, una propuesta `SUPERSEDED` no sería ni `APPLIED` ni `ABORTED`, así que caería por la
        // cadena de validación y terminaría ABORTADA: `apply()` escribiría encima del estado que la
        // supersesión acaba de registrar, destruyendo el rastro. Va acá arriba, junto a los otros dos
        // estados ya resueltos, y produce CERO escrituras.
        if ($proposal->status === TaxonomyReviewedProposal::STATUS_SUPERSEDED) {
            return $this->blocker(self::PREFLIGHT_ALREADY_SUPERSEDED, [
                'note' => 'La propuesta fue SUPERSEDIDA: se retiró de la cola de forma no destructiva por obsolescencia, su contenido sigue intacto y no es aplicable. El candidato volvió a la cola de revisión humana normal; la decisión nueva vive en una propuesta NUEVA, no en esta.',
                'superseded_at' => $proposal->superseded_at?->format('Y-m-d H:i:s'),
                'supersession_reference' => $proposal->supersession_reference,
                'superseded_by_proposal_id' => $proposal->superseded_by_proposal_id,
            ]);
        }

        // 1) Detección de manipulación: recomputa el fingerprint desde los campos de decisión TAL
        // CUAL quedaron guardados y lo compara contra el que se computó al congelar. Un UPDATE manual
        // de la fila (fuera de este servicio) rompe la igualdad.
        if (! self::payloadFingerprintIsValid($proposal)) {
            return $this->blocker(self::PREFLIGHT_TAMPER_DETECTED, [
                'note' => 'El payload congelado no coincide con su propio fingerprint - los campos de decisión fueron modificados después de freeze(). No se escribió nada.',
                'tampered_proposal_id' => (int) $proposal->id,
            ], ['entry_payload_valid' => false]);
        }

        // 2) Obsolescencia: el estado de la taxonomía cambió entre freeze() y apply()?
        // `entry_payload_valid` se registra como EVIDENCIA, no se deduce después del bloqueo: en un
        // grupo bilingüe el tamper puede estar en un HERMANO, y entonces el payload de ESTA fila es
        // perfectamente válido. Inferirlo del bloqueo diría lo contrario (ver `preflightChecks()`).
        $currentTaxonomyFingerprint = $baselineFingerprint ?? CanonicalConceptBuilderService::dryRunInputFingerprint();
        $context = [
            'current_taxonomy_fingerprint' => $currentTaxonomyFingerprint,
            // TASK-0007: el informe del lote necesita poder decir si el fingerprint con el que se
            // validó fue el del instante o el baseline compartido - sin eso, un lector no puede
            // distinguir "no estaba obsoleta" de "se validó contra un baseline".
            'baseline_fingerprint_supplied' => $baselineFingerprint !== null,
            'entry_payload_valid' => true,
        ];

        if ($currentTaxonomyFingerprint !== $proposal->taxonomy_state_fingerprint) {
            return $this->blocker(self::PREFLIGHT_STALE_TAXONOMY_STATE, [
                'note' => 'El estado de la taxonomía cambió entre freeze() y apply(). No se escribió nada. Se requiere una revisión nueva (un freeze() nuevo), no un reintento de este payload.',
                'expected_fingerprint' => $proposal->taxonomy_state_fingerprint,
                'current_fingerprint' => $currentTaxonomyFingerprint,
            ], $context);
        }

        // 3) TASK-0006B (Issue #2 comentario `5936206843`), sección A: compuerta de CONFIRMACIÓN
        // HUMANA, exigible y no decorativa.
        //
        // REGLA DE COMPATIBILIDAD EXACTA (requisito explícito de la sección A): el único predicado
        // consultado es `requires_human_confirmation`, que la migración creó con `DEFAULT FALSE`. Por
        // lo tanto:
        //   - toda propuesta congelada ANTES de TASK-0006B queda en FALSE y sigue siendo aplicable
        //     exactamente como antes - su procedencia de revisión original YA satisface el requisito
        //     de revisión humana (caso de #420/#421/#422/#491);
        //   - solo exigen confirmación las filas marcadas explícitamente: las que el backfill
        //     determinístico de la migración identificó como preparadas por el agente (#492-#495) y
        //     las que `freeze()` marque en adelante al declararse `ACTOR_AGENT`.
        // Ninguna propuesta humana histórica se vuelve inválida por esta compuerta.
        //
        // Va DESPUÉS de tamper y obsolescencia a propósito: esos dos son hallazgos de seguridad que
        // merecen quedar registrados como ABORTED, y deben ganarle a un simple "falta confirmar".
        $unconfirmed = $this->unconfirmedMembers($proposal);
        if ($unconfirmed !== []) {
            return $this->blocker(self::PREFLIGHT_HUMAN_CONFIRMATION_REQUIRED, [
                'note' => 'Esta propuesta exige confirmación humana explícita antes de poder aplicarse (fue preparada por un actor que no es el revisor humano). No se escribió nada y la propuesta sigue PENDING_APPLY.',
                'unconfirmed_proposal_ids' => $unconfirmed,
            ], $context);
        }

        // 4) Fila fuente: existencia, estado y drift de los campos congelados.
        return $proposal->proposal_type === TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK
            ? $this->evaluateCandidateLink($proposal, $lockRows, $context)
            : $this->evaluateConceptRelation($proposal, $lockRows, $context);
    }

    /**
     * TASK-0006D: la tamper-detection, extraída tal cual estaba dentro de `apply()`. Pública porque
     * es un diagnóstico útil por sí solo (el informe pre-APPLY reporta "payload válido sí/no" para
     * las 12 propuestas) y porque no decide nada: solo recomputa un hash y lo compara.
     *
     * La lista de 9 campos es FIJA y no incluye ninguna columna de confirmación/ejecución, así que
     * confirmar, anular una confirmación o aplicar no pueden invalidar el fingerprint de una
     * propuesta - ver el docblock de `confirm()`.
     */
    public static function payloadFingerprintIsValid(TaxonomyReviewedProposal $proposal): bool
    {
        $recomputed = self::computePayloadFingerprint([
            'proposal_type' => $proposal->proposal_type,
            'candidate_link_id' => $proposal->candidate_link_id,
            'concept_relation_id' => $proposal->concept_relation_id,
            'decision' => $proposal->decision,
            'decision_payload' => $proposal->decision_payload ?? [],
            'payload_version' => $proposal->payload_version,
            'taxonomy_state_fingerprint' => $proposal->taxonomy_state_fingerprint,
            'reviewer_id' => $proposal->reviewer_id,
            'reviewed_at' => $proposal->reviewed_at->format('Y-m-d H:i:s'),
        ]);

        return $recomputed === $proposal->payload_fingerprint;
    }

    /**
     * TASK-0006D: validación de un candidato término→concepto. Mismo orden exacto que tenía
     * `applyCandidateLinkDecision()` antes de la extracción - incluidas sus dos excepciones
     * deliberadas: REJECT no exige el snapshot de `term_id`, y el chequeo de `term_id` corre ANTES de
     * la rama CONTEXT_REQUIRED (ronda 4 de TASK-0004, defecto 2).
     */
    private function evaluateCandidateLink(TaxonomyReviewedProposal $proposal, bool $lockRows, array $context): array
    {
        $candidate = TaxonomyCandidateConceptLink::query()
            ->when($lockRows, fn ($q) => $q->lockForUpdate())
            ->find($proposal->candidate_link_id);

        if (! $candidate) {
            return $this->blocker(self::PREFLIGHT_ENTITY_MISSING, [
                'note' => "El candidato referenciado (id={$proposal->candidate_link_id}) ya no existe.",
            ], $context);
        }

        $context['candidate'] = $candidate;

        // Re-verificación server-side (hallazgo 3 de TASK-0004): "reviewed" no implica que nadie
        // más resolvió este candidato mientras tanto por otra vía (el camino inmediato existente,
        // otro payload, tinker, etc.).
        if ($candidate->status !== TaxonomyCandidateConceptLink::STATUS_PENDING) {
            return $this->blocker(self::PREFLIGHT_SOURCE_ALREADY_RESOLVED, [
                'note' => "El candidato ya no está pending (status actual: {$candidate->status}) - alguien más lo resolvió entre freeze() y apply().",
                'current_source_status' => $candidate->status,
            ], $context);
        }

        $decisionPayload = $proposal->decision_payload ?? [];

        if ($proposal->decision === TaxonomyReviewedProposal::DECISION_REJECT) {
            // REJECT no publica nada determinado por un campo fuente congelado - no hay nada que
            // pueda "driftear" hacia una publicación incorrecta, así que no se exige el snapshot de
            // term_id acá (freeze() igual lo guarda, pero apply() no depende de él para este caso).
            return $this->applicable($context);
        }

        // TASK-0004, re-audit HIGH-1: re-verifica que el campo fuente congelado (term_id) siga
        // coincidiendo con la fila VIVA antes de publicar nada - si alguien editó el candidato
        // después de freeze() (directamente en la base, no hay UI para esto hoy, pero el guard no
        // depende de que exista una UI), el payload ya no describe lo que un humano revisó.
        //
        // TASK-0004, re-audit ronda 4 (Issue #2 comentario `5909267134`, defecto 2): este chequeo
        // corre ANTES de la rama CONTEXT_REQUIRED (antes corría después, y esa rama retornaba
        // temprano sin pasar por acá). CONTEXT_REQUIRED es una decisión semántica SOBRE un término
        // particular - si `suggested_term_id` cambió desde freeze(), aplicar la decisión "necesita
        // contexto" al candidato mutado resolvería un término DISTINTO del que el humano revisó, lo
        // cual viola la misma regla de inmutabilidad que ya protegía a MAP_TO_EXISTING/CREATE_NEW.
        // REJECT sigue siendo la única excepción deliberada (no determina NINGÚN destino de
        // escritura ni resuelve semánticamente un término específico).
        $frozenTermId = $decisionPayload['term_id'] ?? null;
        if ($frozenTermId === null || (int) $frozenTermId !== (int) $candidate->suggested_term_id) {
            return $this->blocker(self::PREFLIGHT_SOURCE_DRIFT, [
                'note' => 'suggested_term_id del candidato cambió desde freeze() - el payload congelado ya no describe la fila real. Se requiere una revisión nueva.',
                'frozen_term_id' => $frozenTermId,
                'current_term_id' => $candidate->suggested_term_id,
            ], $context);
        }

        $context['frozen_term_id'] = (int) $frozenTermId;

        if ($proposal->decision === TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED) {
            return $this->applicable($context);
        }

        if ($proposal->decision === TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING) {
            $targetConceptId = $decisionPayload['target_concept_id'] ?? null;

            if (! $targetConceptId || ! TaxonomyCanonicalConcept::query()->whereKey($targetConceptId)->exists()) {
                return $this->blocker(self::PREFLIGHT_ENTITY_MISSING, [
                    'note' => "El concepto destino (id={$targetConceptId}) del payload congelado ya no existe.",
                    'frozen_target_concept_id' => $targetConceptId,
                ], $context);
            }

            $context['target_concept_id'] = (int) $targetConceptId;

            return $this->applicable($context);
        }

        // DECISION_CREATE_NEW - TASK-0004, re-audit ronda 4 (Issue #2 comentario `5909267134`,
        // defecto 1): DOS valores distintos del payload congelado, con roles distintos - nunca se
        // mezclan:
        // - `new_concept_name`: el valor REVISADO/elegido explícitamente por el humano en freeze()
        //   (hallazgo HIGH-1/corrección A) - esto, y SOLO esto, es lo que se publica.
        // - `source_suggested_new_concept_name`: la sugerencia del Builder congelada en el instante
        //   de freeze() - esto, y SOLO esto, es lo que se compara contra la fila VIVA para detectar
        //   drift de la fuente. Antes de esa corrección, `apply()` comparaba el nombre REVISADO
        //   contra la sugerencia VIVA, lo cual hacía imposible una corrección/normalización legítima
        //   del revisor (Builder sugiere "X", humano aprueba explícitamente "Y" -> abortaba tratando
        //   "Y != X" como si "X" hubiera cambiado, cuando en realidad nunca cambió).
        $frozenSourceSuggestedName = $decisionPayload['source_suggested_new_concept_name'] ?? null;
        if ($frozenSourceSuggestedName === null || $frozenSourceSuggestedName !== $candidate->suggested_new_concept_name) {
            return $this->blocker(self::PREFLIGHT_SOURCE_DRIFT, [
                'note' => 'suggested_new_concept_name del candidato cambió desde freeze() - el payload congelado ya no describe la fila real. Se requiere una revisión nueva.',
                'frozen_source_suggested_new_concept_name' => $frozenSourceSuggestedName,
                'current_new_concept_name' => $candidate->suggested_new_concept_name,
            ], $context);
        }

        // TASK-0006B (Issue #2 comentario `5936206843`), sección C: identidad BILINGÜE explícita
        // (ES + EN congeladas) o identidad MONOLINGÜE histórica (`new_concept_name`). Las dos
        // conviven; la compatibilidad hacia atrás es explícita y está cubierta por test: un payload
        // congelado antes de TASK-0006B no trae las claves ES/EN, cae por el camino de siempre y se
        // publica exactamente igual que antes.
        $frozenBilingual = self::frozenBilingualNames($decisionPayload);

        $frozenNewConceptName = null;
        if ($frozenBilingual === null) {
            $frozenNewConceptName = $decisionPayload['new_concept_name'] ?? null;
            if ($frozenNewConceptName === null || trim((string) $frozenNewConceptName) === '') {
                return $this->blocker(self::PREFLIGHT_SOURCE_DRIFT, [
                    'note' => 'El payload congelado no trae un new_concept_name revisado válido - no se puede publicar.',
                ], $context);
            }
        }

        $context['frozen_bilingual'] = $frozenBilingual;
        $context['frozen_new_concept_name'] = $frozenNewConceptName === null ? null : (string) $frozenNewConceptName;

        // TASK-0006B, sección D: convergencia bilingüe gobernada - UN concepto para VARIOS
        // candidatos, en una sola transacción.
        if ($proposal->isGrouped()) {
            return $this->evaluateBilingualGroup($proposal, $frozenBilingual, $lockRows, $context);
        }

        return $this->applicable($context);
    }

    /**
     * TASK-0006D: validación del grupo bilingüe COMPLETO, extraída de
     * `applyBilingualGroupCreateNew()` sin cambiarle el orden ni los desenlaces.
     *
     * El advisory lock del grupo se toma SOLO cuando `$lockRows` es `true`, o sea solo desde
     * `apply()`. Que `preflight()` no lo tome es parte del contrato de no-efectos: tomar un lock de
     * grupo para una lectura de diagnóstico haría que un preflight pudiera DEMORAR un apply real, y
     * eso ya sería un efecto observable - justo lo que esta parte de la tarea prohíbe.
     */
    private function evaluateBilingualGroup(TaxonomyReviewedProposal $proposal, ?array $frozenBilingual, bool $lockRows, array $context): array
    {
        if ($frozenBilingual === null) {
            return $this->blocker(self::PREFLIGHT_GROUP_INCOMPLETE_OR_INCONSISTENT, [
                'note' => 'Una propuesta agrupada exige identidad bilingüe ES/EN explícita en el payload congelado y este payload no la trae.',
            ], $context);
        }

        if ($lockRows) {
            // TASK-0006B re-audit (comentario `5938949812`): defensa en profundidad. `apply()` ya lo
            // tomó antes de cualquier lock de fila; re-tomarlo acá es inofensivo (los advisory locks
            // son re-entrantes dentro de la misma transacción y se liberan una sola vez al
            // terminarla) y garantiza que este camino quede serializado por grupo incluso si alguna
            // vez se lo alcanza por otra vía. No depende de que el llamador se haya acordado.
            self::acquireGroupAdvisoryLock($proposal->proposal_group_id);
        }

        $members = TaxonomyReviewedProposal::query()
            ->where('proposal_group_id', $proposal->proposal_group_id)
            ->orderBy('id')
            ->when($lockRows, fn ($q) => $q->lockForUpdate())
            ->get();

        $context['group_members'] = $members;
        $context['group_member_ids'] = $members->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($members->count() < 2) {
            return $this->blocker(self::PREFLIGHT_GROUP_INCOMPLETE_OR_INCONSISTENT, [
                'note' => 'El grupo bilingüe quedó con menos de 2 miembros - no describe una convergencia válida.',
                'proposal_group_id' => $proposal->proposal_group_id,
            ], $context);
        }

        // Reutiliza el concepto si el grupo ya se aplicó (idempotencia), en vez de crear otro.
        $alreadyAppliedConceptId = null;
        foreach ($members as $member) {
            if ($member->status === TaxonomyReviewedProposal::STATUS_APPLIED) {
                $alreadyAppliedConceptId = $member->application_result['concept_id'] ?? null;
            }
        }

        $pending = [];
        // TASK-0006D re-audit (Issue #2 comentario `5952211890`, BLOCKER): integridad del payload
        // MIEMBRO POR MIEMBRO. `null` = todavía no se evaluó (una compuerta anterior cortó), nunca
        // "válido" - misma convención de honestidad que el resto del informe.
        $memberPayloadValid = [];
        foreach ($members as $member) {
            if ($member->status === TaxonomyReviewedProposal::STATUS_APPLIED) {
                continue;
            }

            $memberPayloadValid[(string) $member->id] = null;
        }
        $context['group_member_payload_valid'] = $memberPayloadValid;

        foreach ($members as $member) {
            if ($member->status === TaxonomyReviewedProposal::STATUS_APPLIED) {
                continue;
            }

            // ========================================================================
            // TASK-0006D re-audit (Issue #2 comentario `5952211890`, BLOCKER — «GROUPED APPLY DOES
            // NOT REVALIDATE EVERY MEMBER'S IMMUTABLE PAYLOAD»): PRIMERA compuerta de cada miembro
            // pendiente, antes de leer UN SOLO campo de su payload o de su fila fuente.
            //
            // EL DEFECTO QUE CIERRA, aceptado sin reservas: `evaluateApplicability()` valida el
            // `payload_fingerprint` SOLO de la propuesta de entrada. Este bucle validaba del hermano
            // su status, el fingerprint de estado, el drift de fuente y la identidad bilingüe - pero
            // NUNCA su propio fingerprint de payload. Y `writeBilingualGroupCreateNew()` publica y
            // marca `APPLIED` a TODOS los hermanos pendientes. Es decir: `apply(#629)` podía escribir
            // #630 sin revalidar el payload inmutable de #630 en el momento de la ejecución.
            //
            // Que `preflightAll()` hubiera evaluado #630 por separado NO cerraba el hueco: el
            // preflight es explícitamente una FOTO sin lock, y `apply()` tiene que revalidar bajo lock
            // todo lo que está por escribir. El defecto venía del diseño original del grupo
            // (TASK-0006B, sección D) y la extracción de TASK-0006D lo heredó sin corregirlo; además
            // el audit afirmaba que `apply()` «revalida TODO otra vez con locks», lo cual era una
            // sobre-afirmación. Las dos cosas se corrigen acá y en el audit.
            //
            // Va PRIMERO a propósito, y no es un detalle: `decision`, `proposal_type` y
            // `taxonomy_state_fingerprint` -que las compuertas de abajo consultan- son TRES de los 9
            // campos cubiertos por el fingerprint. Validarlas antes de comprobar que no fueron
            // manipuladas sería decidir sobre datos de los que todavía no se sabe si son los que un
            // humano revisó. Mismo orden que para la propuesta de entrada: tamper primero.
            // ========================================================================
            if (! self::payloadFingerprintIsValid($member)) {
                $memberPayloadValid[(string) $member->id] = false;
                $context['group_member_payload_valid'] = $memberPayloadValid;

                return $this->blocker(self::PREFLIGHT_TAMPER_DETECTED, [
                    'note' => "El payload congelado del miembro #{$member->id} del grupo bilingüe no coincide con su propio fingerprint - sus campos de decisión fueron modificados después de freeze(). No se escribió nada, y el grupo COMPLETO queda bloqueado: aplicar los hermanos sanos crearía el concepto con un solo término adjunto.",
                    'tampered_proposal_id' => (int) $member->id,
                    // TASK-0006E requisito 10: el mismo id, además, en el campo NORMALIZADO que
                    // `abortWholeGroup()` lee para todos los bloqueos de hermano.
                    'offending_proposal_id' => (int) $member->id,
                    'entry_proposal_id' => (int) $proposal->id,
                    'proposal_group_id' => $proposal->proposal_group_id,
                ], $context);
            }

            $memberPayloadValid[(string) $member->id] = true;
            $context['group_member_payload_valid'] = $memberPayloadValid;

            if ($member->status !== TaxonomyReviewedProposal::STATUS_PENDING_APPLY
                || $member->decision !== TaxonomyReviewedProposal::DECISION_CREATE_NEW
                || $member->proposal_type !== TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK) {
                return $this->blocker(self::PREFLIGHT_GROUP_INCOMPLETE_OR_INCONSISTENT, [
                    'note' => "Un miembro del grupo bilingüe no es aplicable (propuesta #{$member->id}, status={$member->status}, decision={$member->decision}).",
                ], $context);
            }

            if ($member->taxonomy_state_fingerprint !== $proposal->taxonomy_state_fingerprint) {
                return $this->blocker(self::PREFLIGHT_STALE_TAXONOMY_STATE, [
                    'note' => "Los miembros del grupo bilingüe no comparten el mismo fingerprint de estado de taxonomía (propuesta #{$member->id}) - el grupo no se congeló como una sola revisión coherente.",
                ], $context);
            }

            $memberPayload = $member->decision_payload ?? [];
            $memberCandidate = TaxonomyCandidateConceptLink::query()
                ->when($lockRows, fn ($q) => $q->lockForUpdate())
                ->find($member->candidate_link_id);

            if (! $memberCandidate) {
                return $this->blocker(self::PREFLIGHT_ENTITY_MISSING, [
                    'note' => "El candidato (id={$member->candidate_link_id}) de un miembro del grupo bilingüe ya no existe.",
                ], $context);
            }

            if ($memberCandidate->status !== TaxonomyCandidateConceptLink::STATUS_PENDING) {
                return $this->blocker(self::PREFLIGHT_SOURCE_ALREADY_RESOLVED, [
                    'note' => "El candidato #{$memberCandidate->id} del grupo bilingüe ya no está pending (status actual: {$memberCandidate->status}).",
                    'current_source_status' => $memberCandidate->status,
                ], $context);
            }

            // Drift de fuente POR MIEMBRO - requisito explícito de la sección D ("stale/source drift
            // is checked for BOTH source candidates").
            if ((int) ($memberPayload['term_id'] ?? 0) !== (int) $memberCandidate->suggested_term_id) {
                return $this->blocker(self::PREFLIGHT_SOURCE_DRIFT, [
                    'note' => "suggested_term_id del candidato #{$memberCandidate->id} (miembro del grupo bilingüe) cambió desde freeze().",
                    'frozen_term_id' => $memberPayload['term_id'] ?? null,
                    'current_term_id' => $memberCandidate->suggested_term_id,
                ], $context);
            }

            if (($memberPayload['source_suggested_new_concept_name'] ?? null) !== $memberCandidate->suggested_new_concept_name) {
                return $this->blocker(self::PREFLIGHT_SOURCE_DRIFT, [
                    'note' => "suggested_new_concept_name del candidato #{$memberCandidate->id} (miembro del grupo bilingüe) cambió desde freeze().",
                ], $context);
            }

            // Las DOS identidades congeladas tienen que coincidir entre miembros: si difieren, el
            // grupo no describe un único concepto.
            $memberBilingual = self::frozenBilingualNames($memberPayload);
            if ($memberBilingual !== $frozenBilingual) {
                return $this->blocker(self::PREFLIGHT_GROUP_INCOMPLETE_OR_INCONSISTENT, [
                    'note' => "La identidad bilingüe congelada del miembro #{$member->id} no coincide con la del resto del grupo - no se puede converger en un solo concepto.",
                ], $context);
            }

            $pending[] = ['proposal' => $member, 'candidate' => $memberCandidate];
        }

        $context['group_reuse_concept_id'] = $alreadyAppliedConceptId === null ? null : (int) $alreadyAppliedConceptId;
        $context['group_pending'] = $pending;

        if ($pending === []) {
            return $this->blocker(self::PREFLIGHT_ALREADY_APPLIED, [
                'note' => 'Todos los miembros del grupo bilingüe ya están aplicados - no queda nada por aplicar.',
            ], $context);
        }

        return $this->applicable($context);
    }

    /**
     * TASK-0006D: validación de una relación concepto→concepto, extraída de
     * `applyConceptRelationDecision()` con el mismo orden: existencia, estado, (REJECT corta acá),
     * drift de los tres campos congelados, y recién entonces la re-validación server-side completa.
     */
    private function evaluateConceptRelation(TaxonomyReviewedProposal $proposal, bool $lockRows, array $context): array
    {
        $relation = TaxonomyConceptRelation::query()
            ->when($lockRows, fn ($q) => $q->lockForUpdate())
            ->find($proposal->concept_relation_id);

        if (! $relation) {
            return $this->blocker(self::PREFLIGHT_ENTITY_MISSING, [
                'note' => "La relación referenciada (id={$proposal->concept_relation_id}) ya no existe.",
            ], $context);
        }

        $context['relation'] = $relation;

        if ($relation->status !== TaxonomyConceptRelation::STATUS_CANDIDATE) {
            return $this->blocker(self::PREFLIGHT_SOURCE_ALREADY_RESOLVED, [
                'note' => "La relación ya no está candidate (status actual: {$relation->status}) - alguien más la resolvió entre freeze() y apply().",
                'current_source_status' => $relation->status,
            ], $context);
        }

        if ($proposal->decision === TaxonomyReviewedProposal::DECISION_REJECT) {
            return $this->applicable($context);
        }

        // TASK-0004, re-audit HIGH-1: los campos fuente congelados (source/target/relation_type)
        // deben seguir coincidiendo con la fila VIVA - si drifearon desde freeze(), el payload ya no
        // describe lo que se revisó. Comparados ANTES de re-validar/publicar, no después.
        $decisionPayload = $proposal->decision_payload ?? [];
        $frozenSourceId = $decisionPayload['source_concept_id'] ?? null;
        $frozenTargetId = $decisionPayload['target_concept_id'] ?? null;
        $frozenRelationType = $decisionPayload['relation_type'] ?? null;

        if ((int) $frozenSourceId !== (int) $relation->source_concept_id
            || (int) $frozenTargetId !== (int) $relation->target_concept_id
            || $frozenRelationType !== $relation->relation_type) {
            return $this->blocker(self::PREFLIGHT_SOURCE_DRIFT, [
                'note' => 'source_concept_id/target_concept_id/relation_type de la relación cambiaron desde freeze() - el payload congelado ya no describe la fila real. Se requiere una revisión nueva.',
                'frozen' => ['source_concept_id' => $frozenSourceId, 'target_concept_id' => $frozenTargetId, 'relation_type' => $frozenRelationType],
                'current' => ['source_concept_id' => $relation->source_concept_id, 'target_concept_id' => $relation->target_concept_id, 'relation_type' => $relation->relation_type],
            ], $context);
        }

        $context['frozen_source_concept_id'] = (int) $frozenSourceId;
        $context['frozen_target_concept_id'] = (int) $frozenTargetId;
        $context['frozen_relation_type'] = $frozenRelationType;

        // DECISION_PUBLISH_RELATION: re-validación server-side completa contra el estado REAL
        // (duplicado exacto, simétrico, vía inverso, ciclos) - hallazgo 3 de TASK-0004, mismo
        // mecanismo que TASK-0003 hallazgo 6 (validateConceptRelationProposal con excludeId). Usa los
        // valores CONGELADOS (que ya se verificaron arriba como idénticos a los vivos), no relee la
        // fila viva de nuevo - "APPLY must write from the frozen payload".
        //
        // Es una validación de SOLO LECTURA (consultas sobre `taxonomy_concept_relations`), así que
        // el preflight la corre de verdad en vez de estimarla - uno de los puntos del informe
        // pre-APPLY es poder decir que la relación REALMENTE sigue siendo válida hoy.
        $validation = app(CanonicalConceptBuilderService::class)->validateConceptRelationProposal(
            (int) $frozenSourceId,
            (int) $frozenTargetId,
            $frozenRelationType,
            excludeId: $relation->id,
        );

        $context['relation_validation'] = $validation;

        if (! $validation['valid']) {
            return $this->blocker(self::PREFLIGHT_RELATION_VALIDATION_FAILED, [
                'note' => "La relación ya no es válida ({$validation['reason']}) - otra relación equivalente pudo haberse aprobado mientras esta esperaba aplicación.",
                'relation_validation' => $validation,
            ], $context);
        }

        return $this->applicable($context);
    }

    // =====================================================================================
    // PASO 2-bis: las ESCRITURAS de apply(), ya sin ninguna decisión de validación.
    // =====================================================================================

    private function writeCandidateLinkDecision(TaxonomyReviewedProposal $proposal, array $context, string $authorizationReference, string $targetEnvironment): array
    {
        /** @var TaxonomyCandidateConceptLink $candidate */
        $candidate = $context['candidate'];
        $decisionPayload = $proposal->decision_payload ?? [];

        if ($proposal->decision === TaxonomyReviewedProposal::DECISION_REJECT) {
            $candidate->update([
                'status' => TaxonomyCandidateConceptLink::STATUS_REJECTED,
                'reviewed_by' => $proposal->reviewer_id,
                'reviewed_at' => $proposal->reviewed_at,
                'review_notes' => $decisionPayload['notes'] ?? $candidate->review_notes,
            ]);

            return $this->markApplied($proposal, $authorizationReference, $targetEnvironment, [
                'candidate_link_id' => $candidate->id,
                'outcome' => 'REJECTED',
            ]);
        }

        $frozenTermId = $context['frozen_term_id'];

        if ($proposal->decision === TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED) {
            // TASK-0004, re-audit correction C (Issue #2 comentario `5892711739`) + ronda 4 defecto 2
            // (comentario `5909267134`): "generic but valid terms" - válido en la taxonomía pero
            // insuficientemente específico para un mapeo directo producto/servicio/CPV. Invariante
            // central: CERO escrituras a `taxonomy_term_concepts` y CERO conceptos nuevos - nunca
            // inventa una categoría específica. El motivo del revisor se preserva en `review_notes` -
            // `context_required` es un status DISTINTO de `rejected`: el candidato NO se descarta, y
            // el término/motivo queda preservado para un posible uso futuro como evidencia
            // contextual - sin que eso implique que algún consumidor de búsqueda lo lea hoy (ninguno
            // lo hace; ver auditoría de consumidores en el docblock de la clase). No se agrega ningún
            // consumidor nuevo en esta corrección - eso ampliaría el alcance de esta tarea e
            // invalidaría la regresión de búsqueda heredada.
            $candidate->update([
                'status' => TaxonomyCandidateConceptLink::STATUS_CONTEXT_REQUIRED,
                'reviewed_by' => $proposal->reviewer_id,
                'reviewed_at' => $proposal->reviewed_at,
                'review_notes' => $decisionPayload['context_reason'] ?? $candidate->review_notes,
            ]);

            return $this->markApplied($proposal, $authorizationReference, $targetEnvironment, [
                'candidate_link_id' => $candidate->id,
                'outcome' => 'CONTEXT_REQUIRED',
            ]);
        }

        if ($proposal->decision === TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING) {
            // La existencia del concepto destino ya la verificó `evaluateCandidateLink()`.
            $targetConceptId = $context['target_concept_id'];

            $termConceptId = $this->publishTermConceptLink((int) $frozenTermId, (int) $targetConceptId);

            self::withC2PublicationContext(fn () => $candidate->update([
                'status' => TaxonomyCandidateConceptLink::STATUS_PUBLISHED,
                'reviewed_by' => $proposal->reviewer_id,
                'reviewed_at' => $proposal->reviewed_at,
                'published_term_concept_id' => $termConceptId,
            ]));

            return $this->markApplied($proposal, $authorizationReference, $targetEnvironment, [
                'candidate_link_id' => $candidate->id,
                'concept_id' => (int) $targetConceptId,
                'term_concept_id' => $termConceptId,
                'outcome' => 'MAPPED_TO_EXISTING',
            ]);
        }

        // DECISION_CREATE_NEW. Los dos valores con roles distintos (el nombre REVISADO que se
        // publica y la SUGERENCIA del Builder que solo sirve para detectar drift) ya los separó y
        // validó `evaluateCandidateLink()` - ver su comentario y la ronda 4 de TASK-0004.
        $frozenBilingual = $context['frozen_bilingual'];
        $frozenNewConceptName = $context['frozen_new_concept_name'];

        // TASK-0006B, sección D: convergencia bilingüe gobernada - UN concepto para VARIOS
        // candidatos, en una sola transacción.
        if ($proposal->isGrouped()) {
            return $this->writeBilingualGroupCreateNew($proposal, $context, $authorizationReference, $targetEnvironment);
        }

        $term = $candidate->term;

        // La creación del concepto en sí no está guardada (`TaxonomyCanonicalConcept` tiene su
        // propio recurso de administración directa, sin relación con la revisión de candidatos - ver
        // audit/phase4_c2_immutable_apply.md) - solo la transición del CANDIDATO a `published` lo
        // está, así que el contexto se enciende recién para esa escritura puntual.
        $concept = TaxonomyCanonicalConcept::query()->create(
            self::conceptAttributesForCreateNew($frozenBilingual, $frozenNewConceptName, $term)
        );

        $termConceptId = $this->publishTermConceptLink((int) $frozenTermId, $concept->id);

        self::withC2PublicationContext(fn () => $candidate->update([
            'status' => TaxonomyCandidateConceptLink::STATUS_PUBLISHED,
            'reviewed_by' => $proposal->reviewer_id,
            'reviewed_at' => $proposal->reviewed_at,
            'published_term_concept_id' => $termConceptId,
        ]));

        return $this->markApplied($proposal, $authorizationReference, $targetEnvironment, [
            'candidate_link_id' => $candidate->id,
            'concept_id' => $concept->id,
            'term_concept_id' => $termConceptId,
            'outcome' => $frozenBilingual ? 'CREATED_NEW_BILINGUAL_CONCEPT' : 'CREATED_NEW_CONCEPT',
        ]);
    }

    /**
     * Nombres ES/EN del payload CONGELADO, o `null` si el payload es monolingüe (histórico). No
     * valida/normaliza nada: `freeze()` ya lo hizo, y `apply()` debe escribir el payload tal como se
     * revisó - "APPLY must write from the frozen payload".
     */
    private static function frozenBilingualNames(array $decisionPayload): ?array
    {
        $es = $decisionPayload['canonical_name_es'] ?? null;
        $en = $decisionPayload['canonical_name_en'] ?? null;

        if ($es === null || $en === null || trim((string) $es) === '' || trim((string) $en) === '') {
            return null;
        }

        return ['canonical_name_es' => (string) $es, 'canonical_name_en' => (string) $en];
    }

    /**
     * TASK-0006B, sección C. Dos caminos que NUNCA se mezclan:
     *
     * - BILINGÜE: las dos columnas se escriben desde el payload congelado, cada una con el nombre
     *   del idioma que le corresponde. Es lo que cierra el defecto del BLOQUEO 2 del re-audit
     *   `5934324928`: con una sola columna de nombre, un término `es` terminaba con la palabra
     *   INGLESA en `canonical_name_es`.
     * - MONOLINGÜE (histórico, byte por byte el comportamiento anterior): el nombre revisado va al
     *   campo del idioma del término y el otro queda NULL.
     */
    private static function conceptAttributesForCreateNew(?array $frozenBilingual, ?string $frozenNewConceptName, $term): array
    {
        if ($frozenBilingual !== null) {
            return [
                'canonical_name_es' => $frozenBilingual['canonical_name_es'],
                'canonical_name_en' => $frozenBilingual['canonical_name_en'],
                'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE,
            ];
        }

        return [
            'canonical_name_es' => $term?->language === 'en' ? null : ($frozenNewConceptName ?? $term?->canonical_term),
            'canonical_name_en' => $term?->language === 'en' ? ($frozenNewConceptName ?? $term?->canonical_term) : null,
            'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE,
        ];
    }

    /**
     * TASK-0006B (Issue #2 comentario `5936206843`), sección D: aplica el grupo bilingüe COMPLETO en
     * UNA transacción - un solo concepto canónico, las dos identidades de término adjuntas, cero
     * carreras de duplicado.
     *
     * Garantías, en el orden en que se obtienen:
     *
     * 1. SERIALIZACIÓN POR GRUPO SIN DEADLOCK POSIBLE (corrección del re-audit `5938949812`). El
     *    primer lock que toma la transacción es el ADVISORY LOCK DEL GRUPO
     *    (`acquireGroupAdvisoryLock()`), cuya clave se deriva del `proposal_group_id` y por lo tanto
     *    es IDÉNTICA para todos los hermanos - ver `groupAdvisoryLockKey()`. Recién después se toman
     *    los locks de fila (`lockForUpdate()` ordenado por id). Dos `apply()` concurrentes que entran
     *    por hermanos DISTINTOS ya no pueden quedarse cada uno con la fila que el otro necesita: se
     *    serializan en el advisory lock antes de tocar una sola fila, y el segundo entra cuando el
     *    primero ya commiteó, encuentra a todos los miembros en `APPLIED` y devuelve
     *    `ALREADY_APPLIED` sin crear un segundo concepto.
     *
     *    La versión anterior bloqueaba primero la fila de entrada y después el grupo, lo que permitía
     *    una espera circular. Postgres detectaba el deadlock y revertía una de las dos transacciones
     *    -así que nunca se publicaba de más-, pero "una de las dos peticiones muere con un error de
     *    deadlock" es más débil que el contrato de concurrencia pedido, y eso es lo que se corrigió.
     * 2. IDEMPOTENCIA REAL: si algún miembro ya está `APPLIED` con un `concept_id` registrado, se
     *    REUTILIZA ese concepto en vez de crear otro.
     * 3. DRIFT POR MIEMBRO: se revalida `term_id` y `source_suggested_new_concept_name` de CADA
     *    miembro contra su fila viva. Cualquier drift aborta el grupo entero.
     * 4. OBSOLESCENCIA POR MIEMBRO: todos los miembros tienen que compartir el mismo
     *    `taxonomy_state_fingerprint` que ya se validó contra el estado actual.
     * 5. CERO CPV: no se escribe ninguna relación TÉRMINO→CPV. La convergencia bilingüe no inventa
     *    mapeos de categoría (prohibición explícita de la sección D).
     *
     * TASK-0006D: las garantías 2/3/4 (y el advisory lock de la garantía 1) se validan en
     * `evaluateBilingualGroup()`, que es la misma función que corre el preflight. Este método recibe
     * el resultado ya validado -los miembros pendientes con sus candidatos leídos CON lock- y solo
     * escribe. La garantía 1 no se debilita: `apply()` toma el advisory lock del grupo como PRIMERA
     * acción de su transacción, antes de cualquier `lockForUpdate()`, y `evaluateBilingualGroup()` lo
     * re-toma por defensa en profundidad cuando corre con locks.
     */
    private function writeBilingualGroupCreateNew(
        TaxonomyReviewedProposal $proposal,
        array $context,
        string $authorizationReference,
        string $targetEnvironment,
    ): array {
        $frozenBilingual = $context['frozen_bilingual'];
        $pending = $context['group_pending'];
        $alreadyAppliedConceptId = $context['group_reuse_concept_id'];

        // UN SOLO concepto para todo el grupo.
        $conceptId = $alreadyAppliedConceptId
            ?? TaxonomyCanonicalConcept::query()->create(
                self::conceptAttributesForCreateNew($frozenBilingual, null, null)
            )->id;

        $applied = [];
        foreach ($pending as $entry) {
            $member = $entry['proposal'];
            $memberCandidate = $entry['candidate'];
            $termId = (int) ($member->decision_payload['term_id'] ?? 0);

            $termConceptId = $this->publishTermConceptLink($termId, (int) $conceptId);

            self::withC2PublicationContext(fn () => $memberCandidate->update([
                'status' => TaxonomyCandidateConceptLink::STATUS_PUBLISHED,
                'reviewed_by' => $member->reviewer_id,
                'reviewed_at' => $member->reviewed_at,
                'published_term_concept_id' => $termConceptId,
            ]));

            $applied[] = [
                'proposal_id' => $member->id,
                'candidate_link_id' => $memberCandidate->id,
                'term_id' => $termId,
                'term_concept_id' => $termConceptId,
            ];
        }

        // Marca APPLIED a TODOS los miembros pendientes dentro de la misma transacción, cada uno con
        // el mismo `concept_id` - así un `apply()` posterior de cualquier hermano devuelve
        // `ALREADY_APPLIED` y no puede crear un segundo concepto.
        $result = null;
        foreach ($pending as $entry) {
            $member = $entry['proposal'];
            $outcome = $this->markApplied($member, $authorizationReference, $targetEnvironment, [
                'candidate_link_id' => $entry['candidate']->id,
                'concept_id' => (int) $conceptId,
                'proposal_group_id' => $proposal->proposal_group_id,
                'grouped_applied_members' => $applied,
                'outcome' => 'CREATED_NEW_BILINGUAL_CONCEPT_FOR_GROUP',
            ]);

            if ($member->id === $proposal->id) {
                $result = $outcome;
            }
        }

        return $result ?? ['result' => self::RESULT_APPLIED, 'proposal' => $proposal->fresh(), 'abort_reason' => null, 'application_result' => ['concept_id' => (int) $conceptId, 'grouped_applied_members' => $applied]];
    }

    private function writeConceptRelationDecision(TaxonomyReviewedProposal $proposal, array $context, string $authorizationReference, string $targetEnvironment): array
    {
        /** @var TaxonomyConceptRelation $relation */
        $relation = $context['relation'];

        if ($proposal->decision === TaxonomyReviewedProposal::DECISION_REJECT) {
            $relation->update([
                'status' => TaxonomyConceptRelation::STATUS_REJECTED,
                'reviewed_by' => $proposal->reviewer_id,
                'reviewed_at' => $proposal->reviewed_at,
            ]);

            return $this->markApplied($proposal, $authorizationReference, $targetEnvironment, [
                'concept_relation_id' => $relation->id,
                'outcome' => 'REJECTED',
            ]);
        }

        // DECISION_PUBLISH_RELATION. El drift de los tres campos congelados y la re-validación
        // server-side completa (duplicado exacto, simétrico, vía inverso, ciclos) ya corrieron en
        // `evaluateConceptRelation()` - misma función que corre el preflight, mismo orden.
        //
        // `TaxonomyConceptRelation::booted()` (guard de TASK-0003 hallazgo 6, extendido en TASK-0004
        // hallazgo HIGH-2) revalida esto MISMO otra vez dentro de `save()` Y exige que
        // `isApplyingC2Publication()` esté encendida - redundante a propósito (defensa en
        // profundidad), no un desperdicio: vale para cualquier punto de entrada, no solo este
        // servicio.
        self::withC2PublicationContext(fn () => $relation->update([
            'status' => TaxonomyConceptRelation::STATUS_APPROVED,
            'reviewed_by' => $proposal->reviewer_id,
            'reviewed_at' => $proposal->reviewed_at,
        ]));

        return $this->markApplied($proposal, $authorizationReference, $targetEnvironment, [
            'concept_relation_id' => $relation->id,
            'outcome' => 'PUBLISHED_RELATION',
        ]);
    }

    /**
     * `taxonomy_term_concepts` no tiene columna `status` - su sola existencia ES la señal de
     * "publicado" (mismo criterio documentado en `CandidateConceptApprovalService`). Idempotente:
     * reutiliza el link si ya existe en vez de intentar otro INSERT que la
     * `UNIQUE(term_id, concept_id)` de esa tabla rechazaría.
     */
    private function publishTermConceptLink(int $termId, int $conceptId): int
    {
        $existing = DB::connection('pgsql')->table('taxonomy_term_concepts')
            ->where('term_id', $termId)
            ->where('concept_id', $conceptId)
            ->first();

        return $existing->id ?? DB::connection('pgsql')->table('taxonomy_term_concepts')->insertGetId([
            'term_id' => $termId,
            'concept_id' => $conceptId,
            'created_at' => now(),
        ]);
    }

    private function markApplied(TaxonomyReviewedProposal $proposal, string $authorizationReference, string $targetEnvironment, array $applicationResult): array
    {
        $proposal->update([
            'status' => TaxonomyReviewedProposal::STATUS_APPLIED,
            'applied_at' => now(),
            'authorization_reference' => $authorizationReference,
            'target_environment' => $targetEnvironment,
            'application_result' => $applicationResult,
        ]);

        // Auditoría de EJECUCIÓN: CON authorization_reference/target_environment - lo que la
        // distingue de la auditoría de revisión de freeze(). actor_type=system porque quien ejecuta
        // `apply()` puede ser un proceso/comando, no necesariamente el mismo humano que revisó.
        TaxonomyAuditLogger::record(
            entityType: TaxonomyReviewedProposal::class,
            entityId: $proposal->id,
            field: 'status',
            oldValue: TaxonomyReviewedProposal::STATUS_PENDING_APPLY,
            newValue: TaxonomyReviewedProposal::STATUS_APPLIED,
            reason: 'Payload revisado aplicado: '.json_encode($applicationResult),
            actorType: TaxonomyAuditLogger::ACTOR_SYSTEM,
            algorithmVersion: self::PAYLOAD_VERSION,
            authorizationReference: $authorizationReference,
            targetEnvironment: $targetEnvironment,
        );

        return ['result' => self::RESULT_APPLIED, 'proposal' => $proposal->fresh(), 'abort_reason' => null, 'application_result' => $applicationResult];
    }

    private function abort(TaxonomyReviewedProposal $proposal, string $abortReason, string $authorizationReference, string $targetEnvironment, array $extra = []): array
    {
        $applicationResult = array_merge(['abort_reason' => $abortReason], $extra);

        $proposal->update([
            'status' => TaxonomyReviewedProposal::STATUS_ABORTED,
            'authorization_reference' => $authorizationReference,
            'target_environment' => $targetEnvironment,
            'application_result' => $applicationResult,
        ]);

        // También auditado CON authorization_reference/target_environment: alguien SÍ intentó
        // ejecutar esto con autorización real - el hecho de que se haya rechazado es en sí mismo
        // parte de la auditoría ("reconstruir exactamente qué se... saltó, rechazó, o revirtió",
        // hallazgo 7 de TASK-0004), no un evento silencioso.
        TaxonomyAuditLogger::record(
            entityType: TaxonomyReviewedProposal::class,
            entityId: $proposal->id,
            field: 'status',
            oldValue: TaxonomyReviewedProposal::STATUS_PENDING_APPLY,
            newValue: TaxonomyReviewedProposal::STATUS_ABORTED,
            reason: "Intento de apply() abortado: {$abortReason}",
            actorType: TaxonomyAuditLogger::ACTOR_SYSTEM,
            algorithmVersion: self::PAYLOAD_VERSION,
            authorizationReference: $authorizationReference,
            targetEnvironment: $targetEnvironment,
        );

        return ['result' => self::RESULT_ABORTED, 'proposal' => $proposal->fresh(), 'abort_reason' => $abortReason, 'application_result' => $applicationResult];
    }

    /**
     * Fingerprint determinístico de los campos de decisión. `decision_payload` se ordena
     * recursivamente por clave antes de serializar - JSONB de Postgres no garantiza preservar el
     * orden de inserción, así que depender de la representación cruda de la columna sería frágil;
     * esto hace que el fingerprint sea estable sin importar cómo Postgres/PHP reordenen el JSON.
     */
    public static function computePayloadFingerprint(array $fields): string
    {
        ksort($fields);
        if (isset($fields['decision_payload']) && is_array($fields['decision_payload'])) {
            self::recursiveKsort($fields['decision_payload']);
        }

        return hash('sha256', json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function recursiveKsort(array &$array): void
    {
        ksort($array);
        foreach ($array as &$value) {
            if (is_array($value)) {
                self::recursiveKsort($value);
            }
        }
    }
}
