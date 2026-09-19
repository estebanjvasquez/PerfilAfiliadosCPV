<?php

namespace App\Models;

use App\Casts\PostgresTextArrayCast;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 3: catálogo gobernado de `taxonomy_concept_relations.relation_type` - ver docblock de la
 * migración `create_taxonomy_concept_relation_types_table`.
 */
class TaxonomyConceptRelationType extends Model
{
    protected $connection = 'pgsql';

    protected $fillable = [
        'code',
        'label_es',
        'label_en',
        'source_concept_types',
        'target_concept_types',
        'directional',
        'inverse_relation_code',
        'default_weight',
        'max_depth',
        'active',
    ];

    protected $casts = [
        'source_concept_types' => PostgresTextArrayCast::class,
        'target_concept_types' => PostgresTextArrayCast::class,
        'directional' => 'boolean',
        'default_weight' => 'float',
        'max_depth' => 'integer',
        'active' => 'boolean',
    ];

    public static function activeOptions(): array
    {
        return static::query()
            ->where('active', true)
            ->orderBy('code')
            ->pluck('label_es', 'code')
            ->all();
    }
}
