<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TASK-0004 (Phase C2): el "payload inmutable y con fingerprint" del contrato
 * REVIEWED_PROPOSAL -> payload -> APPLY -> VALIDATE -> COMMIT/ROLLBACK -> AUDIT. Ver el docblock de
 * la migración `create_taxonomy_reviewed_proposals_table` para el razonamiento completo. Esta
 * clase es intencionalmente delgada (sin lógica de negocio) - toda la lógica de congelar/aplicar
 * vive en `App\Services\Taxonomy\ReviewedProposalService`, igual que el resto de modelos de
 * taxonomía de este proyecto.
 */
class TaxonomyReviewedProposal extends Model
{
    protected $connection = 'pgsql';

    public const TYPE_TERM_CONCEPT_LINK = 'TERM_CONCEPT_LINK';

    public const TYPE_CONCEPT_RELATION = 'CONCEPT_RELATION';

    public const DECISION_MAP_TO_EXISTING = 'MAP_TO_EXISTING';

    public const DECISION_CREATE_NEW = 'CREATE_NEW';

    public const DECISION_REJECT = 'REJECT';

    public const DECISION_PUBLISH_RELATION = 'PUBLISH_RELATION';

    /**
     * TASK-0004, re-audit correction C (Issue #2 comentario `5892711739`): cuarto desenlace de
     * revisión para TERM_CONCEPT_LINK - término/candidato válido en el dominio pero
     * insuficientemente específico por sí solo para sostener un mapeo directo producto/servicio/CPV
     * ("generic but valid terms"). Distinto de REJECT: el candidato sigue siendo evidencia
     * contextual/de búsqueda válida, no descartado. `apply()` para esta decisión NUNCA escribe
     * `taxonomy_term_concepts` ni crea un concepto - ver `ReviewedProposalService::applyCandidateLinkDecision()`.
     */
    public const DECISION_CONTEXT_REQUIRED = 'CONTEXT_REQUIRED';

    public const STATUS_PENDING_APPLY = 'PENDING_APPLY';

    public const STATUS_APPLIED = 'APPLIED';

    public const STATUS_ABORTED = 'ABORTED';

    protected $fillable = [
        'proposal_type',
        'candidate_link_id',
        'concept_relation_id',
        'decision',
        'decision_payload',
        'payload_version',
        'taxonomy_state_fingerprint',
        'payload_fingerprint',
        'reviewer_id',
        'reviewed_at',
        'status',
        'applied_at',
        'authorization_reference',
        'target_environment',
        'application_result',
    ];

    protected $casts = [
        'candidate_link_id' => 'integer',
        'concept_relation_id' => 'integer',
        'reviewer_id' => 'integer',
        'decision_payload' => 'array',
        'application_result' => 'array',
        'reviewed_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function candidateLink()
    {
        return $this->belongsTo(TaxonomyCandidateConceptLink::class, 'candidate_link_id');
    }

    public function conceptRelation()
    {
        return $this->belongsTo(TaxonomyConceptRelation::class, 'concept_relation_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(UserPgsql::class, 'reviewer_id');
    }
}
