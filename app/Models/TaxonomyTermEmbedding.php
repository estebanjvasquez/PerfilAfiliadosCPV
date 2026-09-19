<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 3 (sección 9 del pedido): embedding persistido/reusable de un `taxonomy_terms` - ver
 * docblock de la migración `create_taxonomy_term_embeddings_table`. Mismo patrón que
 * `TaxonomyCategoryEmbedding` (Fase MCP-1).
 */
class TaxonomyTermEmbedding extends Model
{
    protected $connection = 'pgsql';

    protected $fillable = [
        'term_id',
        'embedding',
        'model_version',
        'source_text',
        'generated_at',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
    ];

    public function term()
    {
        return $this->belongsTo(TaxonomyTerm::class, 'term_id');
    }
}
