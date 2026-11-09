<?php

namespace App\Services;

use App\Models\TaxonomyCategory;
use App\Services\Taxonomy\EmbeddingClient;
use App\Services\Taxonomy\TaxonomyLookup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
 *
 * TAXV3-3: **esta clase es para autocompletado interactivo** (el admin escribe, ve resultados,
 * elige) - está afinada para esa UX (dedupe agresivo por categoría, `take($limit)` temprano,
 * mezcla de 3 niveles). `App\Services\Taxonomy\TaxonomyAutoMapper` (decide relaciones Término→CPV
 * que pueden auto-aprobarse sin revisión humana) NO debe llamar a `search()` - reusa
 * `EmbeddingClient` y las mismas queries léxico/`pg_trgm` como primitivas propias, con su propia
 * lógica de confianza/evidencia. Mezclar ambos casos de uso en un solo método terminaría afinando
 * el autocompletado para decisiones de auto-aprobación, o al revés.
 *
 * TASK-0014 (Issue #2 `6736283026`): los niveles léxico y de diccionario salen ahora de
 * `TaxonomyLookup`, el mismo criterio que usan los listados de administración. Cambios visibles:
 * - también encuentra por código;
 * - ordena por calidad de la coincidencia: el texto completo, luego el comienzo de una palabra, y
 *   al final una subcadena dentro de una palabra. Con «balanc», «Balancín» queda antes que
 *   «Perforación Bajobalanceada (UBD)»;
 * - cada resultado trae `reasons`: todos sus motivos (nombre, sinónimo, término aprobado del
 *   diccionario), no solo el del primer nivel que la encontró;
 * - la similitud semántica sigue siendo de relleno y se rotula como sugerencia. No se guarda como
 *   relación ni se presenta como prueba de capacidad.
 */
class TaxonomyCategorySearch
{
    public function __construct(private readonly TaxonomyLookup $lookup)
    {
    }

    /**
     * @return Collection<int, array{category_id:int, code:string, level:int, breadcrumb:string, match_type:string, score:float, explanation:string, reasons:list<array>}>
     */
    public function search(string $query, int $limit = 8): Collection
    {
        $query = trim($query);

        if ($query === '') {
            return collect();
        }

        $matches = $this->lookup->matches($query, TaxonomyLookup::SCOPE_COMPANY, $limit);
        $seen = $matches->pluck('category_id')->all();

        $remaining = $limit - $matches->count();
        $semantic = $remaining > 0 ? $this->semantic($query, $remaining, $seen) : collect();

        $rows = $matches->map(fn (array $match) => (object) [
            'category_id' => $match['category_id'],
            'code' => $match['code'],
            'level' => $match['level'],
            'path' => null,
            'match_type' => $match['tier'] === 0 ? 'lexical' : 'dictionary',
            'score' => $match['tier'] === 0 ? 1.0 : (float) ($match['dictionary_score'] ?? 0.0),
            'explanation' => TaxonomyLookup::describeForCompany($match['reasons'][0]),
            'reasons' => $match['reasons'],
        ])->concat($semantic->map(function ($row) {
            $row->reasons = [['kind' => TaxonomyLookup::KIND_SEMANTIC, 'matched' => '', 'quality' => TaxonomyLookup::QUALITY_EXACT, 'relation_status' => null]];

            return $row;
        }));

        $breadcrumbs = $this->breadcrumbs($rows->pluck('category_id')->all());

        return $rows->map(function ($row) use ($breadcrumbs) {
            return [
                'category_id' => (int) $row->category_id,
                'code' => $row->code,
                'level' => (int) $row->level,
                'breadcrumb' => $breadcrumbs[(int) $row->category_id] ?? $row->code,
                'match_type' => $row->match_type,
                'score' => $row->score,
                'explanation' => $row->explanation,
                'reasons' => $row->reasons,
            ];
        })->values();
    }

    /**
     * TASK-0014: la misma ruta que `TaxonomyCategory::breadcrumb('es')`, en dos consultas para todos
     * los resultados. Antes eran dos consultas por resultado, que con la base remota sumaban segundos.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function breadcrumbs(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $paths = TaxonomyCategory::query()->whereIn('id', $ids)->pluck('path', 'id');
        $codes = $paths->flatMap(fn ($path) => explode('/', (string) $path))->unique()->values();
        $nodes = TaxonomyCategory::query()->whereIn('code', $codes)->with('translations')->get()->keyBy('code');

        return $paths->map(fn ($path) => collect(explode('/', (string) $path))
            ->map(fn ($code) => $nodes->get($code))
            ->filter()
            ->map(fn (TaxonomyCategory $node) => $node->nameIn('es'))
            ->implode(' > '))
            ->all();
    }

    /**
     * Etiqueta de la opción en el selector de la empresa: ruta, código y motivo. Ejemplo:
     * «Terminación de Pozos > Equipos > Balancín · CPV-29.04.02G · por nombre».
     */
    public static function optionLabel(array $result): string
    {
        $reasons = collect($result['reasons'] ?? [])
            ->map(fn (array $reason) => TaxonomyLookup::describeForCompany($reason))
            ->unique()
            ->take(2)
            ->implode('; ');

        return $result['breadcrumb'].' · '.$result['code'].($reasons !== '' ? ' · '.$reasons : '');
    }

    private function semantic(string $query, int $limit, array $excludeIds): Collection
    {
        $vector = app(EmbeddingClient::class)->embed($query);

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

}
