<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Nodo del catalogo SupplHi (grupo/categoria/subcategoria/item). Ver
 * database/migrations/2026_09_01_120000_create_supplier_categories_table.php para el detalle de
 * cada columna. `level`/`path` los mantiene SupplierCategoryObserver automaticamente - no
 * asignarlos a mano fuera de un import/seeder controlado.
 */
class SupplierCategory extends Model
{
    protected $connection = 'pgsql';

    protected $fillable = [
        'supplhi_code',
        'parent_id',
        'level',
        'path',
        'tipo_oferta',
        'version',
    ];

    protected $casts = [
        'level' => 'integer',
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
        return $this->hasMany(SupplierCategoryTranslation::class, 'category_id');
    }

    public function synonyms()
    {
        return $this->hasMany(SupplierCategorySynonym::class, 'category_id');
    }

    public function empresaLinks()
    {
        return $this->hasMany(EmpresaSupplierCategory::class, 'category_id');
    }

    public function catalogItems()
    {
        return $this->belongsToMany(
            EmpresaCatalogItem::class,
            'empresa_catalog_item_category',
            'category_id',
            'catalog_item_id'
        )->withTimestamps();
    }

    /**
     * Nombre traducido a un idioma, con fallback al supplhi_code si no hay traduccion cargada
     * para ese locale (nunca debe pasar con datos completos, pero evita romper la UI mientras la
     * importacion este parcial).
     */
    public function nameIn(string $locale): string
    {
        return $this->translations->firstWhere('locale', $locale)?->name ?? $this->supplhi_code;
    }

    /**
     * Cadena de nodos desde la raiz hasta este, resuelta a partir de `path` (codes separados por
     * '/') en una sola consulta - pensado para no disparar 1 consulta por nivel al armar un
     * breadcrumb (ver la nota de rendimiento de Empresa::moduleBreakdown() para el porque de esta
     * precaucion).
     */
    public function ancestorsAndSelf(): Collection
    {
        $codes = explode('/', $this->path);

        $byCode = static::query()
            ->whereIn('supplhi_code', $codes)
            ->with('translations')
            ->get()
            ->keyBy('supplhi_code');

        return collect($codes)->map(fn ($code) => $byCode->get($code))->filter()->values();
    }

    /**
     * "Grupo > Categoria > Subcategoria > Item" en el idioma pedido (PDF punto 2/4). Ver
     * ancestorsAndSelf() para como se resuelve la cadena sin N+1.
     */
    public function breadcrumb(string $locale): string
    {
        return $this->ancestorsAndSelf()
            ->map(fn (self $category) => $category->nameIn($locale))
            ->implode(' > ');
    }

    /**
     * Detecta ciclos en parent_id (verificacion de Fase 4 del plan: "query de deteccion de ciclos
     * ... devuelve 0 filas"). Uso esperado: un chequeo de integridad puntual despues de importar/
     * editar categorias a mano, no una consulta de uso frecuente. El limite de profundidad (200,
     * bastante mas que cualquier jerarquia real de SupplHi) es una salvaguarda para que una
     * recursion infinita no cuelgue la base si de verdad hay un ciclo, en vez de la condicion de
     * corte normal (llegar a un parent_id NULL).
     *
     * Ojo con la condicion de corte `NOT (depth > 1 AND current_id = start_id)`: en la fila BASE
     * (depth=1), `current_id` ya es igual a `start_id` por construccion (ambos son el mismo nodo
     * de partida) - un primer intento que cortaba con solo `current_id <> start_id` bloqueaba la
     * recursion desde el primer paso para TODOS los nodos (0 resultados siempre, incluso con un
     * ciclo real armado a proposito para probarlo). Se corrige exigiendo ademas `depth > 1`, para
     * que el corte solo aplique DESPUES de confirmar que se volvio al punto de partida, no en la
     * fila inicial donde eso es trivialmente cierto.
     */
    public static function detectCycles(): Collection
    {
        return collect(DB::connection('pgsql')->select('
            WITH RECURSIVE chain AS (
                SELECT id AS start_id, id AS current_id, parent_id, 1 AS depth
                FROM supplier_categories
                UNION ALL
                SELECT chain.start_id, sc.id, sc.parent_id, chain.depth + 1
                FROM supplier_categories sc
                JOIN chain ON sc.id = chain.parent_id
                WHERE chain.depth < 200
                  AND NOT (chain.depth > 1 AND chain.current_id = chain.start_id)
            )
            SELECT DISTINCT start_id AS id FROM chain WHERE current_id = start_id AND depth > 1
        '))->pluck('id');
    }
}
