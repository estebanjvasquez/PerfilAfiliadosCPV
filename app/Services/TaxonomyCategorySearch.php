<?php

namespace App\Services;

use App\Models\TaxonomyCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fase 4 del proyecto de taxonomía (ver docs/taxonomia/plan_mcp_cira.md): "dado un texto libre,
 * devolver categorías CPV rankeadas" para el buscador de autocarga del panel
 * (TaxonomyCategoriesRelationManager). No existía código para esto en Laravel - se porta la MISMA
 * lógica híbrida (léxico + semántico) que ya usa la tool `search_taxonomy` del MCP
 * (`perfilafiliados-mcp/src/taxonomy-tools.ts`), pero como una llamada HTTP simple a `/embed` +
 * una query SQL directa contra la misma Supabase (no vía protocolo MCP - eso es para agentes/n8n,
 * no para un formulario de Filament).
 *
 * Léxico primero (rápido y preciso para nombres/sinónimos oficiales), diccionario de términos V2
 * después (TAXV2-5 - ver docs/taxonomia/INSTRUCCIONES_TAXONOMIA_CPV_CRAWLER_ADMIN_V2.md sección 7),
 * semántico de relleno al final - mismo criterio de deduplicación por `category_id` en las 3
 * fuentes.
 *
 * Diferencia deliberada respecto al original: acá SÍ se filtra `is_active = true` y `level != 0`
 * (Grupo) - el original es una herramienta de referencia interna para un agente, esto es
 * autocarga de cara a la empresa afiliada: no tiene sentido dejar elegir una categoría que Lorenzo
 * marcó fuera de alcance, ni un nodo de Grupo completo (regla anti-genérico de esta fase).
 *
 * TAXV2-5 agrega el nivel `dictionary`: usa `taxonomy_term_cpv_relations` (solo `status=approved`
 * - relaciones ya auto-aprobadas por confianza alta al importar, o aprobadas a mano desde el panel,
 * TAXV2-6) para encontrar categorías vía el diccionario de 1.837 términos V2, en vez de solo el
 * nombre oficial/sinónimos de `taxonomy_category_synonyms` (mucho más angosto - 3 filas hoy). El
 * `score` de cada resultado (`weight * oil_gas_exclusivity`, penalizado si `context_required`) es
 * un factor de REORDENAMIENTO dentro de esta misma búsqueda - no un motor de ranking aparte, y no
 * reemplaza el sistema RRF ya calibrado del Worker externo `perfilafiliados-mcp` (decisión del plan
 * de esta fase, para no tener 2 sistemas de ranking sin reconciliar). `explanation` es la
 * explicabilidad de la sección 8, adaptada a esta búsqueda de categorías (no la de empresas, que
 * vive en el Worker externo, fuera de alcance de este repo).
 */
class TaxonomyCategorySearch
{
    public function search(string $query, int $limit = 8): Collection
    {
        $query = trim($query);

        if ($query === '') {
            return collect();
        }

        $lexical = $this->lexical($query, $limit);
        $seen = $lexical->pluck('category_id')->all();

        $dictionaryRemaining = $limit - $lexical->count();
        $dictionary = $dictionaryRemaining > 0 ? $this->dictionary($query, $dictionaryRemaining, $seen) : collect();
        $seen = array_merge($seen, $dictionary->pluck('category_id')->all());

        $semanticRemaining = $limit - $lexical->count() - $dictionary->count();
        $semantic = $semanticRemaining > 0 ? $this->semantic($query, $semanticRemaining, $seen) : collect();

        return $lexical->concat($dictionary)->concat($semantic)->map(function ($row) {
            $category = TaxonomyCategory::query()->with('translations')->find($row->category_id);

            return [
                'category_id' => (int) $row->category_id,
                'code' => $row->code,
                'level' => (int) $row->level,
                'breadcrumb' => $category?->breadcrumb('es') ?? $row->path,
                'match_type' => $row->match_type,
                'score' => $row->score,
                'explanation' => $row->explanation,
            ];
        });
    }

    private function lexical(string $query, int $limit): Collection
    {
        $like = '%'.$query.'%';

        $rows = DB::connection('pgsql')->select(<<<'SQL'
            select distinct on (tc.id)
                tc.id as category_id, tc.code, tc.level, tc.path, 'lexical' as match_type
            from taxonomy_categories tc
            left join taxonomy_category_translations tt_es on tt_es.category_id = tc.id and tt_es.locale = 'es'
            left join taxonomy_category_translations tt_en on tt_en.category_id = tc.id and tt_en.locale = 'en'
            left join taxonomy_category_synonyms syn on syn.category_id = tc.id
                and unaccent(syn.term) ilike unaccent(?)
            where tc.level != 0 and tc.is_active = true
                and (
                    unaccent(coalesce(tt_es.name, '')) ilike unaccent(?)
                    or unaccent(coalesce(tt_en.name, '')) ilike unaccent(?)
                    or unaccent(coalesce(syn.term, '')) ilike unaccent(?)
                )
            limit ?
        SQL, [$like, $like, $like, $like, $limit]);

        return collect($rows)->map(function ($row) {
            $row->score = 1.0;
            $row->explanation = 'Coincidencia con el nombre o sinónimo oficial de la categoría';

            return $row;
        });
    }

