<?php

namespace App\Services\Taxonomy;

use App\Models\TaxonomyCanonicalConcept;
use App\Models\TaxonomyCategory;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * TASK-0006A (Issue #2 comentario `5929287629`): evidencia de SOLO LECTURA para que un revisor
 * humano pueda descubrir y evaluar el espacio completo de conceptos canónicos antes de congelar un
 * MAP_TO_EXISTING, y entender qué implicaría ese mapeo en términos de CPV/categorías/empresas.
 *
 * Motivo (del propio comentario): revisando el término `pipeline` el revisor descubrió que el
 * selector solo ofrecía los duplicados sugeridos por el Builder más la sugerencia original, y que la
 * búsqueda remota truncaba a 20 resultados mostrando únicamente el nombre - o sea podía crear
 * falsa confianza de que las pocas opciones visibles eran las únicas válidas.
 *
 * CERO ESCRITURA. Este servicio solo compone datos ya gobernados:
 * - `taxonomy_canonical_concepts` (catálogo de conceptos);
 * - `taxonomy_term_concepts` (identidad término->concepto YA aprobada - su existencia ES la señal
 *   de publicación, la tabla no tiene columna de status);
 * - `taxonomy_term_cpv_relations` con `status='approved'` (único camino gobernado término->CPV);
 * - `taxonomy_categories` (jerarquía Grupo/Familia/Categoría) y sus traducciones;
 * - los helpers ya aprobados de `CanonicalConceptBuilderService` para el impacto de empresas.
 *
 * NO inventa relaciones nuevas ni cambia semántica de taxonomía. En particular NO expone un camino
 * TÉRMINO->CPV directo: la capa de concepto canónico sigue siendo la autoritativa, y el comentario
 * que abrió esta tarea lo marca como límite semántico explícito.
 */
class ConceptExplorerService
{
    /**
     * Tope de resultados que la búsqueda devuelve de una vez. No es una truncación silenciosa: el
     * resultado SIEMPRE informa el total real de coincidencias y si hubo overflow, para que la UI
     * pueda decirle al revisor que refine en vez de hacerle creer que vio todo (sección A.3 del
     * comentario). Reemplaza el `limit(20)` anterior, que no informaba nada.
     */
    public const SEARCH_RESULT_CAP = 50;

    /**
     * Cuántas categorías CPV se listan en detalle en el panel de diagnóstico antes de resumir el
     * resto como "y N más". Igual que arriba: el total real siempre se informa (sección B del
     * comentario: "provide a searchable/collapsible/paginated diagnostic representation rather than
     * silently truncating them").
     */
    public const CATEGORY_PREVIEW_LIMIT = 12;

    public function __construct(private CanonicalConceptBuilderService $builder) {}

    public function activeConceptCount(): int
    {
        return TaxonomyCanonicalConcept::query()
            ->where('status', TaxonomyCanonicalConcept::STATUS_ACTIVE)
            ->count();
    }

    /**
     * Busca sobre TODO el catálogo de conceptos ACTIVOS - no solo los duplicados sugeridos por el
     * Builder. Coincide por nombre canónico ES y EN, y además por término miembro o alias de término
     * miembro (sección A.4: "Search at minimum Spanish and English canonical names. Reuse existing
     * aliases/term/concept evidence where architecturally appropriate if it improves discovery").
     *
     * Con `$search` vacío devuelve el catálogo activo ordenado por nombre, así que el revisor puede
     * NAVEGAR el conjunto completo y no solo buscar a ciegas.
     *
     * El filtro `status=active` es nuevo y corrige un defecto real del selector anterior, que no lo
     * tenía: ofrecía también conceptos `merged`, que no son un destino de mapeo válido.
     *
     * @return array{total:int, shown:int, truncated:bool, concepts:Collection<int,TaxonomyCanonicalConcept>}
     */
    public function searchActiveConcepts(?string $search = null, int $cap = self::SEARCH_RESULT_CAP): array
    {
        $term = trim((string) $search);

        $query = TaxonomyCanonicalConcept::query()
            ->where('status', TaxonomyCanonicalConcept::STATUS_ACTIVE);

        if ($term !== '') {
            $like = '%'.$term.'%';
            $query->where(function ($q) use ($like) {
                $q->where('canonical_name_es', 'ilike', $like)
                    ->orWhere('canonical_name_en', 'ilike', $like)
                    ->orWhereExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')
                        ->from('taxonomy_term_concepts as tc_term')
                        ->join('taxonomy_terms as t_term', 't_term.id', '=', 'tc_term.term_id')
                        ->whereColumn('tc_term.concept_id', 'taxonomy_canonical_concepts.id')
                        ->where('t_term.term', 'ilike', $like))
                    ->orWhereExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')
                        ->from('taxonomy_term_concepts as tc_alias')
                        ->join('taxonomy_term_aliases as a_alias', 'a_alias.term_id', '=', 'tc_alias.term_id')
                        ->whereColumn('tc_alias.concept_id', 'taxonomy_canonical_concepts.id')
                        ->where('a_alias.alias', 'ilike', $like));
            });
        }

        $total = (clone $query)->count();

        $concepts = $query
            ->orderByRaw('coalesce(canonical_name_es, canonical_name_en) asc')
            ->limit($cap)
            ->get();

        return [
            'total' => $total,
            'shown' => $concepts->count(),
            'truncated' => $total > $concepts->count(),
            'concepts' => $concepts,
        ];
    }

    /** Opciones id => etiqueta para un `Select` de Filament, a partir de una búsqueda. */
    public function searchOptions(?string $search = null, int $cap = self::SEARCH_RESULT_CAP): array
    {
        return $this->searchActiveConcepts($search, $cap)['concepts']
            ->mapWithKeys(fn (TaxonomyCanonicalConcept $c) => [$c->id => $this->optionLabel($c)])
            ->all();
    }

    /** Etiqueta con identidad suficiente para distinguir homónimos: nombre(s) + id + tipo/dominio. */
    public function optionLabel(TaxonomyCanonicalConcept $concept): string
    {
        $extra = collect([$concept->concept_type, $concept->domain])->filter()->implode(', ');

        return $concept->display_name.' [#'.$concept->id.']'.($extra !== '' ? " — {$extra}" : '');
    }

    /**
     * IDs de términos miembro del concepto (los que ya tienen identidad aprobada con él vía
     * `taxonomy_term_concepts`).
     *
     * @return array<int>
     */
    public function memberTermIds(TaxonomyCanonicalConcept $concept): array
    {
        return DB::connection('pgsql')->table('taxonomy_term_concepts')
            ->where('concept_id', $concept->id)
            ->pluck('term_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Panel de diagnóstico de la sección B: todo lo que un revisor necesita para entender qué
     * significa este concepto y qué implicaría mapearle el término.
     *
     * Las categorías CPV se resuelven por el ÚNICO camino gobernado que ya usa el resto del
     * proyecto: términos miembro del concepto -> sus `taxonomy_term_cpv_relations` con
     * `status='approved'` -> `taxonomy_categories`. Es el mismo criterio que
     * `CanonicalConceptBuilderService::conceptApprovedCategoryIds()` y que la expansión por
     * hermanos de concepto que hace el índice de búsqueda - no una relación inventada acá.
     *
     * @return array{
     *     concept: TaxonomyCanonicalConcept,
     *     identity: array<string, mixed>,
     *     member_term_count: int,
     *     member_terms: Collection,
     *     alias_count: int,
     *     aliases: Collection,
     *     category_total: int,
     *     categories: Collection,
     *     category_overflow: int,
     *     impact: array<string, mixed>,
     *     warnings: array<int, string>
     * }
     */
    public function diagnostics(TaxonomyCanonicalConcept $concept, int $categoryPreviewLimit = self::CATEGORY_PREVIEW_LIMIT): array
    {
        $memberTermIds = $this->memberTermIds($concept);

        $memberTerms = DB::connection('pgsql')->table('taxonomy_terms')
            ->whereIn('id', $memberTermIds ?: [0])
            ->select('id', 'term', 'language', 'term_type', 'origin_type', 'display_source', 'context_required')
            ->orderBy('term')
            ->get();

        $aliases = DB::connection('pgsql')->table('taxonomy_term_aliases')
            ->whereIn('term_id', $memberTermIds ?: [0])
            ->select('term_id', 'alias')
            ->orderBy('alias')
            ->get();

        $categoryIds = $this->builder->conceptApprovedCategoryIds($memberTermIds);

        $categories = TaxonomyCategory::query()
            ->whereIn('id', $categoryIds ?: [0])
            ->with('translations')
            ->orderBy('path')
            ->get();

        $categoryTotal = $categories->count();
        $shownCategories = $categories->take($categoryPreviewLimit);

        $impact = $memberTermIds === []
            ? $this->builder->predictAffectedCompanies([])
            : $this->builder->predictAffectedCompanies($categoryIds);

        return [
            'concept' => $concept,
            'identity' => [
                'id' => $concept->id,
                'canonical_name_es' => $concept->canonical_name_es,
                'canonical_name_en' => $concept->canonical_name_en,
                'status' => $concept->status,
                'concept_type' => $concept->concept_type,
                'domain' => $concept->domain,
            ],
            'member_term_count' => count($memberTermIds),
            'member_terms' => $memberTerms,
            'alias_count' => $aliases->count(),
            'aliases' => $aliases,
            'category_total' => $categoryTotal,
            'categories' => $shownCategories,
            'category_overflow' => max(0, $categoryTotal - $shownCategories->count()),
            'impact' => $impact,
            'warnings' => $this->warningsFor($concept, $memberTermIds, $categoryTotal, $impact),
        ];
    }

    /**
     * Huecos de datos que el revisor debe ver explícitamente (sección B: "warnings for data gaps
     * where applicable"). No bloquean ninguna decisión - la decisión final es humana.
     *
     * @param  array<int>  $memberTermIds
     * @param  array<string, mixed>  $impact
     * @return array<int, string>
     */
    private function warningsFor(TaxonomyCanonicalConcept $concept, array $memberTermIds, int $categoryTotal, array $impact): array
    {
        $warnings = [];

        if ($concept->status !== TaxonomyCanonicalConcept::STATUS_ACTIVE) {
            $warnings[] = "Este concepto no está activo (status={$concept->status}) - no es un destino de mapeo válido.";
        }

        if ($memberTermIds === []) {
            $warnings[] = 'Este concepto todavía no tiene ningún término con identidad aprobada - no hay evidencia TÉRMINO→CONCEPTO que respalde su significado.';
        }

        if ($categoryTotal === 0) {
            $warnings[] = 'Ningún término miembro tiene relaciones CPV aprobadas, así que mapear acá hoy no alcanzaría ninguna categoría CPV por la expansión por hermanos de concepto.';
        }

        if ($concept->canonical_name_es === null || $concept->canonical_name_en === null) {
            $warnings[] = 'El concepto tiene solo uno de los dos nombres canónicos (ES/EN) - identidad bilingüe incompleta.';
        }

        foreach ($impact['data_gap_flags'] ?? [] as $flag) {
            $warnings[] = "Hueco de datos en el impacto predicho: {$flag}.";
        }

        return $warnings;
    }
}
