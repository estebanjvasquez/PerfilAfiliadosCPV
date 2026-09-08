<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Nodo de la taxonomía CPV (Grupo/Familia/Categoría) — reemplaza a `SupplierCategory` tras el
 * acuerdo del 7 sep 2026 con datos reales de Lorenzo (ver docs/taxonomia/
 * acuerdos_pendientes_con_lorenzo.md). Solo 3 niveles son nodos reales del árbol: `branch`/
 * `subbranch` son etiquetas de texto sin código propio (Lorenzo no los codifica de forma única —
 * el mismo texto se repite en familias distintas), viven como columnas planas de la Categoría, no
 * como parent_id propio.
 *
 * `level`/`path` los mantiene TaxonomyCategoryObserver automáticamente — no asignarlos a mano
 * fuera de un import/seeder controlado.
 */
class TaxonomyCategory extends Model
{
    protected $connection = 'pgsql';

    public const RELEVANCE_BELONGS = 'belongs';
    public const RELEVANCE_MAYBE = 'maybe';
    public const RELEVANCE_DOES_NOT_BELONG = 'does_not_belong';

    public const TIPO_BIEN = 'bien';
    public const TIPO_SERVICIO = 'servicio';

    public const LEVEL_GROUP = 0;
    public const LEVEL_FAMILY = 1;
    public const LEVEL_CATEGORY = 2;

    public const CODE_PREFIX = 'CPV-';

    protected $fillable = [
        'code',
        'parent_id',
        'level',
        'path',
        'tipo_oferta',
        'branch',
        'subbranch',
        'chamber_relevance',
        'relevance_basis',
        'is_active',
        'source_version',
    ];

    protected $casts = [
        'level' => 'integer',
        'is_active' => 'boolean',
    ];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function translations()
    {
        return $this->hasMany(TaxonomyCategoryTranslation::class, 'category_id');
    }

    public function synonyms()
    {
        return $this->hasMany(TaxonomyCategorySynonym::class, 'category_id');
    }

    public function empresaLinks()
    {
        return $this->hasMany(EmpresaTaxonomyCategory::class, 'category_id');
    }

    /**
     * Nombre traducido a un idioma, con fallback al `code` si no hay traducción cargada para ese
     * locale (no debería pasar con datos completos, pero evita romper la UI mientras la
     * traducción al español —a cargo de Lorenzo, ver acuerdo punto 5— todavía no llegó).
     */
    public function nameIn(string $locale): string
    {
        return $this->translations->firstWhere('locale', $locale)?->name ?? $this->code;
    }

    /**
     * Nombre para mostrarle al ADMINISTRADOR (selects/dropdowns del panel, no la vista del
     * afiliado) — español si ya está cargado, si no inglés (que sí viene siempre desde el Excel
     * de Lorenzo), y solo si no hay ninguno de los dos cae al código. Encontrado el 8 sep 2026: los
     * selectores de Grupo/Familia usaban `nameIn('es')` directo, que cae al `code` en vez de al
     * nombre en inglés cuando la traducción a español todavía no llegó (el caso de HOY, para casi
     * toda la taxonomía) — mostraba "CPV-05 — CPV-05" en vez de "CPV-05 — Valves".
     */
    public function displayName(): string
    {
        return $this->translations->firstWhere('locale', 'es')?->name
            ?? $this->translations->firstWhere('locale', 'en')?->name
            ?? $this->code;
    }

    /**
     * Cadena de nodos desde la raíz (Grupo) hasta este, resuelta a partir de `path` (codes
     * separados por '/') en una sola consulta — igual patrón que la Fase 4 original, para no
     * disparar 1 consulta por nivel al armar un breadcrumb.
     */
    public function ancestorsAndSelf(): Collection
    {
        $codes = explode('/', $this->path);

        $byCode = static::query()
            ->whereIn('code', $codes)
            ->with('translations')
            ->get()
            ->keyBy('code');

        return collect($codes)->map(fn ($code) => $byCode->get($code))->filter()->values();
    }

    /** "Grupo > Familia > Categoría" en el idioma pedido. */
    public function breadcrumb(string $locale): string
    {
        return $this->ancestorsAndSelf()
            ->map(fn (self $category) => $category->nameIn($locale))
            ->implode(' > ');
    }

    /** Quita el prefijo "CPV-" si está — para comparar códigos por su jerarquía numérica. */
    public static function stripCodePrefix(string $code): string
    {
        return str_starts_with($code, self::CODE_PREFIX) ? substr($code, strlen(self::CODE_PREFIX)) : $code;
    }

    /**
     * True si $childCode es hijo DIRECTO de $parentCode según la jerarquía numérica del propio
     * código (ej. "CPV-05.01.01G" es hijo de "CPV-05.01", que es hijo de "CPV-05") — independiente
     * de lo que diga `parent_id` en la base. Pedido explícito de Lorenzo (7 sep 2026): "no se debe
     * poder crear una categoría 05.01.01 y asociarle una familia distinta de 05.01, e igual debe
     * validarse que la familia 05.01 esté dentro del grupo 05". Se usa en
     * TaxonomyCategoryObserver para bloquear guardar un parent_id que no corresponda al código.
     */
    public static function codeBelongsToParent(string $childCode, string $parentCode): bool
    {
        // Solo las categorías (hojas) llevan el sufijo G/S pegado al final del código — quitarlo
        // acá para poder comparar segmento a segmento contra el padre (Grupo/Familia), que nunca
        // lo tiene.
        $child = preg_replace('/[GS]$/i', '', self::stripCodePrefix($childCode));
        $parent = self::stripCodePrefix($parentCode);

        $childSegments = explode('.', $child);
        $parentSegments = explode('.', $parent);

        if (count($childSegments) !== count($parentSegments) + 1) {
            return false;
        }

        return array_slice($childSegments, 0, count($parentSegments)) === $parentSegments;
    }

    /**
     * Detección de ciclos en `parent_id` — mismo mecanismo (y misma corrección de la condición de
     * corte) que `SupplierCategory::detectCycles()` de la Fase 4 original, portado al nombre de
     * tabla nuevo.
     */
    public static function detectCycles(): Collection
    {
        return collect(DB::connection('pgsql')->select('
            WITH RECURSIVE chain AS (
                SELECT id AS start_id, id AS current_id, parent_id, 1 AS depth
                FROM taxonomy_categories
                UNION ALL
                SELECT chain.start_id, tc.id, tc.parent_id, chain.depth + 1
                FROM taxonomy_categories tc
                JOIN chain ON tc.id = chain.parent_id
                WHERE chain.depth < 200
                  AND NOT (chain.depth > 1 AND chain.current_id = chain.start_id)
            )
            SELECT DISTINCT start_id AS id FROM chain WHERE current_id = start_id AND depth > 1
        '))->pluck('id');
    }
}
