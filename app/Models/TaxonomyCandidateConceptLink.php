<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 3 (sección 7 del pedido): fila de la cola de revisión del Canonical Concept Builder - ver
 * docblock de la migración `create_taxonomy_candidate_concept_links_table`. Mismo espíritu que
 * `TaxonomyCandidateTerm` (TAXV2-12) pero para propuestas TÉRMINO->CONCEPTO.
 *
 * `suggested_concept_id === null` representa una propuesta de concepto NUEVO
 * (`suggested_new_concept_name` lleva el nombre sugerido) - ver `isProposingNewConcept()`.
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
}
