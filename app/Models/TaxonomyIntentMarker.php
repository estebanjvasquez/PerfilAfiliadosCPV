<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 3 (sección 2/13 del pedido): marcador de intención de negocio GENÉRICO (mantenimiento,
 * alquiler, inspección, fabricación, instalación, reparación, suministro, transporte, ingeniería) -
 * ver docblock de la migración `create_taxonomy_intent_markers_table`. Consumido exclusivamente por
 * `IntentContaminationDetector`, nunca comparado contra un término de ejemplo específico.
 */
class TaxonomyIntentMarker extends Model
{
    protected $connection = 'pgsql';

    protected $fillable = [
        'marker_es',
        'marker_en',
        'marker_type',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    /** @return array<int, string> Todas las formas activas (ES+EN), normalizadas, sin duplicados. */
    public static function activeNormalizedForms(): array
    {
        $forms = static::query()
            ->where('active', true)
            ->get(['marker_es', 'marker_en'])
            ->flatMap(fn (self $marker) => [$marker->marker_es, $marker->marker_en])
            ->filter()
            ->map(fn (string $m) => mb_strtolower(trim($m)))
            ->unique()
            ->values();

        return $forms->all();
    }
}
