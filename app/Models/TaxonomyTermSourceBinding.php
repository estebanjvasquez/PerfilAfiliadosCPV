<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * V2->V3 (ver docblock de la migración `create_taxonomy_term_source_bindings_table`): un binding
 * VERIFICADO entre un término y una fuente externa - nunca una sugerencia sin comprobar (esas viven
 * en `taxonomy_terms.candidate_sources`, no acá).
 */
class TaxonomyTermSourceBinding extends Model
{
    protected $connection = 'pgsql';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_PENDING_VERIFICATION = 'pending_verification';

    public const STATUS_STALE = 'stale';

    public const STATUS_MISSING = 'missing';

    protected $fillable = [
        'term_id',
        'source_id',
        'source_term',
        'source_url',
        'language',
        'binding_type',
        'verification_status',
        'sync_enabled',
        'first_seen_at',
        'last_seen_at',
        'last_verified_at',
        'content_hash',
    ];

    protected $casts = [
        'sync_enabled' => 'boolean',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'last_verified_at' => 'datetime',
    ];

    public function term()
    {
        return $this->belongsTo(TaxonomyTerm::class, 'term_id');
    }

    public function source()
    {
        return $this->belongsTo(TaxonomySource::class, 'source_id', 'source_id');
    }
}
