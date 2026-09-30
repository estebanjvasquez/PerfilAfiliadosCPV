<?php

namespace App\Models;

use App\Services\Taxonomy\ReviewedProposalService;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 3 (sección 7 del pedido): fila de la cola de revisión del Canonical Concept Builder - ver
 * docblock de la migración `create_taxonomy_candidate_concept_links_table`. Mismo espíritu que
 * `TaxonomyCandidateTerm` (TAXV2-12) pero para propuestas TÉRMINO->CONCEPTO.
 *
 * `suggested_concept_id === null` representa una propuesta de concepto NUEVO
 * (`suggested_new_concept_name` lleva el nombre sugerido) - ver `isProposingNewConcept()`.
 *
 * TASK-0004, re-audit HIGH-2 (Issue #2 comentario `5890113782`): `status -> published` es la
 * transición que CONSTITUYE "publicación" para esta fila (junto con la creación del link real en
 * `taxonomy_term_concepts`, que sucede en la misma transacción vía `ReviewedProposalService`) - ver
 * `booted()` abajo, que la bloquea fuera del `apply()` autorizado de Phase C2.
 */
class TaxonomyCandidateConceptLink extends Model
{
    protected $connection = 'pgsql';

    public const TIER_AUTO_ACCEPT = 'AUTO_ACCEPT';

    public const TIER_AUTO_ACCEPT_CONSERVATIVE = 'AUTO_ACCEPT_CONSERVATIVE';

    public const TIER_REVIEW = 'REVIEW';

    public const TIER_REJECT = 'REJECT';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PUBLISHED = 'published';

    /**
     * TASK-0004, re-audit correction C (Issue #2 comentario `5892711739`): resultado de la decisión
     * CONTEXT_REQUIRED de `ReviewedProposalService` - término/candidato válido pero insuficientemente
     * específico para un mapeo directo. Distinto de `STATUS_REJECTED` (el candidato NO se descarta;
     * el término/motivo queda preservado para un POSIBLE uso futuro como evidencia contextual, sin
     * que eso implique que algún consumidor de búsqueda lo lea hoy) y distinto de `STATUS_PUBLISHED`
     * (nunca se creó ningún link en `taxonomy_term_concepts` para esta fila).
     */
    public const STATUS_CONTEXT_REQUIRED = 'context_required';

    protected $fillable = [
        'suggested_term_id',
        'suggested_concept_id',
        'suggested_new_concept_name',
        'signals',
        'confidence',
        'tier',
        'status',
        'review_notes',
        'reviewed_by',
        'reviewed_at',
        'published_term_concept_id',
        'taxonomy_state_fingerprint',
    ];

    protected $casts = [
        'signals' => 'array',
        'confidence' => 'float',
        'reviewed_at' => 'datetime',
    ];

    public function term()
    {
        return $this->belongsTo(TaxonomyTerm::class, 'suggested_term_id');
    }

    public function concept()
    {
        return $this->belongsTo(TaxonomyCanonicalConcept::class, 'suggested_concept_id');
    }

    public function isProposingNewConcept(): bool
    {
        return $this->suggested_concept_id === null;
    }

    // Phase B.1 (sección 14 del pedido, auditabilidad): mismo patrón que
    // `TaxonomyCandidateTerm::reviewedBy()` - UserPgsql (no User) para quedarse en la conexión
    // `pgsql` de este modelo.
    public function reviewedBy()
    {
        return $this->belongsTo(UserPgsql::class, 'reviewed_by');
    }

    // TASK-0005 (Issue #2 comentario `5914793857`): visibilidad de la propuesta congelada C2 desde
    // la UI del candidato - `freeze()` NUNCA toca `status`/`reviewed_at` de esta fila, así que sin
    // esta relación no habría forma de saber desde la grilla/vista que un candidato "pending" ya
    // tiene una decisión congelada esperando aplicación.
    public function reviewedProposals()
    {
        return $this->hasMany(TaxonomyReviewedProposal::class, 'candidate_link_id');
    }

    /**
     * TASK-0004, re-audit HIGH-2: bloquea CUALQUIER guardado (Filament, `CandidateConceptApprovalService`,
     * tinker, lo que sea) que deje `status=published` por fuera del `apply()` autorizado de Phase C2
     * - mismo criterio que el guard de `TaxonomyConceptRelation::booted()` (TASK-0003 hallazgo 6).
     * `ReviewedProposalService::apply()` enciende `isApplyingC2Publication()` únicamente alrededor
     * de sus propias dos escrituras de publicación (candidato + relación) - cualquier otro camino
     * que intente esta MISMA transición la ve apagada y aborta acá, revirtiendo toda la transacción
     * que lo contenga (incluida, por ejemplo, la fila que `CandidateConceptApprovalService::approve()`
     * ya insertó en `taxonomy_term_concepts` en la misma transacción antes de este punto).
     */
    protected static function booted(): void
    {
        static::saving(function (self $candidate) {
            if (! $candidate->isDirty('status') || $candidate->status !== self::STATUS_PUBLISHED) {
                return;
            }

            if (! ReviewedProposalService::isApplyingC2Publication()) {
                throw new \RuntimeException(
                    'No se puede publicar (status=published) un candidato fuera del apply() autorizado de Phase C2 '.
                    '(ReviewedProposalService::apply()) - ver TASK-0004, hallazgo HIGH-2. Congelá una decisión con '.
                    'freeze() y aplicala con una referencia de autorización explícita.'
                );
            }
        });
    }
}
