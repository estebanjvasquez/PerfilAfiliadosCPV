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

    /**
     * TAXV3-6: extraído de `CrawlCompanyWebsite::storeMatch()` para poder reusarlo desde
     * `taxonomy:rematch-company-pages` sin duplicar la lógica de scoring - ambos comandos terminan
     * en el mismo lugar (una fila de evidencia), solo cambia de dónde viene el texto (recién
     * crawleado vs ya almacenado).
     *
     * @param  array{term: TaxonomyTerm, matched_text: string, context: string}  $found
     * @return bool true si se creó una fila nueva (false si ya existía para esta página+término).
     */
    public static function recordFromMatch(CompanyPage $page, int $empresaId, array $found): bool
    {
        $term = $found['term'];

        $exists = static::query()
            ->where('page_id', $page->id)
            ->where('taxonomy_term_id', $term->id)
            ->exists();
        if ($exists) {
            return false;
        }

        $bestRelation = $term->cpvRelations->sortByDesc('weight')->first();
        $evidenceScore = round(($bestRelation->weight ?? 0.5) * ($term->oil_gas_exclusivity ?? 1.0), 4);

        static::query()->create([
            'page_id' => $page->id,
            'empresa_id' => $empresaId,
            'taxonomy_term_id' => $term->id,
            'matched_text' => $found['matched_text'],
            'canonical_term' => $term->canonical_term ?: $term->term,
            'match_type' => 'exact_page_text',
            'context' => $found['context'],
            'cpv_code' => $bestRelation->cpv_code ?? null,
            'relation_weight' => $bestRelation->weight ?? null,
            'evidence_score' => $evidenceScore,
            'status' => static::STATUS_PENDING_REVIEW,
            'crawled_at' => now(),
        ]);

        return true;
    }
}
