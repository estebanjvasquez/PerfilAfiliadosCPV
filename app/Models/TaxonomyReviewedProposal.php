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
     * ("generic but valid terms"). Distinto de REJECT: el candidato NO se descarta, y el
     * término/motivo del revisor se preserva para un POSIBLE uso futuro como evidencia contextual -
     * sin que eso implique que algún consumidor de búsqueda lo lea hoy (ninguno lo hace, aclarado en
     * la ronda 4 del re-audit, Issue #2 comentario `5909267134`). `apply()` para esta decisión NUNCA
     * escribe `taxonomy_term_concepts` ni crea un concepto, y revalida el `term_id` congelado contra
     * la fila viva antes de resolverla (ronda 4, defecto 2) - ver
     * `ReviewedProposalService::evaluateCandidateLink()`/`writeCandidateLinkDecision()`.
     */
    public const DECISION_CONTEXT_REQUIRED = 'CONTEXT_REQUIRED';

    public const STATUS_PENDING_APPLY = 'PENDING_APPLY';

    public const STATUS_APPLIED = 'APPLIED';

    public const STATUS_ABORTED = 'ABORTED';

    /**
     * TASK-0006B (Issue #2 comentario `5936206843`), sección A: quién PREPARÓ el contenido de la
     * decisión, que no es necesariamente quién figura como `reviewer_id`. `ACTOR_AGENT` es lo que
     * el re-audit `5934324928` exigió poder decir estructuralmente en vez de dejarlo como texto
     * libre contradictorio dentro de `context_reason`.
     */
    public const ACTOR_HUMAN_REVIEWER = 'human_reviewer';

    public const ACTOR_AGENT = 'agent';

    /** Canal auto-capturado (nunca provisto por quien llama), mismo criterio que `target_environment`. */
    public const CHANNEL_HTTP = 'http';

    public const CHANNEL_CONSOLE = 'console';

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
        'requires_human_confirmation',
        'prepared_by_actor_type',
        'prepared_via',
        'confirmed_by_id',
        'confirmed_at',
        'confirmation_reference',
        'confirmation_channel',
        'confirmation_note',
        'proposal_group_id',
        'confirmation_invalidated_at',
        'confirmation_invalidated_by_id',
        'confirmation_invalidation_actor_type',
        'confirmation_invalidation_channel',
        'confirmation_invalidation_reference',
        'confirmation_invalidation_reason',
        'invalidated_confirmation_snapshot',
    ];

    protected $casts = [
        'candidate_link_id' => 'integer',
        'concept_relation_id' => 'integer',
        'reviewer_id' => 'integer',
        'confirmed_by_id' => 'integer',
        'decision_payload' => 'array',
        'application_result' => 'array',
        'reviewed_at' => 'datetime',
        'applied_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'requires_human_confirmation' => 'boolean',
        'confirmation_invalidated_at' => 'datetime',
        'confirmation_invalidated_by_id' => 'integer',
        'invalidated_confirmation_snapshot' => 'array',
    ];

    /**
     * TASK-0006B, sección A: único predicado que `apply()` consulta para decidir si hay que exigir
     * confirmación humana. `requires_human_confirmation` es `FALSE` por DEFAULT de la migración, y
     * eso ES la regla de compatibilidad: toda propuesta congelada antes de TASK-0006B (incluidas
     * las legítimas #420/#421/#422/#491) sigue siendo aplicable sin confirmación, porque su
     * procedencia de revisión original YA satisface el requisito de revisión humana.
     */
    public function awaitsHumanConfirmation(): bool
    {
        return $this->requires_human_confirmation && $this->confirmed_at === null;
    }

    public function isHumanConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /** Preparada por el agente: su decisión NO la redactó la persona que figura como revisor. */
    public function isAgentPrepared(): bool
    {
        return $this->prepared_by_actor_type === self::ACTOR_AGENT;
    }

    /**
     * TASK-0006B, sección D: una propuesta bilingüe agrupada (270 + 271 -> UN concepto) se modela
     * como varias filas unidas por `proposal_group_id`, para que el índice único parcial ya
     * existente proteja a TODOS los candidatos del grupo - ver el docblock de la migración
     * `add_human_confirmation_and_bilingual_group_to_taxonomy_reviewed_proposals`.
     */
    public function isGrouped(): bool
    {
        return $this->proposal_group_id !== null;
    }

    public function confirmedBy()
    {
        return $this->belongsTo(UserPgsql::class, 'confirmed_by_id');
    }

    /**
     * TASK-0006C: esta propuesta tuvo una confirmación cuya procedencia resultó inválida y fue
     * ANULADA. El rastro se conserva (`invalidated_confirmation_snapshot` + referencia + motivo), así
     * que la fila sigue siendo autodescriptiva: se puede ver que hubo una confirmación inválida y
     * cuál era, sin cruzar con `taxonomy_audit_log`.
     */
    public function hasInvalidatedConfirmation(): bool
    {
        return $this->confirmation_invalidated_at !== null;
    }

    public function confirmationInvalidatedBy()
    {
        return $this->belongsTo(UserPgsql::class, 'confirmation_invalidated_by_id');
    }

    /** Las otras filas del mismo grupo bilingüe (sin incluirse a sí misma). */
    public function groupSiblings()
    {
        return self::query()
            ->where('proposal_group_id', $this->proposal_group_id)
            ->whereKeyNot($this->getKey())
            ->whereNotNull('proposal_group_id');
    }

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
