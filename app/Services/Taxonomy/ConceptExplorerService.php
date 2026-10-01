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
 * TASK-0006A re-audit (comentario `5930560603`): la primera versión devolvía `total`/`truncated`
 * pero la UI llamaba solo a `searchOptions()`, que los descartaba - así que el revisor seguía viendo
 * como máximo N opciones SIN ninguna señal de que existían más, y sin forma de alcanzarlas. Esta
 * versión es PAGINADA de punta a punta: cada respuesta declara `total`, `page`, `last_page` y
 * `has_more`, y existe una página siguiente real para cada coincidencia. Lo mismo para las categorías
 * CPV del panel de diagnóstico, que además se pueden filtrar por texto.
 *
 * El tope NO se subió al tamaño actual del catálogo (79): la paginación tiene que seguir siendo
 * correcta cuando el catálogo crezca, así que el tamaño de página es chico y estable a propósito.
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
    /** Conceptos por página del explorador. Chico a propósito - ver docblock de la clase. */
    public const CONCEPTS_PER_PAGE = 25;

    /** Categorías CPV por página del panel de diagnóstico. */
    public const CATEGORIES_PER_PAGE = 12;

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
     * miembro (sección A.4 del comentario que abrió la tarea).
     *
     * Con `$search` vacío devuelve el catálogo activo ordenado por nombre, así que el revisor puede
     * NAVEGAR el conjunto completo página por página y no solo buscar a ciegas.
     *
     * El filtro `status=active` corrige un defecto real del selector anterior, que no lo tenía:
     * ofrecía también conceptos `merged`, que no son un destino de mapeo válido.
     *
     * @return array{
     *     total:int, per_page:int, page:int, last_page:int, shown:int,
     *     has_more:bool, has_previous:bool, first_index:int, last_index:int,
     *     concepts:Collection<int,TaxonomyCanonicalConcept>
     * }
     */
    public function searchActiveConcepts(?string $search = null, int $perPage = self::CONCEPTS_PER_PAGE, int $page = 1): array
    {
        $perPage = max(1, $perPage);
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
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);

        $concepts = $query
            ->orderByRaw('coalesce(canonical_name_es, canonical_name_en) asc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return [
            'total' => $total,
            'per_page' => $perPage,
            'page' => $page,
            'last_page' => $lastPage,
            'shown' => $concepts->count(),
            'has_more' => $page < $lastPage,
            'has_previous' => $page > 1,
            'first_index' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
            'last_index' => (($page - 1) * $perPage) + $concepts->count(),
            'concepts' => $concepts,
        ];
    }

    /**
     * Opciones id => etiqueta para un `Select` de Filament, correspondientes a UNA página concreta.
     *
     * La UI no usa esto como único camino de descubrimiento: combina esta página con los controles
     * de paginación, de modo que cada coincidencia es alcanzable. Ver
     * `TaxonomyCandidateConceptLinkResource::freezeReviewForm()`.
     */
    public function searchOptions(?string $search = null, int $perPage = self::CONCEPTS_PER_PAGE, int $page = 1): array
    {
        return $this->searchActiveConcepts($search, $perPage, $page)['concepts']
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
     * Texto de estado del explorador, pensado para mostrarse EN LA UI (no solo devolverse).
     *
     * TASK-0006A re-audit (`5930560603`), corrección 1: el overflow tiene que ser visible para el
     * revisor. Esta línea declara cuántas coincidencias hay en total, qué rango se está viendo y en
     * qué página, y cuando hay más avisa explícitamente que se siga paginando.
     *
     * @param  array<string, mixed>  $result
     */
    public function explorerStatusLine(array $result): string
    {
        if ($result['total'] === 0) {
            return 'Sin coincidencias en el catálogo de conceptos activos. Probá otro texto o vaciá la búsqueda para navegar el catálogo completo.';
        }

        $line = sprintf(
            'Mostrando %d-%d de %d coincidencias (página %d de %d).',
            $result['first_index'],
            $result['last_index'],
            $result['total'],
            $result['page'],
            $result['last_page'],
        );

        if ($result['has_more'] || $result['has_previous']) {
            $line .= ' Usá "Página siguiente"/"Página anterior" para recorrer TODAS las coincidencias: ninguna queda oculta.';
        }

        return $line;
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
     * TASK-0006A re-audit (`5930560603`), corrección 2: las categorías se PAGINAN y se pueden
     * FILTRAR por texto (código o nombre traducido), así que el revisor puede inspeccionar las que
     * antes quedaban fuera del preview. Declarar "y N más" no alcanzaba para una decisión de
     * gobernanza.
     *
     * @return array<string, mixed>
     */
    public function diagnostics(
        TaxonomyCanonicalConcept $concept,
        int $categoryPerPage = self::CATEGORIES_PER_PAGE,
        int $categoryPage = 1,
        ?string $categorySearch = null,
    ): array {
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

        $categoryPageData = $this->paginateCategories($categoryIds, $categoryPerPage, $categoryPage, $categorySearch);

        $impact = $this->builder->predictAffectedCompanies($categoryIds);

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
            'impact' => $impact,
            'warnings' => $this->warningsFor($concept, $memberTermIds, $categoryPageData['category_total'], $impact),
            ...$categoryPageData,
        ];
    }

    /**
     * Página de categorías CPV, opcionalmente filtrada por código o nombre traducido.
     *
     * `category_total` es el total SIN filtro (la cantidad real de categorías alcanzables, que es el
     * dato de gobernanza) y `category_matching` el total que coincide con el filtro aplicado - los
     * dos se informan para que un filtro no pueda hacer parecer que hay menos categorías de las que
     * realmente hay.
     *
     * @param  array<int>  $categoryIds
     * @return array<string, mixed>
     */
    private function paginateCategories(array $categoryIds, int $perPage, int $page, ?string $search): array
    {
        $perPage = max(1, $perPage);
        $term = trim((string) $search);

        $base = TaxonomyCategory::query()->whereIn('id', $categoryIds ?: [0]);
        $total = (clone $base)->count();

        $filtered = clone $base;
        if ($term !== '') {
            $like = '%'.$term.'%';
            $filtered->where(function ($q) use ($like) {
                $q->where('code', 'ilike', $like)
                    ->orWhereExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')
                        ->from('taxonomy_category_translations as ct')
                        ->whereColumn('ct.category_id', 'taxonomy_categories.id')
                        ->where('ct.name', 'ilike', $like));
            });
        }

        $matching = (clone $filtered)->count();
        $lastPage = max(1, (int) ceil($matching / $perPage));
        $page = min(max(1, $page), $lastPage);

        $categories = $filtered
            ->with('translations')
            ->orderBy('path')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return [
            'category_total' => $total,
            'category_matching' => $matching,
            'category_filtered' => $term !== '',
            'category_per_page' => $perPage,
            'category_page' => $page,
            'category_last_page' => $lastPage,
            'category_has_more' => $page < $lastPage,
            'category_has_previous' => $page > 1,
            'category_first_index' => $matching === 0 ? 0 : (($page - 1) * $perPage) + 1,
            'category_last_index' => (($page - 1) * $perPage) + $categories->count(),
            'categories' => $categories,
        ];
    }

    /**
     * Línea de estado de las categorías CPV, para mostrarse EN LA UI igual que la del explorador.
     *
     * @param  array<string, mixed>  $d
     */
    public function categoryStatusLine(array $d): string
    {
        if ($d['category_total'] === 0) {
            return 'Ninguna categoría CPV alcanzable: ningún término miembro tiene relaciones CPV aprobadas.';
        }

        if ($d['category_matching'] === 0) {
            return sprintf(
                'El filtro no coincide con ninguna de las %d categorías alcanzables. Vaciá el filtro para verlas todas.',
                $d['category_total'],
            );
        }

        $line = sprintf(
            'Mostrando %d-%d de %d%s (página %d de %d).',
            $d['category_first_index'],
            $d['category_last_index'],
            $d['category_matching'],
            $d['category_filtered'] ? sprintf(' coincidencias, sobre %d categorías alcanzables en total', $d['category_total']) : ' categorías CPV alcanzables',
            $d['category_page'],
            $d['category_last_page'],
        );

        if ($d['category_has_more'] || $d['category_has_previous']) {
            $line .= ' Paginá o filtrá para inspeccionar TODAS: ninguna queda sin poder revisarse.';
        }

        return $line;
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
