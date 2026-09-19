<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 3 (sección 5 del pedido): relación semántica TIPADA entre dos `taxonomy_canonical_concepts`.
 * Distinta de `taxonomy_term_concepts` (que es IDENTIDAD/EXPRESIÓN término->concepto) - esta tabla
 * vive exclusivamente al nivel concepto->concepto (ej. un futuro PART_OF entre "Torre y Mástil de
 * Perforación" y "Equipo de Perforación").
 *
 * Queda en 0 filas tras esta entrega (ver STOP CONDITION / ZERO-POPULATION del pedido) - el modelo
 * existe para que el admin y los tests puedan operar sobre la tabla ya, sin esperar a que el Builder
 * la pueble automáticamente (todavía no habilitado).
 */
class TaxonomyConceptRelation extends Model
{
    protected $connection = 'pgsql';

    public const STATUS_CANDIDATE = 'candidate';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'source_concept_id',
        'target_concept_id',
        'relation_type',
        'weight',
        'confidence',
        'status',
        'provenance',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'weight' => 'float',
        'confidence' => 'float',
        'provenance' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function sourceConcept()
    {
        return $this->belongsTo(TaxonomyCanonicalConcept::class, 'source_concept_id');
    }

    public function targetConcept()
    {
        return $this->belongsTo(TaxonomyCanonicalConcept::class, 'target_concept_id');
    }
}
