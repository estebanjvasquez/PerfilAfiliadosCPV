<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TAXV2-13 (sección 6 y 8 del documento de instrucciones): evidencia de que un término del
 * diccionario aparece en la web de una empresa - insumo de explicabilidad, NO una asignación
 * automática a `empresa_taxonomy_category` (ver docblock de la migración).
 */
class CompanyTermMatch extends Model
{
    protected $connection = 'pgsql';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'page_id',
        'empresa_id',
        'taxonomy_term_id',
        'matched_text',
        'canonical_term',
        'match_type',
        'context',
        'cpv_code',
        'relation_weight',
        'evidence_score',
        'status',
        'crawled_at',
    ];

    protected $casts = [
        'relation_weight' => 'float',
        'evidence_score' => 'float',
        'crawled_at' => 'datetime',
    ];

    public function page()
    {
        return $this->belongsTo(CompanyPage::class, 'page_id');
    }

    public function empresa()
    {
        return $this->belongsTo(EmpresaPgsql::class, 'empresa_id');
    }

    public function term()
    {
        return $this->belongsTo(TaxonomyTerm::class, 'taxonomy_term_id');
    }
}
