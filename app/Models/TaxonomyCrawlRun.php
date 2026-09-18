<?php

namespace App\Models;

use App\Casts\PostgresTextArrayCast;
use Illuminate\Database\Eloquent\Model;

/**
 * TAXV2-12: una corrida (posiblemente en curso) del crawler de una fuente terminológica. Ver
 * docblock de la migración - `pending_urls`/`visited_urls` son el checkpoint real que hace posible
 * retomar sin reprocesar.
 */
class TaxonomyCrawlRun extends Model
{
    protected $connection = 'pgsql';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BLOCKED = 'blocked';

    protected $fillable = [
        'source_id',
        'status',
        'pending_urls',
        'visited_urls',
        'pages_processed',
        'candidates_found',
        'started_at',
        'finished_at',
        'error_message',
    ];

    protected $casts = [
        'pending_urls' => PostgresTextArrayCast::class,
        'visited_urls' => PostgresTextArrayCast::class,
        'pages_processed' => 'integer',
        'candidates_found' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function source()
    {
        return $this->belongsTo(TaxonomySource::class, 'source_id', 'source_id');
    }

    public function candidateTerms()
    {
        return $this->hasMany(TaxonomyCandidateTerm::class, 'crawl_run_id');
    }
}
