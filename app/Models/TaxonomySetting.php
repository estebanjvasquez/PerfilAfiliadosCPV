<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila clave-valor de `taxonomy_settings` (TAXV2-4). La metadata (label/descripción/default/min/
 * max/ejemplo) vive en `App\Support\Taxonomy\TaxonomyRankingParameters`, no en este modelo - acá
 * solo el valor actual.
 */
class TaxonomySetting extends Model
{
    protected $connection = 'pgsql';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value',
        'updated_by',
    ];

    protected $casts = [
        'value' => 'float',
    ];

    /** `key => value` de todos los settings actuales, para que el buscador/simulador no dispare una query por parámetro. */
    public static function currentValues(): array
    {
        return static::query()->pluck('value', 'key')->all();
    }
}
