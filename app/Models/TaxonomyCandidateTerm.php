<?php

namespace App\Models;

use App\Casts\PostgresTextArrayCast;
use Illuminate\Database\Eloquent\Model;

/**
 * TAXV2-12: término encontrado por el crawler de fuentes, todavía fuera de `taxonomy_terms`. Ver
 * docblock de la migración para el workflow `candidate -> needs_review -> approved/rejected ->
 * published` y la detección de cambios (`change_status`).
 */
class TaxonomyCandidateTerm extends Model
{
    protected $connection = 'pgsql';

    public const STATUS_CANDIDATE = 'candidate';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PUBLISHED = 'published';

    public const CHANGE_NEW = 'new';

    public const CHANGE_UNCHANGED = 'unchanged';

    public const CHANGE_CHANGED = 'changed';

    public const CHANGE_MISSING = 'missing';

    protected $fillable = [
        'crawl_run_id',
        'source_id',
        'term',
        'language',
        'source_url',
        'source_category',
        'context_snippet',
        'content_hash',
        'change_status',
        'suggested_canonical_term',
        'suggested_aliases',
        'suggested_cpv_relations',
        'confidence',
        'status',
        'review_notes',
        'reviewed_by',
        'reviewed_at',
        'published_term_id',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'suggested_aliases' => PostgresTextArrayCast::class,
        'suggested_cpv_relations' => 'array',
        'confidence' => 'float',
        'reviewed_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function source()
    {
        return $this->belongsTo(TaxonomySource::class, 'source_id', 'source_id');
    }

    public function crawlRun()
    {
        return $this->belongsTo(TaxonomyCrawlRun::class, 'crawl_run_id');
    }

    public function publishedTerm()
    {
        return $this->belongsTo(TaxonomyTerm::class, 'published_term_id');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(UserPgsql::class, 'reviewed_by');
    }
}
