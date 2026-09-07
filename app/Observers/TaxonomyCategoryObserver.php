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

        // Pedido explícito de Lorenzo (7 sep 2026): la jerarquía numérica del CÓDIGO manda — no se
        // puede guardar, por ejemplo, "CPV-05.01.01G" con parent_id apuntando a la familia
        // "CPV-05.02" (ni una familia "CPV-05.01" colgando de un grupo que no sea "CPV-05"). Esto
        // ya encontró datos reales inconsistentes del Excel de origen al escribir esta regla (12
        // categorías del Grupo 48 con "Category Code" mal tipeado apuntando al Family Code
        // correcto pero con code de otro segmento) — quedan bloqueadas hasta corregir código o
        // padre, no se "arreglan solas" con este chequeo.
        if ($category->code && ! TaxonomyCategory::codeBelongsToParent($category->code, $parent->code)) {
            throw ValidationException::withMessages([
                'code' => "El código {$category->code} no corresponde al padre elegido ({$parent->code}) — la jerarquía numérica del código debe coincidir (ej. \"05.01.01\" solo puede colgar de \"05.01\").",
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
