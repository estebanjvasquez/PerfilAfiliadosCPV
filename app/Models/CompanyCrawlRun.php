<?php

namespace App\Models;

use App\Casts\PostgresTextArrayCast;
use Illuminate\Database\Eloquent\Model;

/**
 * TAXV2-13: una corrida (posiblemente en curso) del crawler de la web de una empresa. Mismo patrón
 * de checkpoint que `TaxonomyCrawlRun` (TAXV2-12) - ver docblock de la migración.
 */
class CompanyCrawlRun extends Model
{
    protected $connection = 'pgsql';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_BLOCKED = 'blocked';

    protected $fillable = [
        'empresa_id',
        'status',
        'pending_urls',
        'visited_urls',
        'pages_processed',
        'matches_found',
        'started_at',
        'finished_at',
        'error_message',
    ];

    protected $casts = [
        'pending_urls' => PostgresTextArrayCast::class,
        'visited_urls' => PostgresTextArrayCast::class,
        'pages_processed' => 'integer',
        'matches_found' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function empresa()
    {
        return $this->belongsTo(EmpresaPgsql::class, 'empresa_id');
    }

    public function pages()
    {
        return $this->hasMany(CompanyPage::class, 'crawl_run_id');
    }

    public function termMatches()
    {
        return $this->hasManyThrough(CompanyTermMatch::class, CompanyPage::class, 'crawl_run_id', 'page_id');
    }
}
