<?php

namespace App\Observers;

use App\Models\SupplierCategory;
use Illuminate\Validation\ValidationException;

/**
 * Mantiene `level`/`path` de SupplierCategory sincronizados con `parent_id`/`supplhi_code`, y
 * previene ciclos en la jerarquia. `path` se arma con `supplhi_code` (no con `id`) porque el
 * codigo se conoce ANTES del insert (lo trae el archivo de importacion) - evita el problema de
 * necesitar el id autoincremental, que recien existe despues de guardar, para armar el propio
 * path del nodo que se esta creando.
 */
class SupplierCategoryObserver
{
    public function saving(SupplierCategory $category): void
    {
        if ($category->parent_id === null) {
            $category->level = 0;
            $category->path = $category->supplhi_code;

            return;
        }

        $parent = SupplierCategory::query()->find($category->parent_id);

        if (! $parent) {
            throw ValidationException::withMessages([
                'parent_id' => 'La categoria padre indicada no existe.',
            ]);
        }

        if ($category->exists) {
            if ((int) $parent->id === (int) $category->id) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Una categoria no puede ser su propio padre.',
                ]);
            }

            // El path ORIGINAL (antes de este cambio) representa el subarbol completo de $category
            // tal como esta hoy en la base - si el padre propuesto cuelga de ese subarbol (su path
            // empieza por "$originalPath/"), asignarlo crearia un ciclo.
            $originalPath = $category->getOriginal('path');

            if ($originalPath && str_starts_with($parent->path.'/', $originalPath.'/')) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Esa asignacion crearia un ciclo: el nuevo padre es un descendiente de esta categoria.',
                ]);
            }
        }

        $category->level = $parent->level + 1;
        $category->path = $parent->path.'/'.$category->supplhi_code;
    }

    public function saved(SupplierCategory $category): void
    {
        if ($category->wasChanged(['path', 'supplhi_code'])) {
            $this->refreshDescendantPaths($category);
        }
    }

    /**
     * Propaga el path/level nuevo a todos los descendientes. Usa saveQuietly() (no dispara
     * saving/saved de nuevo) y recursion manual en su lugar, para no depender de que los eventos
     * de Eloquent se re-disparen en cascada.
     */
    private function refreshDescendantPaths(SupplierCategory $category): void
    {
        $children = SupplierCategory::query()->where('parent_id', $category->id)->get();

        foreach ($children as $child) {
            $child->level = $category->level + 1;
            $child->path = $category->path.'/'.$child->supplhi_code;
            $child->saveQuietly();

            $this->refreshDescendantPaths($child);
        }
    }
}
