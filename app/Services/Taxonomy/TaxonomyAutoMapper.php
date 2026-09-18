<?php

namespace App\Services\Taxonomy;

use App\Models\TaxonomyTerm;
use App\Models\TaxonomyTermCpvRelation;
use Illuminate\Support\Facades\DB;

/**
 * TAXV3-3 (ajuste V3 puntos 7/8/13/16): decide relaciones Término→CPV para términos que hoy no
 * tienen NINGUNA (277: 225 heredados de V2 + 52 nuevos de V3) - nunca para los 9.727 ya existentes.
 *
 * Pipeline de PRIORIDAD (no un ensemble ponderado): se prueba cada señal en orden y la primera que
 * alcanza el piso de confianza configurado (`auto_mapping.candidate_confidence_floor`) gana - un
 * match exacto de nombre CPV siempre le gana a una similitud léxica/semántica, aunque
 * hipotéticamente esa señal más débil hubiera puntuado distinto. Si ninguna señal alcanza el piso,
 * no se crea relación y el término queda tal cual (sigue "needs_review"/"unmapped" a nivel de
 * término, listo para cuando haya mejores datos - ej. Fase B, Full Source Discovery).
 *
 * **A propósito NO llama a `TaxonomyCategorySearch::search()`** (ver docblock de esa clase) - reusa
 * `EmbeddingClient` y escribe sus propias queries léxico/`pg_trgm`, calibradas para decisiones de
 * auto-aprobación (evidencia + confianza explícita), no para autocompletado interactivo.
 */
class TaxonomyAutoMapper
{
    public function __construct(private readonly EmbeddingClient $embeddingClient) {}

    /**
     * @return array{cpv_code:string,category_id:?int,level:string,relation_type:string,weight:float,confidence:float,evidence:array<int,string>}|null
     */
    public function proposeCandidate(TaxonomyTerm $term): ?array
    {
        $needle = trim($term->canonical_term ?: $term->term);
        if ($needle === '') {
            return null;
        }

        $floor = (float) (DB::connection('pgsql')->table('taxonomy_settings')
            ->where('key', 'auto_mapping.candidate_confidence_floor')->value('value') ?? 0.70);

        foreach ([
            fn () => $this->exactCategoryLabelMatch($needle),
            fn () => $this->conceptInheritedMatch($term),
            fn () => $this->lexicalSimilarityMatch($needle, $term->id),
            fn () => $this->embeddingSimilarityMatch($needle),
        ] as $signal) {
            $candidate = $signal();

            if ($candidate !== null && $candidate['confidence'] >= $floor) {
                $candidate['confidence'] = $this->applySourceCorroborationBoost($term, $candidate['confidence']);

                return $candidate;
            }
        }

        return null;
    }

    /**
     * Señal 1: coincidencia exacta (unaccent, sin distinguir mayúsculas) contra el nombre EN/ES de
     * un Grupo/Familia/Categoría - la más específica gana si hay varias.
     */
    private function exactCategoryLabelMatch(string $needle): ?array
    {
        $row = DB::connection('pgsql')->selectOne(<<<'SQL'
            SELECT tc.id AS category_id, tc.code, tc.level, tt.name, tt.locale
            FROM taxonomy_categories tc
            JOIN taxonomy_category_translations tt ON tt.category_id = tc.id
            WHERE tc.is_active = true
                AND unaccent(tt.name) ILIKE unaccent(?)
            ORDER BY tc.level DESC
            LIMIT 1
        SQL, [$needle]);

        if (! $row) {
            return null;
        }

        return $this->buildCandidate(
            categoryId: (int) $row->category_id,
            cpvCode: $row->code,
            level: $this->levelToString((int) $row->level),
            relationType: 'exact',
            confidence: 0.97,
            weight: 0.95,
            evidenceLine: "Coincidencia exacta con el nombre {$row->locale} \"{$row->name}\" de la categoría CPV {$row->code}",
        );
    }

    /**
     * Señal 2 (ajuste V3 punto 6): si el término pertenece a un concepto canónico (TAXV3-2) y OTRO
     * término del mismo concepto ya tiene una relación CPV aprobada, se hereda esa relación con un
     * descuento de confianza por indirección - así un regionalismo vinculado a su concepto (ej.
     * "mechurrio" -> concepto de "flare") resuelve su mapping CPV sin haber sido nunca fuente
     * externa verificada él mismo.
     */
    private function conceptInheritedMatch(TaxonomyTerm $term): ?array
    {
        $row = DB::connection('pgsql')->selectOne(<<<'SQL'
            SELECT r.cpv_code, r.category_id, r.level, r.weight, r.confidence, sibling.term AS sibling_term
            FROM taxonomy_term_concepts my_link
            JOIN taxonomy_term_concepts sibling_link ON sibling_link.concept_id = my_link.concept_id
                AND sibling_link.term_id != my_link.term_id
            JOIN taxonomy_terms sibling ON sibling.id = sibling_link.term_id
            JOIN taxonomy_term_cpv_relations r ON r.term_id = sibling.id AND r.status = 'approved'
            WHERE my_link.term_id = ?
            ORDER BY r.weight DESC
            LIMIT 1
        SQL, [$term->id]);

        if (! $row) {
            return null;
        }

        return $this->buildCandidate(
            categoryId: $row->category_id ? (int) $row->category_id : null,
            cpvCode: $row->cpv_code,
            level: $row->level,
            relationType: 'concept_inherited',
            confidence: round(((float) $row->confidence) * 0.9, 4),
            weight: round(((float) $row->weight) * 0.9, 4),
            evidenceLine: "Heredado del concepto canónico compartido con \"{$row->sibling_term}\" (ya aprobado hacia {$row->cpv_code})",
        );
    }

