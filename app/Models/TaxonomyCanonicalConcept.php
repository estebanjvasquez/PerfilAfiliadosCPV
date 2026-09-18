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
    ];

    public function terms()
    {
        return $this->belongsToMany(TaxonomyTerm::class, 'taxonomy_term_concepts', 'concept_id', 'term_id')
            ->withPivot('created_at');
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->canonical_name_en && $this->canonical_name_es
            ? "{$this->canonical_name_en} / {$this->canonical_name_es}"
            : (string) ($this->canonical_name_en ?? $this->canonical_name_es ?? "Concepto #{$this->id}");
    }
}
