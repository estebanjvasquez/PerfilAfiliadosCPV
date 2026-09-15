<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila única (patrón singleton) con los 2 límites configurables del buscador de autocarga de
 * categorías (Fase 4) - ver TaxonomyCategoriesRelationManager y TaxonomySelectionSettingsPage.
 * `null` en cualquiera de los 2 campos significa "sin tope".
 */
class TaxonomySelectionSettings extends Model
{
    protected $connection = 'pgsql';

    protected $table = 'taxonomy_selection_settings';

    protected $fillable = [
        'max_categorias_principales',
        'max_categorias_secundarias',
    ];

    protected $casts = [
        'max_categorias_principales' => 'integer',
        'max_categorias_secundarias' => 'integer',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }
}
