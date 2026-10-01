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
     * `applyCandidateLinkDecision()`/`applyConceptRelationDecision()` (uso real) como los tests que
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
            // `applyCandidateLinkDecision`).
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
     * Canal de ejecución real, auto-capturado. No es autorización ni pretende impedir que código
     * de la aplicación se haga pasar por un humano - dentro de un mismo proceso confiable eso no es
     * criptográficamente evitable, y afirmar lo contrario sería falso. Lo que SÍ garantiza es que el
     * canal quede registrado con la verdad: una confirmación hecha por consola queda marcada como
     * `console` y no puede presentarse como si hubiera venido de la UI.
     */
    private static function currentChannel(): string
    {
        return app()->runningInConsole()
            ? TaxonomyReviewedProposal::CHANNEL_CONSOLE
            : TaxonomyReviewedProposal::CHANNEL_HTTP;
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

        return DB::connection('pgsql')->transaction(function () use ($proposalId, $authorizationReference, $targetEnvironment) {
            $proposal = TaxonomyReviewedProposal::query()->lockForUpdate()->find($proposalId);

            if (! $proposal) {
                return ['result' => self::RESULT_NOT_FOUND, 'proposal' => null, 'abort_reason' => null, 'application_result' => null];
            }

            if ($proposal->status === TaxonomyReviewedProposal::STATUS_APPLIED) {
                // Replay idempotente: NINGUNA escritura nueva (ni mapeo, ni concepto, ni relación,
                // ni fila de auditoría) - hallazgo 4 de TASK-0004.
                return ['result' => self::RESULT_ALREADY_APPLIED, 'proposal' => $proposal, 'abort_reason' => null, 'application_result' => $proposal->application_result];
            }

            if ($proposal->status === TaxonomyReviewedProposal::STATUS_ABORTED) {
                return ['result' => self::RESULT_ALREADY_ABORTED, 'proposal' => $proposal, 'abort_reason' => $proposal->application_result['abort_reason'] ?? null, 'application_result' => $proposal->application_result];
            }

            // 1) Detección de manipulación: recomputa el fingerprint desde los campos de decisión
            // TAL CUAL quedaron guardados y lo compara contra el que se computó al congelar. Un
            // UPDATE manual de la fila (fuera de este servicio) rompe la igualdad.
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

            if ($recomputed !== $proposal->payload_fingerprint) {
                return $this->abort($proposal, self::ABORT_TAMPER_DETECTED, $authorizationReference, $targetEnvironment, [
                    'note' => 'El payload congelado no coincide con su propio fingerprint - los campos de decisión fueron modificados después de freeze(). No se escribió nada.',
                ]);
            }

            // 2) Obsolescencia: el estado de la taxonomía cambió entre freeze() y apply()?
            $currentTaxonomyFingerprint = CanonicalConceptBuilderService::dryRunInputFingerprint();
            if ($currentTaxonomyFingerprint !== $proposal->taxonomy_state_fingerprint) {
                return $this->abort($proposal, self::ABORT_STALE_TAXONOMY_STATE, $authorizationReference, $targetEnvironment, [
                    'note' => 'El estado de la taxonomía cambió entre freeze() y apply(). No se escribió nada. Se requiere una revisión nueva (un freeze() nuevo), no un reintento de este payload.',
                    'expected_fingerprint' => $proposal->taxonomy_state_fingerprint,
                    'current_fingerprint' => $currentTaxonomyFingerprint,
                ]);
            }

            // 3) TASK-0006B (Issue #2 comentario `5936206843`), sección A: compuerta de CONFIRMACIÓN
            // HUMANA, exigible y no decorativa.
            //
            // REGLA DE COMPATIBILIDAD EXACTA (requisito explícito de la sección A): el único
            // predicado consultado es `requires_human_confirmation`, que la migración creó con
            // `DEFAULT FALSE`. Por lo tanto:
            //   - toda propuesta congelada ANTES de TASK-0006B queda en FALSE y sigue siendo
            //     aplicable exactamente como antes - su procedencia de revisión original YA
            //     satisface el requisito de revisión humana (caso de #420/#421/#422/#491);
            //   - solo exigen confirmación las filas marcadas explícitamente: las que el backfill
            //     determinístico de la migración identificó como preparadas por el agente
            //     (#492-#495) y las que `freeze()` marque en adelante al declararse
            //     `ACTOR_AGENT`.
            // Ninguna propuesta humana histórica se vuelve inválida por esta compuerta.
            //
            // Va DESPUÉS de tamper y obsolescencia a propósito: esos dos son hallazgos de seguridad
            // que merecen quedar registrados como ABORTED, y deben ganarle a un simple "falta
            // confirmar".
            $unconfirmed = $this->unconfirmedMembers($proposal);
            if ($unconfirmed !== []) {
                // NO es un abort: ver el docblock de RESULT_HUMAN_CONFIRMATION_REQUIRED. Cero
                // escrituras, `status` intacto en PENDING_APPLY, la propuesta sigue aplicable una
                // vez confirmada.
                return [
                    'result' => self::RESULT_HUMAN_CONFIRMATION_REQUIRED,
                    'proposal' => $proposal,
                    'abort_reason' => null,
                    'application_result' => [
                        'note' => 'Esta propuesta exige confirmación humana explícita antes de poder aplicarse (fue preparada por un actor que no es el revisor humano). No se escribió nada y la propuesta sigue PENDING_APPLY.',
                        'unconfirmed_proposal_ids' => $unconfirmed,
                    ],
                ];
            }

            return $proposal->proposal_type === TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK
                ? $this->applyCandidateLinkDecision($proposal, $authorizationReference, $targetEnvironment)
                : $this->applyConceptRelationDecision($proposal, $authorizationReference, $targetEnvironment);
        });
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
            ->where('requires_human_confirmation', true)
            ->whereNull('confirmed_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function applyCandidateLinkDecision(TaxonomyReviewedProposal $proposal, string $authorizationReference, string $targetEnvironment): array
    {
        $candidate = TaxonomyCandidateConceptLink::query()->lockForUpdate()->find($proposal->candidate_link_id);

        if (! $candidate) {
            return $this->abort($proposal, self::ABORT_ENTITY_MISSING, $authorizationReference, $targetEnvironment, [
                'note' => "El candidato referenciado (id={$proposal->candidate_link_id}) ya no existe.",
            ]);
        }

        // Re-verificación server-side (hallazgo 3 de TASK-0004): "reviewed" no implica que nadie
        // más resolvió este candidato mientras tanto por otra vía (el camino inmediato existente,
        // otro payload, tinker, etc.).
        if ($candidate->status !== TaxonomyCandidateConceptLink::STATUS_PENDING) {
            return $this->abort($proposal, self::ABORT_CANDIDATE_ALREADY_RESOLVED, $authorizationReference, $targetEnvironment, [
                'note' => "El candidato ya no está pending (status actual: {$candidate->status}) - alguien más lo resolvió entre freeze() y apply().",
            ]);
        }

        $decisionPayload = $proposal->decision_payload ?? [];

        if ($proposal->decision === TaxonomyReviewedProposal::DECISION_REJECT) {
            // REJECT no publica nada determinado por un campo fuente congelado - no hay nada que
            // pueda "driftear" hacia una publicación incorrecta, así que no se exige el snapshot de
            // term_id acá (freeze() igual lo guarda, pero apply() no depende de él para este caso).
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

        // TASK-0004, re-audit HIGH-1: re-verifica que el campo fuente congelado (term_id) siga
        // coincidiendo con la fila VIVA antes de publicar nada - si alguien editó el candidato
        // después de freeze() (directamente en la base, no hay UI para esto hoy, pero el guard no
        // depende de que exista una UI), el payload ya no describe lo que un humano revisó.
        //
        // TASK-0004, re-audit ronda 4 (Issue #2 comentario `5909267134`, defecto 2): este chequeo
        // ahora corre ANTES de la rama CONTEXT_REQUIRED (antes corría después, y esa rama retornaba
        // temprano sin pasar por acá). CONTEXT_REQUIRED es una decisión semántica SOBRE un término
        // particular - si `suggested_term_id` cambió desde freeze(), aplicar la decisión "necesita
        // contexto" al candidato mutado resolvería un término DISTINTO del que el humano revisó, lo
        // cual viola la misma regla de inmutabilidad que ya protegía a MAP_TO_EXISTING/CREATE_NEW.
        // REJECT sigue siendo la única excepción deliberada (no determina NINGÚN destino de
        // escritura ni resuelve semánticamente un término específico).
        $frozenTermId = $decisionPayload['term_id'] ?? null;
        if ($frozenTermId === null || (int) $frozenTermId !== (int) $candidate->suggested_term_id) {
            return $this->abort($proposal, self::ABORT_SOURCE_DRIFT, $authorizationReference, $targetEnvironment, [
                'note' => 'suggested_term_id del candidato cambió desde freeze() - el payload congelado ya no describe la fila real. Se requiere una revisión nueva.',
                'frozen_term_id' => $frozenTermId,
                'current_term_id' => $candidate->suggested_term_id,
            ]);
        }

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
            $targetConceptId = $decisionPayload['target_concept_id'] ?? null;

            if (! $targetConceptId || ! TaxonomyCanonicalConcept::query()->whereKey($targetConceptId)->exists()) {
                return $this->abort($proposal, self::ABORT_ENTITY_MISSING, $authorizationReference, $targetEnvironment, [
                    'note' => "El concepto destino (id={$targetConceptId}) del payload congelado ya no existe.",
                ]);
            }

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

        // DECISION_CREATE_NEW - TASK-0004, re-audit ronda 4 (Issue #2 comentario `5909267134`,
        // defecto 1): DOS valores distintos del payload congelado, con roles distintos - nunca se
        // mezclan:
        // - `new_concept_name`: el valor REVISADO/elegido explícitamente por el humano en freeze()
        //   (hallazgo HIGH-1/corrección A) - esto, y SOLO esto, es lo que se publica abajo.
        // - `source_suggested_new_concept_name`: la sugerencia del Builder congelada en el instante
        //   de freeze() - esto, y SOLO esto, es lo que se compara contra la fila VIVA para detectar
        //   drift de la fuente. Antes de esta corrección, `apply()` comparaba el nombre REVISADO
        //   contra la sugerencia VIVA, lo cual hacía imposible una corrección/normalización legítima
        //   del revisor (Builder sugiere "X", humano aprueba explícitamente "Y" -> abortaba tratando
        //   "Y != X" como si "X" hubiera cambiado, cuando en realidad nunca cambió).
        $frozenSourceSuggestedName = $decisionPayload['source_suggested_new_concept_name'] ?? null;
        if ($frozenSourceSuggestedName === null || $frozenSourceSuggestedName !== $candidate->suggested_new_concept_name) {
            return $this->abort($proposal, self::ABORT_SOURCE_DRIFT, $authorizationReference, $targetEnvironment, [
                'note' => 'suggested_new_concept_name del candidato cambió desde freeze() - el payload congelado ya no describe la fila real. Se requiere una revisión nueva.',
                'frozen_source_suggested_new_concept_name' => $frozenSourceSuggestedName,
                'current_new_concept_name' => $candidate->suggested_new_concept_name,
            ]);
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
                return $this->abort($proposal, self::ABORT_SOURCE_DRIFT, $authorizationReference, $targetEnvironment, [
                    'note' => 'El payload congelado no trae un new_concept_name revisado válido - no se puede publicar.',
                ]);
            }
        }

        // TASK-0006B, sección D: convergencia bilingüe gobernada - UN concepto para VARIOS
        // candidatos, en una sola transacción.
        if ($proposal->isGrouped()) {
            return $this->applyBilingualGroupCreateNew($proposal, $frozenBilingual, $authorizationReference, $targetEnvironment);
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
     * 1. BLOQUEO DEL GRUPO ENTERO (`lockForUpdate()` ordenado por id). Dos `apply()` concurrentes
     *    sobre miembros distintos del mismo grupo se serializan: el segundo entra cuando el primero
     *    ya commiteó y encuentra a todos los miembros en `APPLIED`, así que devuelve
     *    `ALREADY_APPLIED` sin crear un segundo concepto. (El primer `lockForUpdate()` de `apply()`
     *    ya tomó una fila del grupo; si dos transacciones empiezan por miembros distintos, Postgres
     *    detecta el deadlock y revierte una de las dos - revertir es seguro por diseño, deja cero
     *    escrituras.)
     * 2. IDEMPOTENCIA REAL: si algún miembro ya está `APPLIED` con un `concept_id` registrado, se
     *    REUTILIZA ese concepto en vez de crear otro.
     * 3. DRIFT POR MIEMBRO: se revalida `term_id` y `source_suggested_new_concept_name` de CADA
     *    miembro contra su fila viva. Cualquier drift aborta el grupo entero.
     * 4. OBSOLESCENCIA POR MIEMBRO: todos los miembros tienen que compartir el mismo
     *    `taxonomy_state_fingerprint` que ya se validó contra el estado actual.
     * 5. CERO CPV: no se escribe ninguna relación TÉRMINO→CPV. La convergencia bilingüe no inventa
     *    mapeos de categoría (prohibición explícita de la sección D).
     */
    private function applyBilingualGroupCreateNew(
        TaxonomyReviewedProposal $proposal,
        ?array $frozenBilingual,
        string $authorizationReference,
        string $targetEnvironment,
    ): array {
        if ($frozenBilingual === null) {
            return $this->abort($proposal, self::ABORT_BILINGUAL_GROUP_NOT_APPLICABLE, $authorizationReference, $targetEnvironment, [
                'note' => 'Una propuesta agrupada exige identidad bilingüe ES/EN explícita en el payload congelado y este payload no la trae.',
            ]);
        }

        $members = TaxonomyReviewedProposal::query()
            ->where('proposal_group_id', $proposal->proposal_group_id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($members->count() < 2) {
            return $this->abort($proposal, self::ABORT_BILINGUAL_GROUP_NOT_APPLICABLE, $authorizationReference, $targetEnvironment, [
                'note' => 'El grupo bilingüe quedó con menos de 2 miembros - no describe una convergencia válida.',
                'proposal_group_id' => $proposal->proposal_group_id,
            ]);
        }

        // Reutiliza el concepto si el grupo ya se aplicó (idempotencia), en vez de crear otro.
        $alreadyAppliedConceptId = null;
        foreach ($members as $member) {
            if ($member->status === TaxonomyReviewedProposal::STATUS_APPLIED) {
                $alreadyAppliedConceptId = $member->application_result['concept_id'] ?? null;
            }
        }

        $pending = [];
        foreach ($members as $member) {
            if ($member->status === TaxonomyReviewedProposal::STATUS_APPLIED) {
                continue;
            }

            if ($member->status !== TaxonomyReviewedProposal::STATUS_PENDING_APPLY
                || $member->decision !== TaxonomyReviewedProposal::DECISION_CREATE_NEW
                || $member->proposal_type !== TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK) {
                return $this->abort($proposal, self::ABORT_BILINGUAL_GROUP_NOT_APPLICABLE, $authorizationReference, $targetEnvironment, [
                    'note' => "Un miembro del grupo bilingüe no es aplicable (propuesta #{$member->id}, status={$member->status}, decision={$member->decision}).",
                ]);
            }

            if ($member->taxonomy_state_fingerprint !== $proposal->taxonomy_state_fingerprint) {
                return $this->abort($proposal, self::ABORT_STALE_TAXONOMY_STATE, $authorizationReference, $targetEnvironment, [
                    'note' => "Los miembros del grupo bilingüe no comparten el mismo fingerprint de estado de taxonomía (propuesta #{$member->id}) - el grupo no se congeló como una sola revisión coherente.",
                ]);
            }

            $memberPayload = $member->decision_payload ?? [];
            $memberCandidate = TaxonomyCandidateConceptLink::query()->lockForUpdate()->find($member->candidate_link_id);

            if (! $memberCandidate) {
                return $this->abort($proposal, self::ABORT_ENTITY_MISSING, $authorizationReference, $targetEnvironment, [
                    'note' => "El candidato (id={$member->candidate_link_id}) de un miembro del grupo bilingüe ya no existe.",
                ]);
            }

            if ($memberCandidate->status !== TaxonomyCandidateConceptLink::STATUS_PENDING) {
                return $this->abort($proposal, self::ABORT_CANDIDATE_ALREADY_RESOLVED, $authorizationReference, $targetEnvironment, [
                    'note' => "El candidato #{$memberCandidate->id} del grupo bilingüe ya no está pending (status actual: {$memberCandidate->status}).",
                ]);
            }

            // Drift de fuente POR MIEMBRO - requisito explícito de la sección D ("stale/source drift
            // is checked for BOTH source candidates").
            if ((int) ($memberPayload['term_id'] ?? 0) !== (int) $memberCandidate->suggested_term_id) {
                return $this->abort($proposal, self::ABORT_SOURCE_DRIFT, $authorizationReference, $targetEnvironment, [
                    'note' => "suggested_term_id del candidato #{$memberCandidate->id} (miembro del grupo bilingüe) cambió desde freeze().",
                    'frozen_term_id' => $memberPayload['term_id'] ?? null,
                    'current_term_id' => $memberCandidate->suggested_term_id,
                ]);
            }

            if (($memberPayload['source_suggested_new_concept_name'] ?? null) !== $memberCandidate->suggested_new_concept_name) {
                return $this->abort($proposal, self::ABORT_SOURCE_DRIFT, $authorizationReference, $targetEnvironment, [
                    'note' => "suggested_new_concept_name del candidato #{$memberCandidate->id} (miembro del grupo bilingüe) cambió desde freeze().",
                ]);
            }

            // Las DOS identidades congeladas tienen que coincidir entre miembros: si difieren, el
            // grupo no describe un único concepto.
            $memberBilingual = self::frozenBilingualNames($memberPayload);
            if ($memberBilingual !== $frozenBilingual) {
                return $this->abort($proposal, self::ABORT_BILINGUAL_GROUP_NOT_APPLICABLE, $authorizationReference, $targetEnvironment, [
                    'note' => "La identidad bilingüe congelada del miembro #{$member->id} no coincide con la del resto del grupo - no se puede converger en un solo concepto.",
                ]);
            }

            $pending[] = ['proposal' => $member, 'candidate' => $memberCandidate];
        }

        if ($pending === []) {
            return ['result' => self::RESULT_ALREADY_APPLIED, 'proposal' => $proposal, 'abort_reason' => null, 'application_result' => $proposal->application_result];
        }

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

    private function applyConceptRelationDecision(TaxonomyReviewedProposal $proposal, string $authorizationReference, string $targetEnvironment): array
    {
        $relation = TaxonomyConceptRelation::query()->lockForUpdate()->find($proposal->concept_relation_id);

        if (! $relation) {
            return $this->abort($proposal, self::ABORT_ENTITY_MISSING, $authorizationReference, $targetEnvironment, [
                'note' => "La relación referenciada (id={$proposal->concept_relation_id}) ya no existe.",
            ]);
        }

        if ($relation->status !== TaxonomyConceptRelation::STATUS_CANDIDATE) {
            return $this->abort($proposal, self::ABORT_RELATION_ALREADY_RESOLVED, $authorizationReference, $targetEnvironment, [
                'note' => "La relación ya no está candidate (status actual: {$relation->status}) - alguien más la resolvió entre freeze() y apply().",
            ]);
        }

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
            return $this->abort($proposal, self::ABORT_SOURCE_DRIFT, $authorizationReference, $targetEnvironment, [
                'note' => 'source_concept_id/target_concept_id/relation_type de la relación cambiaron desde freeze() - el payload congelado ya no describe la fila real. Se requiere una revisión nueva.',
                'frozen' => ['source_concept_id' => $frozenSourceId, 'target_concept_id' => $frozenTargetId, 'relation_type' => $frozenRelationType],
                'current' => ['source_concept_id' => $relation->source_concept_id, 'target_concept_id' => $relation->target_concept_id, 'relation_type' => $relation->relation_type],
            ]);
        }

        // DECISION_PUBLISH_RELATION: re-validación server-side completa contra el estado REAL
        // (duplicado exacto, simétrico, vía inverso, ciclos) - hallazgo 3 de TASK-0004, mismo
        // mecanismo que TASK-0003 hallazgo 6 (validateConceptRelationProposal con excludeId). Usa
        // los valores CONGELADOS (que ya se verificaron arriba como idénticos a los vivos), no
        // relee la fila viva de nuevo - "APPLY must write from the frozen payload".
        $validation = app(CanonicalConceptBuilderService::class)->validateConceptRelationProposal(
            (int) $frozenSourceId,
            (int) $frozenTargetId,
            $frozenRelationType,
            excludeId: $relation->id,
        );

        if (! $validation['valid']) {
            return $this->abort($proposal, self::ABORT_RELATION_INVALID_AT_APPLY_TIME, $authorizationReference, $targetEnvironment, [
                'note' => "La relación ya no es válida ({$validation['reason']}) - otra relación equivalente pudo haberse aprobado mientras esta esperaba aplicación.",
            ]);
        }

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
