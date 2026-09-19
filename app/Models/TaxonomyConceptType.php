<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 3: vocabulario gobernado de `taxonomy_canonical_concepts.concept_type` - ver docblock de la
 * migración `create_taxonomy_concept_types_table`.
 */
class TaxonomyConceptType extends Model
{
    protected $connection = 'pgsql';

    protected $fillable = [
        'code',
        'label_es',
        'label_en',
        'sort_order',
        'active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'active' => 'boolean',
    ];

    public static function activeOptions(): array
    {
        return static::query()
            ->where('active', true)
            ->orderBy('sort_order')
            ->pluck('label_es', 'code')
            ->all();
    }
}
