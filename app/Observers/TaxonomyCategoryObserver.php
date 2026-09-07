<?php

namespace App\Observers;

use App\Models\TaxonomyCategory;
use Illuminate\Validation\ValidationException;

/**
 * Mantiene `level`/`path` de TaxonomyCategory sincronizados con `parent_id`/`code`, y previene
 * ciclos en la jerarquía. `path` se arma con `code` (no con `id`) porque el código se conoce ANTES
 * del insert (lo trae el archivo de importación) — evita el problema de necesitar el id
 * autoincremental, que recién existe después de guardar, para armar el propio path del nodo que se
 * está creando. Portado de `SupplierCategoryObserver` (Fase 4 original) sin cambios de lógica,
 * solo de nombres.
 */
class TaxonomyCategoryObserver
{
    public function saving(TaxonomyCategory $category): void
    {
        if ($category->parent_id === null) {
            $category->level = 0;
            $category->path = $category->code;

            return;
        }

        $parent = TaxonomyCategory::query()->find($category->parent_id);

        if (! $parent) {
            throw ValidationException::withMessages([
                'parent_id' => 'La categoría padre indicada no existe.',
            ]);
        }

        if ($category->exists) {
            if ((int) $parent->id === (int) $category->id) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Una categoría no puede ser su propio padre.',
                ]);
            }

            // El path ORIGINAL (antes de este cambio) representa el subárbol completo de $category
            // tal como está hoy en la base - si el padre propuesto cuelga de ese subárbol (su path
            // empieza por "$originalPath/"), asignarlo crearía un ciclo.
            $originalPath = $category->getOriginal('path');

            if ($originalPath && str_starts_with($parent->path.'/', $originalPath.'/')) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Esa asignación crearía un ciclo: el nuevo padre es un descendiente de esta categoría.',
                ]);
            }
        }

        $category->level = $parent->level + 1;
        $category->path = $parent->path.'/'.$category->code;
    }

    public function saved(TaxonomyCategory $category): void
    {
        if ($category->wasChanged(['path', 'code'])) {
            $this->refreshDescendantPaths($category);
        }
    }

    /**
     * Propaga el path/level nuevo a todos los descendientes. Usa saveQuietly() (no dispara
     * saving/saved de nuevo) y recursión manual en su lugar, para no depender de que los eventos
     * de Eloquent se re-disparen en cascada.
     */
    private function refreshDescendantPaths(TaxonomyCategory $category): void
    {
        $children = TaxonomyCategory::query()->where('parent_id', $category->id)->get();

        foreach ($children as $child) {
            $child->level = $category->level + 1;
            $child->path = $category->path.'/'.$child->code;
            $child->saveQuietly();

            $this->refreshDescendantPaths($child);
        }
    }
}
