<?php

namespace App\Services\Taxonomy;

use App\Models\TaxonomyCandidateConceptLink;
use App\Models\User;
use App\Services\TaxonomyAuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3.1 (sección 9 del pedido): endurece la operación Approve/Reject de
 * `TaxonomyCandidateConceptLinkResource`, que hasta esta fase vivía inline en el Action del recurso
 * sin transacción, sin re-lectura con lock, y sin log de auditoría.
 *
 * Salvaguardas implementadas (auditoría de la sección 9, cada una mapeada a un test dedicado en
 * `CandidateConceptApprovalServiceTest`):
 * - **autorización re-verificada DENTRO del servicio** (no solo en el `visible()` del botón de
 *   Filament, que solo oculta la UI - nunca impide una llamada directa al servicio). Usa la MISMA
 *   policy (`TaxonomyCandidateConceptLinkPolicy::update`) que ya gobierna el recurso Filament, vía
 *   `Gate`/`$user->can()` - no un chequeo de rol paralelo.
 * - **status válido re-verificado DENTRO de la transacción** (no solo en el `visible()` del botón,
 *   que solo oculta la UI - no impide una segunda invocación ya en vuelo).
 * - **`lockForUpdate()`**: protección real contra doble click / doble request concurrente - una
 *   segunda transacción concurrente se serializa detrás de la primera y, al obtener el lock, ve
 *   `status` ya cambiado y devuelve `ALREADY_PROCESSED` en vez de duplicar.
 * - **transacción**: el INSERT en `taxonomy_term_concepts` y el UPDATE del candidato son atómicos -
 *   si cualquiera falla (ej. el concepto fue borrado entre tanto, viola la FK), NINGUNO de los dos
 *   persiste.
 * - **idempotencia real de la publicación**: si el link TÉRMINO->CONCEPTO ya existe (por cualquier
 *   vía), reutiliza su id en vez de intentar otro INSERT que la `UNIQUE(term_id, concept_id)` de
 *   `taxonomy_term_concepts` rechazaría con una excepción SQL cruda.
 * - **audit log**: reusa `TaxonomyAuditLogger` (TAXV2-9), el mecanismo YA establecido en este
 *   proyecto para decisiones de aprobar/rechazar - no se inventa un mecanismo paralelo.
 * - **reviewed_by/reviewed_at/published_term_concept_id**: se siguen poblando, ahora dentro de la
 *   misma transacción.
 */
class CandidateConceptApprovalService
{
    public const RESULT_APPROVED = 'APPROVED';

    public const RESULT_REJECTED = 'REJECTED';

    public const RESULT_ALREADY_PROCESSED = 'ALREADY_PROCESSED';

    public const RESULT_NOT_SUPPORTED = 'NOT_SUPPORTED_PROPOSE_NEW_CONCEPT';

    public const RESULT_NOT_FOUND = 'NOT_FOUND';

    public const RESULT_UNAUTHORIZED = 'UNAUTHORIZED';

    /** @return array{result:string, candidate:?TaxonomyCandidateConceptLink, term_concept_id:?int} */
    public function approve(int $candidateId, ?User $reviewer): array
    {
        return DB::connection('pgsql')->transaction(function () use ($candidateId, $reviewer) {
            $candidate = TaxonomyCandidateConceptLink::query()->lockForUpdate()->find($candidateId);

            if (! $candidate) {
                return ['result' => self::RESULT_NOT_FOUND, 'candidate' => null, 'term_concept_id' => null];
            }

            if (! $reviewer || ! $reviewer->can('update', $candidate)) {
                return ['result' => self::RESULT_UNAUTHORIZED, 'candidate' => $candidate, 'term_concept_id' => null];
            }

            if ($candidate->status !== TaxonomyCandidateConceptLink::STATUS_PENDING) {
                return ['result' => self::RESULT_ALREADY_PROCESSED, 'candidate' => $candidate, 'term_concept_id' => $candidate->published_term_concept_id];
            }

            if ($candidate->isProposingNewConcept()) {
                return ['result' => self::RESULT_NOT_SUPPORTED, 'candidate' => $candidate, 'term_concept_id' => null];
            }

            $existingLink = DB::connection('pgsql')->table('taxonomy_term_concepts')
                ->where('term_id', $candidate->suggested_term_id)
                ->where('concept_id', $candidate->suggested_concept_id)
                ->first();

            $termConceptId = $existingLink->id ?? DB::connection('pgsql')->table('taxonomy_term_concepts')->insertGetId([
                'term_id' => $candidate->suggested_term_id,
                'concept_id' => $candidate->suggested_concept_id,
                'created_at' => now(),
            ]);

            $previousStatus = $candidate->status;

            $candidate->update([
                'status' => TaxonomyCandidateConceptLink::STATUS_PUBLISHED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'published_term_concept_id' => $termConceptId,
            ]);

            TaxonomyAuditLogger::record(
                entityType: TaxonomyCandidateConceptLink::class,
                entityId: $candidate->id,
                field: 'status',
                oldValue: $previousStatus,
                newValue: $candidate->status,
                reason: "Aprobado -> taxonomy_term_concepts#{$termConceptId} (term_id={$candidate->suggested_term_id}, concept_id={$candidate->suggested_concept_id})",
            );

            return ['result' => self::RESULT_APPROVED, 'candidate' => $candidate, 'term_concept_id' => $termConceptId];
        });
    }

    /** @return array{result:string, candidate:?TaxonomyCandidateConceptLink} */
    public function reject(int $candidateId, ?User $reviewer, ?string $notes = null): array
    {
        return DB::connection('pgsql')->transaction(function () use ($candidateId, $reviewer, $notes) {
            $candidate = TaxonomyCandidateConceptLink::query()->lockForUpdate()->find($candidateId);

            if (! $candidate) {
                return ['result' => self::RESULT_NOT_FOUND, 'candidate' => null];
            }

            if (! $reviewer || ! $reviewer->can('update', $candidate)) {
                return ['result' => self::RESULT_UNAUTHORIZED, 'candidate' => $candidate];
            }

            if ($candidate->status !== TaxonomyCandidateConceptLink::STATUS_PENDING) {
                return ['result' => self::RESULT_ALREADY_PROCESSED, 'candidate' => $candidate];
            }

            $previousStatus = $candidate->status;

            $candidate->update([
                'status' => TaxonomyCandidateConceptLink::STATUS_REJECTED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_notes' => $notes ?? $candidate->review_notes,
            ]);

            TaxonomyAuditLogger::record(
                entityType: TaxonomyCandidateConceptLink::class,
                entityId: $candidate->id,
                field: 'status',
                oldValue: $previousStatus,
                newValue: $candidate->status,
                reason: $notes,
            );

            return ['result' => self::RESULT_REJECTED, 'candidate' => $candidate];
        });
    }
}
