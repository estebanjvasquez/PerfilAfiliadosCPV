<?php

namespace App\Services\Taxonomy;

use App\Models\TaxonomyCandidateConceptLink;
use App\Models\TaxonomyCanonicalConcept;
use App\Models\TaxonomyConceptRelation;
use App\Models\TaxonomyReviewedProposal;
use App\Models\User;
use App\Services\TaxonomyAuditLogger;
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
     * @return array{result:string, proposal:?TaxonomyReviewedProposal}
     */
    public function freeze(
        string $proposalType,
        int $targetId,
        string $decision,
        ?User $reviewer,
        array $decisionPayload = [],
    ): array {
        return match ($proposalType) {
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK => $this->freezeCandidateLink($targetId, $decision, $reviewer, $decisionPayload),
            TaxonomyReviewedProposal::TYPE_CONCEPT_RELATION => $this->freezeConceptRelation($targetId, $decision, $reviewer, $decisionPayload),
            default => throw new \InvalidArgumentException("proposal_type desconocido: {$proposalType}"),
        };
    }

    private function freezeCandidateLink(int $candidateId, string $decision, ?User $reviewer, array $decisionPayload): array
    {
        if (! in_array($decision, [
            TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING,
            TaxonomyReviewedProposal::DECISION_CREATE_NEW,
            TaxonomyReviewedProposal::DECISION_REJECT,
        ], true)) {
            throw new \InvalidArgumentException("Decisión no soportada para TERM_CONCEPT_LINK: {$decision}");
        }

        return DB::connection('pgsql')->transaction(function () use ($candidateId, $decision, $reviewer, $decisionPayload) {
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

            // TASK-0004, re-audit HIGH-1: congela TODOS los campos fuente decision-relevantes DENTRO
            // del payload, leídos UNA sola vez acá (bajo el lock, en el instante de la revisión) -
            // `apply()` nunca vuelve a leer `suggested_term_id`/`suggested_new_concept_name` de la
            // fila viva para decidir QUÉ escribir, solo para revalidar que no cambiaron (ver
            // `applyCandidateLinkDecision`). `new_concept_name` se resuelve acá también si el
            // llamador no lo pasó explícito - nunca se difiere esa resolución a apply().
            $snapshot = $decisionPayload;
            $snapshot['term_id'] = $candidate->suggested_term_id;
            if ($decision === TaxonomyReviewedProposal::DECISION_CREATE_NEW) {
                $snapshot['new_concept_name'] = $decisionPayload['new_concept_name'] ?? $candidate->suggested_new_concept_name;
            }

            return $this->insertFrozenProposal(
                proposalType: TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
                candidateLinkId: $candidateId,
                conceptRelationId: null,
                decision: $decision,
                reviewer: $reviewer,
                decisionPayload: $snapshot,
            );
        });
    }

    private function freezeConceptRelation(int $relationId, string $decision, ?User $reviewer, array $decisionPayload): array
    {
        if (! in_array($decision, [
            TaxonomyReviewedProposal::DECISION_PUBLISH_RELATION,
            TaxonomyReviewedProposal::DECISION_REJECT,
        ], true)) {
            throw new \InvalidArgumentException("Decisión no soportada para CONCEPT_RELATION: {$decision}");
        }

        return DB::connection('pgsql')->transaction(function () use ($relationId, $decision, $reviewer, $decisionPayload) {
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
            );
        });
    }

    private function insertFrozenProposal(
        string $proposalType,
        ?int $candidateLinkId,
        ?int $conceptRelationId,
        string $decision,
        User $reviewer,
        array $decisionPayload,
    ): array {
        $taxonomyStateFingerprint = CanonicalConceptBuilderService::dryRunInputFingerprint();
        $reviewedAt = now();

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
            reason: "Decisión de revisión congelada: {$decision}".($candidateLinkId ? " (candidate_link_id={$candidateLinkId})" : " (concept_relation_id={$conceptRelationId})"),
            actorType: TaxonomyAuditLogger::ACTOR_USER,
            algorithmVersion: self::PAYLOAD_VERSION,
        );

        return ['result' => self::RESULT_FROZEN, 'proposal' => $proposal];
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

            return $proposal->proposal_type === TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK
                ? $this->applyCandidateLinkDecision($proposal, $authorizationReference, $targetEnvironment)
                : $this->applyConceptRelationDecision($proposal, $authorizationReference, $targetEnvironment);
        });
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
        $frozenTermId = $decisionPayload['term_id'] ?? null;
        if ($frozenTermId === null || (int) $frozenTermId !== (int) $candidate->suggested_term_id) {
            return $this->abort($proposal, self::ABORT_SOURCE_DRIFT, $authorizationReference, $targetEnvironment, [
                'note' => 'suggested_term_id del candidato cambió desde freeze() - el payload congelado ya no describe la fila real. Se requiere una revisión nueva.',
                'frozen_term_id' => $frozenTermId,
                'current_term_id' => $candidate->suggested_term_id,
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

        // DECISION_CREATE_NEW - el nombre viene ÚNICAMENTE del payload congelado (nunca de
        // `$candidate->suggested_new_concept_name` en vivo - hallazgo HIGH-1: "CREATE_NEW must
        // require the reviewed new concept name explicitly in the frozen payload; do not fall back
        // at apply time to a mutable candidate field"). Si la fila viva cambió su nombre sugerido
        // desde freeze(), eso también es drift y debe abortar, no publicarse en silencio con el
        // valor viejo.
        $frozenNewConceptName = $decisionPayload['new_concept_name'] ?? null;
        if ($frozenNewConceptName === null || $frozenNewConceptName !== $candidate->suggested_new_concept_name) {
            return $this->abort($proposal, self::ABORT_SOURCE_DRIFT, $authorizationReference, $targetEnvironment, [
                'note' => 'suggested_new_concept_name del candidato cambió desde freeze() - el payload congelado ya no describe la fila real. Se requiere una revisión nueva.',
                'frozen_new_concept_name' => $frozenNewConceptName,
                'current_new_concept_name' => $candidate->suggested_new_concept_name,
            ]);
        }

        $term = $candidate->term;

        // La creación del concepto en sí no está guardada (`TaxonomyCanonicalConcept` tiene su
        // propio recurso de administración directa, sin relación con la revisión de candidatos - ver
        // audit/phase4_c2_immutable_apply.md) - solo la transición del CANDIDATO a `published` lo
        // está, así que el contexto se enciende recién para esa escritura puntual.
        $concept = TaxonomyCanonicalConcept::query()->create([
            'canonical_name_es' => $term?->language === 'en' ? null : ($frozenNewConceptName ?? $term?->canonical_term),
            'canonical_name_en' => $term?->language === 'en' ? ($frozenNewConceptName ?? $term?->canonical_term) : null,
            'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE,
        ]);

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
            'outcome' => 'CREATED_NEW_CONCEPT',
        ]);
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
