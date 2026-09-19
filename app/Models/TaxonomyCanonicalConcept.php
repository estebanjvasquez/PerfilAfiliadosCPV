<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TAXV3-2: ver docblock de la migración `create_taxonomy_canonical_concepts_table`.
 */
class TaxonomyCanonicalConcept extends Model
{
    protected $connection = 'pgsql';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_MERGED = 'merged';

    protected $fillable = [
        'canonical_name_en',
        'canonical_name_es',
        'status',
        'concept_type',
        'domain',
    ];

    public function terms()
    {
        return $this->belongsToMany(TaxonomyTerm::class, 'taxonomy_term_concepts', 'concept_id', 'term_id')
            ->withPivot('created_at');
    }

    /** Phase 3: relaciones tipadas donde este concepto es el origen. */
    public function relationsAsSource()
    {
        return $this->hasMany(TaxonomyConceptRelation::class, 'source_concept_id');
    }

    /** Phase 3: relaciones tipadas donde este concepto es el destino. */
    public function relationsAsTarget()
    {
        return $this->hasMany(TaxonomyConceptRelation::class, 'target_concept_id');
    }

    /** Phase 3: candidatos de la cola de revisión que proponen vincular un término a este concepto. */
    public function candidateLinks()
    {
        return $this->hasMany(TaxonomyCandidateConceptLink::class, 'suggested_concept_id');
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->canonical_name_en && $this->canonical_name_es
            ? "{$this->canonical_name_en} / {$this->canonical_name_es}"
            : (string) ($this->canonical_name_en ?? $this->canonical_name_es ?? "Concepto #{$this->id}");
    }
}