    /**
     * TAXV2-5: matches vía el diccionario de términos V2 (`term`/`canonical_term`/alias), solo
     * relaciones `status=approved` (ver docblock de la clase). Trae hasta 3x `$limit` candidatos
     * (una consulta de texto libre normalmente no matchea tantos términos distintos) para poder
     * ordenar por `score` en PHP antes de recortar a `$limit` - hacerlo en PHP en vez de en el
     * `ORDER BY` de SQL evita repetir la fórmula de penalización (que depende de
     * `context_required`, un booleano) dos veces.
     */
    private function dictionary(string $query, int $limit, array $excludeIds): Collection
    {
        $like = '%'.$query.'%';
        $fetchLimit = max($limit * 3, 20);
        $placeholders = empty($excludeIds) ? '(0)' : '('.implode(',', array_fill(0, count($excludeIds), '?')).')';

        $rows = DB::connection('pgsql')->select(<<<SQL
            select distinct on (tc.id)
                tc.id as category_id, tc.code, tc.level, tc.path,
                t.term, r.weight, t.oil_gas_exclusivity, t.ambiguity_penalty, t.context_required
            from taxonomy_term_cpv_relations r
            join taxonomy_terms t on t.id = r.term_id
            join taxonomy_categories tc on tc.id = r.category_id
            left join taxonomy_term_aliases a on a.term_id = t.id
            where r.status = 'approved'
                and tc.level != 0 and tc.is_active = true
                and tc.id not in {$placeholders}
                and (
                    unaccent(t.term) ilike unaccent(?)
                    or unaccent(t.canonical_term) ilike unaccent(?)
                    or unaccent(coalesce(a.alias, '')) ilike unaccent(?)
                )
            order by tc.id, r.weight desc
            limit ?
        SQL, [...$excludeIds, $like, $like, $like, $fetchLimit]);

        return collect($rows)
            ->map(function ($row) {
                $penalty = $row->context_required ? (float) $row->ambiguity_penalty : 0.0;
                $score = max(0.0, round(((float) $row->weight) * (float) $row->oil_gas_exclusivity - $penalty, 4));

                return (object) [
                    'category_id' => $row->category_id,
                    'code' => $row->code,
                    'level' => $row->level,
                    'path' => $row->path,
                    'match_type' => 'dictionary',
                    'score' => $score,
                    'explanation' => sprintf(
                        'Término del diccionario "%s" (peso %.2f × exclusividad O&G %.2f%s)',
                        $row->term,
                        $row->weight,
                        $row->oil_gas_exclusivity,
                        $penalty > 0 ? sprintf(', penalizado -%.2f por ambigüedad', $penalty) : ''
                    ),
                ];
            })
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }

    private function semantic(string $query, int $limit, array $excludeIds): Collection
    {
        $vector = $this->embed($query);

        if (! $vector) {
            return collect();
        }

        $placeholders = empty($excludeIds) ? '(0)' : '('.implode(',', array_fill(0, count($excludeIds), '?')).')';

        $rows = DB::connection('pgsql')->select(<<<SQL
            select
                tc.id as category_id, tc.code, tc.level, tc.path,
                (tce.embedding <=> ?::vector) as distance
            from taxonomy_category_embeddings tce
            join taxonomy_categories tc on tc.id = tce.category_id
            where tc.level != 0 and tc.is_active = true
                and tc.id not in {$placeholders}
            order by distance asc
            limit ?
        SQL, [$vector, ...$excludeIds, $limit]);

        return collect($rows)->map(function ($row) {
            $row->match_type = 'semantic';
            $row->score = max(0.0, round(1 - (float) $row->distance, 4));
            $row->explanation = sprintf('Similitud semántica (distancia coseno %.3f)', $row->distance);

            return $row;
        });
    }

    /** Mismo contrato que GenerateTaxonomyEmbeddings.php/HomologateServicesTaxonomy.php: POST {texts:[...]} -> {embeddings:[[...]]}. */
    private function embed(string $text): ?string
    {
        $embedUrl = config('services.mcp.embed_url');
        $embedToken = config('services.mcp.embed_token');

        if (! $embedUrl || ! $embedToken) {
            Log::warning('TaxonomyCategorySearch: falta MCP_EMBED_URL/MCP_EMBED_TOKEN, se omite el nivel semántico.');

            return null;
        }

        $response = Http::withToken($embedToken)->timeout(15)->post($embedUrl, ['texts' => [$text]]);

        if (! $response->successful()) {
            Log::warning('TaxonomyCategorySearch: fallo el request a /embed', ['status' => $response->status()]);

            return null;
        }

        $embeddings = $response->json('embeddings');
        $vector = $embeddings[0] ?? null;

        return is_array($vector) ? '['.implode(',', $vector).']' : null;
    }
}
