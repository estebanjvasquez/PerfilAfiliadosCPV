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
 * Léxico primero (rápido y preciso para jerga/sinónimos locales conocidos), semántico de relleno
 * hasta completar el límite (para lenguaje natural sin coincidencia literal) - mismo orden y mismo
 * criterio de deduplicación por `category_id` que el original.
 *
 * Diferencia deliberada respecto al original: acá SÍ se filtra `is_active = true` y `level != 0`
 * (Grupo) - el original es una herramienta de referencia interna para un agente, esto es
 * autocarga de cara a la empresa afiliada: no tiene sentido dejar elegir una categoría que Lorenzo
 * marcó fuera de alcance, ni un nodo de Grupo completo (regla anti-genérico de esta fase).
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

        $remaining = $limit - $lexical->count();
        $semantic = $remaining > 0 ? $this->semantic($query, $remaining, $seen) : collect();

        return $lexical->concat($semantic)->map(function ($row) {
            $category = TaxonomyCategory::query()->with('translations')->find($row->category_id);

            return [
                'category_id' => (int) $row->category_id,
                'code' => $row->code,
                'level' => (int) $row->level,
                'breadcrumb' => $category?->breadcrumb('es') ?? $row->path,
                'match_type' => $row->match_type,
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

        return collect($rows);
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
                tc.id as category_id, tc.code, tc.level, tc.path, 'semantic' as match_type
            from taxonomy_category_embeddings tce
            join taxonomy_categories tc on tc.id = tce.category_id
            where tc.level != 0 and tc.is_active = true
                and tc.id not in {$placeholders}
            order by tce.embedding <=> ?::vector
            limit ?
        SQL, [...$excludeIds, $vector, $limit]);

        return collect($rows);
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
