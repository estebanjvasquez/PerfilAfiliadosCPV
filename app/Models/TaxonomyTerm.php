<?php

namespace App\Models;

use App\Casts\PostgresTextArrayCast;
use Illuminate\Database\Eloquent\Model;

/**
 * Diccionario de términos CPV V2 (TAXV2-1) - ver docs/taxonomia/oil_gas_master_taxonomy_v2_cpv.json
 * `records[]` y docs/taxonomia/INSTRUCCIONES_TAXONOMIA_CPV_CRAWLER_ADMIN_V2.md sección 4.5.
 *
 * `term_category`/`term_subcategory` son la clasificación temática del JSON (ej. "drilling",
 * "core") - NO tienen relación con `taxonomy_categories` (la jerarquía CPV), a propósito nombradas
 * distinto para no confundirlas.
 */
class TaxonomyTerm extends Model
{
    protected $connection = 'pgsql';

    public const TERM_TYPE_TECHNICAL = 'technical_term';
    public const TERM_TYPE_COMMERCIAL_PHRASE = 'commercial_phrase';
    public const TERM_TYPE_GENERATED_DOMAIN_PHRASE = 'generated_domain_phrase';
    public const TERM_TYPE_ACRONYM = 'acronym';
    public const TERM_TYPE_TRANSLATION_ALIAS = 'translation_alias';
    public const TERM_TYPE_OILFIELD_SLANG = 'oilfield_slang';
    public const TERM_TYPE_REGIONAL_SLANG = 'regional_slang';
    public const TERM_TYPE_REGIONAL_VARIANT = 'regional_variant';

    public const MAPPING_AUTO_MAPPED = 'auto_mapped';
    public const MAPPING_UNMAPPED = 'unmapped';

    /** V2->V3 (TAXV3-1): reemplaza el valor ad-hoc `source_verified_needs_cpv_mapping`. */
    public const MAPPING_NEEDS_REVIEW = 'needs_review';

    /** V2->V3: ver docblock de la migración `add_provenance_columns_to_taxonomy_terms_table`. */
    public const ORIGIN_EXTERNAL_VERIFIED = 'external_verified';

    public const ORIGIN_PENDING_SOURCE_VERIFICATION = 'seed_taxonomy_pending_source_verification';

    public const ORIGIN_CURATED_OR_GENERATED = 'curated_or_generated';

    protected $fillable = [
        'external_id',
        'term',
        'language',
        'canonical_term',
        'term_category',
        'term_subcategory',
        'region',
        'term_type',
        'relevance_weight',
        'oil_gas_exclusivity',
        'context_required',
        'minimum_supporting_terms',
        'context_window_words',
        'ambiguity_penalty',
        'negative_context',
        'positive_context',
        'source_id',
        'mapping_review_status',
        'origin_type',
        'display_source',
        'primary_source_id',
        'candidate_sources',
    ];

    protected $casts = [
        'region' => PostgresTextArrayCast::class,
        'negative_context' => PostgresTextArrayCast::class,
        'positive_context' => PostgresTextArrayCast::class,
        'relevance_weight' => 'float',
        'oil_gas_exclusivity' => 'float',
        'ambiguity_penalty' => 'float',
        'context_required' => 'boolean',
        'minimum_supporting_terms' => 'integer',
        'context_window_words' => 'integer',
        'candidate_sources' => 'array',
    ];

    public function source()
    {
        return $this->belongsTo(TaxonomySource::class, 'source_id', 'source_id');
    }

    /** V2->V3: la fuente externa verificada principal (null si `origin_type` no es `external_verified`). */
    public function primarySource()
    {
        return $this->belongsTo(TaxonomySource::class, 'primary_source_id', 'source_id');
    }

    /** V2->V3: bindings verificados (N:M real) - ver docblock de `taxonomy_term_source_bindings`. */
    public function sourceBindings()
    {
        return $this->hasMany(TaxonomyTermSourceBinding::class, 'term_id');
    }

    public function aliases()
    {
        return $this->hasMany(TaxonomyTermAlias::class, 'term_id');
    }

    public function cpvRelations()
    {
        return $this->hasMany(TaxonomyTermCpvRelation::class, 'term_id');
    }

    public function serviceRelations()
    {
        return $this->hasMany(TaxonomyTermServiceRelation::class, 'term_id');
    }
}
