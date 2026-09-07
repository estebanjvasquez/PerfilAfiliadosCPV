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
