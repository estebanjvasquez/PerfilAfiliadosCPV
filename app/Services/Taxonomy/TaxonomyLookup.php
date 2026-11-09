<?php

namespace App\Services\Taxonomy;

use App\Models\TaxonomyCategory;
use App\Models\TaxonomyTermCpvRelation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * TASK-0014 (Issue #2 `6736283026`): UN solo criterio de coincidencia para la taxonomía CPV, usado
 * por el selector de la empresa (`TaxonomyCategorySearch`) y por los listados de administración
 * (Categorías, Familias, Grupos).
 *
 * Antes cada superficie buscaba distinto. Caso reproducido: «balancin» encontraba «Balancín» en la
 * ficha de la empresa (unaccent + ilike) y no en Administración (`like`, que en Postgres distingue
 * mayúsculas y tildes).
 *
 * **Campos**, siempre con `unaccent` y sin distinguir mayúsculas:
 * - código;
 * - nombre ES y nombre EN;
 * - sinónimo de la categoría (`taxonomy_category_synonyms`);
 * - término, término canónico o alias del diccionario (`taxonomy_terms`/`taxonomy_term_aliases`),
 *   a través de su relación término→CPV.
 *
 * **Motivo:** cada coincidencia trae el campo, el texto que coincidió, el estado de la relación del
 * diccionario si la hay, y la calidad:
 * - 0, el texto completo es igual;
 * - 1, empieza una palabra («balanc» en «Balancín»);
 * - 2, está dentro de una palabra («balanc» en «Bajobalanceada»).
 * Se ordena por calidad, así una subcadena accidental queda detrás y se rotula como tal.
 *
 * **Alcances:**
 * - `company`: solo categorías activas, sin Grupos (nivel 0), y del diccionario solo relaciones
 *   `approved`. Es lo que la empresa puede elegir.
 * - `admin`: todos los estados y todos los niveles; las relaciones del diccionario se ven con su
 *   estado, sean o no aprobadas. Sin límite de selector.
 *
 * La similitud semántica NO vive acá: es una sugerencia y la agrega `TaxonomyCategorySearch`
 * rotulada como tal. Este servicio nunca crea ni modifica relaciones.
 */
class TaxonomyLookup
{
    public const SCOPE_COMPANY = 'company';

    public const SCOPE_ADMIN = 'admin';

    public const KIND_CODE = 'code';

    public const KIND_NAME_ES = 'name_es';

    public const KIND_NAME_EN = 'name_en';

    public const KIND_SYNONYM = 'synonym';

    public const KIND_TERM = 'term';

    public const KIND_CANONICAL = 'canonical';

    public const KIND_ALIAS = 'alias';

    public const KIND_SEMANTIC = 'semantic';

    public const QUALITY_EXACT = 0;

    public const QUALITY_WORD_START = 1;

    public const QUALITY_INSIDE_WORD = 2;

    public const RELATION_STATUS_LABELS = [
        TaxonomyTermCpvRelation::STATUS_CANDIDATE => 'candidata',
        TaxonomyTermCpvRelation::STATUS_AUTO_MAPPED => 'auto-mapeada',
        TaxonomyTermCpvRelation::STATUS_NEEDS_REVIEW => 'en revisión',
        TaxonomyTermCpvRelation::STATUS_APPROVED => 'aprobada',
        TaxonomyTermCpvRelation::STATUS_REJECTED => 'rechazada',
        TaxonomyTermCpvRelation::STATUS_DEPRECATED => 'descartada',
    ];

    /** @var array<string, array<int, list<string>>> memo de motivos por búsqueda y nivel (una petición). */
    private array $reasonMemo = [];

    /**
     * Categorías que coinciden, una fila por categoría, de la mejor coincidencia a la peor.
     *
     * @param  list<int>|null  $onlyIds  restringe a esas categorías (para explicar filas ya listadas)
     * @return Collection<int, array{category_id:int, code:string, level:int, is_active:bool, quality:int, tier:int, dictionary_score:?float, reasons:list<array{kind:string, matched:string, quality:int, relation_status:?string}>}>
     */
    public function matches(string $query, string $scope, ?int $limit = null, ?array $onlyIds = null): Collection
    {
        $query = trim($query);

        if ($query === '' || ($onlyIds !== null && $onlyIds === [])) {
            return collect();
        }

        [$matchSql, $bindings] = $this->matchCte($query, $scope);

        $restrict = '';
        if ($onlyIds !== null) {
            $restrict = 'and m.category_id = any(?::bigint[])';
            $bindings[] = '{'.implode(',', array_map('intval', $onlyIds)).'}';
        }

        $scopeFilter = $scope === self::SCOPE_COMPANY
            ? 'and tc.is_active = true and tc.level != '.TaxonomyCategory::LEVEL_GROUP
            : '';

        $limitSql = '';
        if ($limit !== null) {
            $limitSql = 'limit ?';
            $bindings[] = $limit;
        }

        $rows = DB::connection('pgsql')->select(<<<SQL
            {$matchSql}
            select tc.id as category_id, tc.code, tc.level, tc.is_active,
                min(m.quality) as quality,
                min(case when m.kind in ('term', 'canonical', 'alias') then 1 else 0 end) as tier,
                max(m.dictionary_score) as dictionary_score,
                json_agg(json_build_object(
                    'kind', m.kind, 'matched', m.matched, 'quality', m.quality, 'relation_status', m.relation_status
                ) order by m.quality, m.kind, m.matched) as reasons
            from m
            join taxonomy_categories tc on tc.id = m.category_id
            where true {$scopeFilter} {$restrict}
            group by tc.id, tc.code, tc.level, tc.is_active
            order by quality, tier, dictionary_score desc nulls last, tc.level desc, tc.code
            {$limitSql}
        SQL, $bindings);

        return collect($rows)->map(fn ($row) => [
            'category_id' => (int) $row->category_id,
            'code' => $row->code,
            'level' => (int) $row->level,
            'is_active' => (bool) $row->is_active,
            'quality' => (int) $row->quality,
            'tier' => (int) $row->tier,
            'dictionary_score' => $row->dictionary_score === null ? null : (float) $row->dictionary_score,
            'reasons' => $this->uniqueReasons(json_decode($row->reasons, true) ?: []),
        ]);
    }

    /**
     * Restringe un listado de administración a lo que coincide con UNA palabra de búsqueda.
     *
     * Jerarquía: una Familia aparece también si coincide una de sus categorías, y un Grupo si
     * coincide una de sus familias o categorías. Así, desde una coincidencia de categoría se
     * localiza su familia y su grupo en los otros dos listados.
     */
    public function constrainAdminQuery(Builder $query, string $word): Builder
    {
        [$matchSql, $bindings] = $this->matchCte(trim($word), self::SCOPE_ADMIN);
        $table = $query->getModel()->getTable();

        return $query->whereRaw(<<<SQL
            {$table}.id in (
                {$matchSql}
                select category_id from m
                union
                select c.parent_id from taxonomy_categories c where c.id in (select category_id from m)
                union
                select f.parent_id from taxonomy_categories c
                    join taxonomy_categories f on f.id = c.parent_id
                    where c.id in (select category_id from m)
            )
        SQL, $bindings);
    }

    /**
     * Motivos legibles para una fila de un listado de administración, incluidas las coincidencias de
     * sus descendientes. Calcula una vez por búsqueda los motivos de todas las filas de la página.
     *
     * @param  list<int>  $pageIds
     * @return list<string>
     */
    public function adminReasonsFor(int $categoryId, ?string $search, array $pageIds): array
    {
        $words = self::searchWords((string) $search);

        if ($words === []) {
            return [];
        }

        $key = implode("\x1f", $words);
        $ids = array_values(array_unique(array_map('intval', array_merge($pageIds, [$categoryId]))));
        $missing = array_values(array_diff($ids, array_keys($this->reasonMemo[$key] ?? [])));

        if ($missing !== []) {
            $this->reasonMemo[$key] = ($this->reasonMemo[$key] ?? []) + $this->computeAdminReasons($missing, $words);
        }

        return $this->reasonMemo[$key][$categoryId] ?? [];
    }

    /**
     * Las palabras en que Filament divide la búsqueda de una tabla (mismo criterio que
     * `CanSearchRecords::extractTableSearchWords`): cada palabra debe coincidir por separado.
     *
     * @return list<string>
     */
    public static function searchWords(string $search): array
    {
        return array_values(array_filter(
            str_getcsv(preg_replace('/\s+/', ' ', trim($search)), separator: ' ', escape: '\\'),
            fn ($word): bool => filled($word),
        ));
    }

    /** Texto del motivo para la administración: campo, texto que coincidió y estado de la relación. */
    public static function describeForAdmin(array $reason): string
    {
        $matched = $reason['matched'] ?? '';
        $status = $reason['relation_status'] ?? null;
        $relation = $status ? ' — relación '.(self::RELATION_STATUS_LABELS[$status] ?? $status) : '';

        $text = match ($reason['kind']) {
            self::KIND_CODE => "Código {$matched}",
            self::KIND_NAME_ES => "Nombre ES «{$matched}»",
            self::KIND_NAME_EN => "Nombre EN «{$matched}»",
            self::KIND_SYNONYM => "Sinónimo de la categoría «{$matched}»",
            self::KIND_TERM => "Término del diccionario «{$matched}»{$relation}",
            self::KIND_CANONICAL => "Término canónico del diccionario «{$matched}»{$relation}",
            self::KIND_ALIAS => "Alias del diccionario «{$matched}»{$relation}",
            self::KIND_SEMANTIC => 'Sugerencia por similitud (no es un vínculo aprobado)',
            default => $matched,
        };

        return $text.self::qualitySuffix($reason);
    }

    /**
     * Texto corto del motivo para la empresa. En su alcance las relaciones del diccionario siempre
     * están aprobadas; la similitud se dice como sugerencia, nunca como prueba de capacidad.
     */
    public static function describeForCompany(array $reason): string
    {
        $matched = $reason['matched'] ?? '';

        $text = match ($reason['kind']) {
            self::KIND_CODE => 'por código',
            self::KIND_NAME_ES => 'por nombre',
            // La ruta se muestra en español: sin esto, «Bola» aparecía «por nombre» al buscar «bal».
            self::KIND_NAME_EN => "por nombre en inglés «{$matched}»",
            self::KIND_SYNONYM => "por sinónimo «{$matched}»",
            self::KIND_TERM, self::KIND_CANONICAL, self::KIND_ALIAS => "término aprobado del diccionario «{$matched}»",
            self::KIND_SEMANTIC => 'sugerencia por similitud, sin confirmar',
            default => $matched,
        };

        return $text.self::qualitySuffix($reason);
    }

    private static function qualitySuffix(array $reason): string
    {
        return (int) ($reason['quality'] ?? 0) === self::QUALITY_INSIDE_WORD ? ' (parte de una palabra)' : '';
    }

    /**
     * CTE `m(category_id, kind, matched, relation_status, dictionary_score, quality)` con UNA
     * consulta de texto.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function matchCte(string $query, string $scope): array
    {
        $like = '%'.addcslashes($query, '\\%_').'%';
        $wordRegex = '\\m'.preg_replace('/[.*+?^${}()|\[\]\\\\]/', '\\\\$0', $query);

        $relationStatus = $scope === self::SCOPE_COMPANY
            ? "and r.status = '".TaxonomyTermCpvRelation::STATUS_APPROVED."'"
            : '';

        $dictionaryScore = 'greatest(0, r.weight * t.oil_gas_exclusivity - case when t.context_required then t.ambiguity_penalty else 0 end)';

        $sql = <<<SQL
            with p as (
                select unaccent(?::text) as q_like, lower(unaccent(?::text)) as q_exact, lower(unaccent(?::text)) as q_word
            ),
            raw as (
                select tc.id as category_id, 'code'::text as kind, tc.code::text as matched,
                    null::text as relation_status, null::numeric as dictionary_score
                from taxonomy_categories tc, p
                where unaccent(tc.code) ilike p.q_like
                union all
                select t.category_id, 'name_' || t.locale, t.name, null, null
                from taxonomy_category_translations t, p
                where t.locale in ('es', 'en') and unaccent(t.name) ilike p.q_like
                union all
                select s.category_id, 'synonym', s.term, null, null
                from taxonomy_category_synonyms s, p
                where unaccent(s.term) ilike p.q_like
                union all
                select r.category_id, 'term', t.term, r.status, {$dictionaryScore}
                from taxonomy_term_cpv_relations r join taxonomy_terms t on t.id = r.term_id, p
                where r.category_id is not null {$relationStatus} and unaccent(t.term) ilike p.q_like
                union all
                select r.category_id, 'canonical', t.canonical_term, r.status, {$dictionaryScore}
                from taxonomy_term_cpv_relations r join taxonomy_terms t on t.id = r.term_id, p
                where r.category_id is not null {$relationStatus} and unaccent(coalesce(t.canonical_term, '')) ilike p.q_like
                union all
                select r.category_id, 'alias', a.alias, r.status, {$dictionaryScore}
                from taxonomy_term_cpv_relations r
                    join taxonomy_terms t on t.id = r.term_id
                    join taxonomy_term_aliases a on a.term_id = t.id, p
                where r.category_id is not null {$relationStatus} and unaccent(a.alias) ilike p.q_like
            ),
            m as (
                select raw.*,
                    case
                        when lower(unaccent(raw.matched)) = p.q_exact then 0
                        when raw.kind = 'code' and lower(unaccent(raw.matched)) = 'cpv-' || p.q_exact then 0
                        when lower(unaccent(raw.matched)) ~ p.q_word then 1
                        else 2
                    end as quality
                from raw, p
            )
        SQL;

        return [$sql, [$like, $query, $wordRegex]];
    }

    /**
     * @param  list<int>  $ids
     * @param  list<string>  $words
     * @return array<int, list<string>>
     */
    private function computeAdminReasons(array $ids, array $words): array
    {
        $family = TaxonomyCategory::query()
            ->whereIn('parent_id', $ids)
            ->get(['id', 'parent_id', 'code', 'is_active']);
        $grandchildren = TaxonomyCategory::query()
            ->whereIn('parent_id', $family->pluck('id'))
            ->get(['id', 'parent_id', 'code', 'is_active']);

        /** @var array<int, int> $ownerOf descendiente => fila del listado */
        $ownerOf = [];
        foreach ($family as $child) {
            $ownerOf[$child->id] = (int) $child->parent_id;
        }
        foreach ($grandchildren as $grandchild) {
            $ownerOf[$grandchild->id] = $ownerOf[(int) $grandchild->parent_id];
        }

        $candidateIds = array_values(array_unique(array_merge($ids, array_keys($ownerOf))));
        $codes = $family->concat($grandchildren)->pluck('code', 'id');

        $direct = [];
        $viaDescendant = [];

        foreach ($words as $word) {
            foreach ($this->matches($word, self::SCOPE_ADMIN, null, $candidateIds) as $match) {
                $text = collect($match['reasons'])->map(fn ($r) => self::describeForAdmin($r))->all();

                if (in_array($match['category_id'], $ids, true)) {
                    $direct[$match['category_id']] = array_merge($direct[$match['category_id']] ?? [], $text);
                }

                if (isset($ownerOf[$match['category_id']])) {
                    $owner = $ownerOf[$match['category_id']];
                    $viaDescendant[$owner][] = 'Contiene '.($codes[$match['category_id']] ?? $match['code'])
                        .($match['is_active'] ? '' : ' (inactiva)').': '.$text[0];
                }
            }
        }

        $result = [];
        foreach ($ids as $id) {
            $lines = array_values(array_unique($direct[$id] ?? []));
            $descendants = array_values(array_unique($viaDescendant[$id] ?? []));

            if (count($descendants) > 3) {
                $extra = count($descendants) - 3;
                $descendants = array_merge(array_slice($descendants, 0, 3), ["… y {$extra} coincidencia(s) más en sus hijas"]);
            }

            $result[$id] = array_merge($lines, $descendants);
        }

        return $result;
    }

    /** @return list<array{kind:string, matched:string, quality:int, relation_status:?string}> */
    private function uniqueReasons(array $reasons): array
    {
        $seen = [];
        $unique = [];

        foreach ($reasons as $reason) {
            $key = $reason['kind'].'|'.mb_strtolower((string) $reason['matched']).'|'.($reason['relation_status'] ?? '');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = [
                'kind' => (string) $reason['kind'],
                'matched' => (string) $reason['matched'],
                'quality' => (int) $reason['quality'],
                'relation_status' => $reason['relation_status'] ?? null,
            ];
        }

        return $unique;
    }
}