    /**
     * Señal 3: similitud `pg_trgm` contra el `canonical_term`/alias de OTROS términos que ya tienen
     * al menos una relación CPV aprobada (mismo criterio que `CrawlTaxonomySource::suggestCpvMatch`,
     * reescrito acá porque ese método vive en un comando de crawling con su propio ciclo de vida,
     * no en un servicio reusable - ambos comparten la query, no el propósito).
     */
    private function lexicalSimilarityMatch(string $needle, int $excludeTermId): ?array
    {
        $row = DB::connection('pgsql')->selectOne(<<<'SQL'
            SELECT r.cpv_code, r.category_id, r.level, r.weight, r.confidence, t.canonical_term,
                similarity(t.canonical_term, ?) AS sim
            FROM taxonomy_terms t
            JOIN taxonomy_term_cpv_relations r ON r.term_id = t.id AND r.status = 'approved'
            WHERE t.id != ? AND t.canonical_term IS NOT NULL AND t.canonical_term % ?
            ORDER BY sim DESC, r.weight DESC
            LIMIT 1
        SQL, [$needle, $excludeTermId, $needle]);

        if (! $row) {
            return null;
        }

        return $this->buildCandidate(
            categoryId: $row->category_id ? (int) $row->category_id : null,
            cpvCode: $row->cpv_code,
            level: $row->level,
            relationType: 'lexical',
            confidence: round((float) $row->sim, 4),
            weight: round(((float) $row->weight) * (float) $row->sim, 4),
            evidenceLine: sprintf('Similitud léxica %.2f con el término ya mapeado "%s" (→ %s)', $row->sim, $row->canonical_term, $row->cpv_code),
        );
    }

    /** Señal 4 (la más débil, última en la prioridad): distancia coseno directa contra categorías. */
    private function embeddingSimilarityMatch(string $needle): ?array
    {
        $vector = $this->embeddingClient->embed($needle);
        if (! $vector) {
            return null;
        }

        $row = DB::connection('pgsql')->selectOne(<<<'SQL'
            SELECT tc.id AS category_id, tc.code, tc.level,
                (tce.embedding <=> ?::vector) AS distance
            FROM taxonomy_category_embeddings tce
            JOIN taxonomy_categories tc ON tc.id = tce.category_id
            WHERE tc.level != 0 AND tc.is_active = true
            ORDER BY distance ASC
            LIMIT 1
        SQL, [$vector]);

        if (! $row) {
            return null;
        }

        $confidence = max(0.0, round(1 - (float) $row->distance, 4));

        return $this->buildCandidate(
            categoryId: (int) $row->category_id,
            cpvCode: $row->code,
            level: $this->levelToString((int) $row->level),
            relationType: 'contextual',
            confidence: $confidence,
            weight: $confidence,
            evidenceLine: sprintf('Similitud semántica (distancia coseno %.3f) con la categoría %s', $row->distance, $row->code),
        );
    }

    /**
     * Ajuste V3 punto 13: la corroboración de fuente aumenta la CONFIANZA, no el ranking de
     * búsqueda directamente (eso es responsabilidad de `TaxonomyCategorySearch`, sin tocar acá).
     */
    private function applySourceCorroborationBoost(TaxonomyTerm $term, float $confidence): float
    {
        $bindingsCount = DB::connection('pgsql')->table('taxonomy_term_source_bindings')
            ->where('term_id', $term->id)
            ->where('verification_status', 'verified')
            ->count();

        if ($bindingsCount <= 1) {
            return $confidence;
        }

        $boost = (float) (DB::connection('pgsql')->table('taxonomy_settings')
            ->where('key', 'auto_mapping.source_corrob_boost')->value('value') ?? 1.0);

        return min(1.0, round($confidence * $boost, 4));
    }

    private function buildCandidate(?int $categoryId, string $cpvCode, string $level, string $relationType, float $confidence, float $weight, string $evidenceLine): array
    {
        return [
            'cpv_code' => $cpvCode,
            'category_id' => $categoryId,
            'level' => $level,
            'relation_type' => $relationType,
            'weight' => max(0.0, min(1.0, $weight)),
            'confidence' => max(0.0, min(1.0, $confidence)),
            'evidence' => [$evidenceLine],
        ];
    }

    private function levelToString(int $level): string
    {
        return match ($level) {
            0 => TaxonomyTermCpvRelation::LEVEL_GROUP,
            1 => TaxonomyTermCpvRelation::LEVEL_FAMILY,
            default => TaxonomyTermCpvRelation::LEVEL_CATEGORY,
        };
    }
}
