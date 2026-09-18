<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Relación Término -> CPV del diccionario V2 (TAXV2-2). Ver docblock de la migración
 * `create_taxonomy_term_cpv_relations_table` para el criterio de auto-triage de `status` al
 * importar y por qué `category_id` es nullable.
 */
class TaxonomyTermCpvRelation extends Model
{
    protected $connection = 'pgsql';

    public const LEVEL_GROUP = 'group';
    public const LEVEL_FAMILY = 'family';
    public const LEVEL_CATEGORY = 'category';

    public const STATUS_CANDIDATE = 'candidate';
    public const STATUS_AUTO_MAPPED = 'auto_mapped';
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_DEPRECATED = 'deprecated';

    protected $fillable = [
        'term_id',
        'cpv_code',
        'category_id',
        'level',
        'relation_type',
        'weight',
        'confidence',
        'matched_on',
        'source',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'weight' => 'float',
        'confidence' => 'float',
        'reviewed_at' => 'datetime',
    ];

    public function term()
    {
        return $this->belongsTo(TaxonomyTerm::class, 'term_id');
    }

    public function category()
    {
        return $this->belongsTo(TaxonomyCategory::class, 'category_id');
    }
}
