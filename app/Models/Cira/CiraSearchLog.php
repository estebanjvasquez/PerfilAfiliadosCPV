<?php

namespace App\Models\Cira;

use App\Casts\PgBigintArray;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TASK-0013 table, written by the CIRA flow (n8n). This application only READS it (TASK-0027): saving or deleting through
 * this model is refused.
 */
class CiraSearchLog extends Model
{
    protected $connection = 'pgsql';

    protected $table = 'cira_search_log';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'created_at' => 'datetime',
        'agent_params' => 'array',
        'result_evidence' => 'array',
        'result_empresa_ids' => PgBigintArray::class,
        'result_count' => 'integer',
        'response_ms' => 'integer',
    ];

    public function feedback(): HasMany
    {
        return $this->hasMany(CiraSearchFeedback::class, 'search_log_id');
    }

    protected static function booted(): void
    {
        $refuse = function (): void {
            throw new \LogicException('cira_search_log es de solo lectura para esta aplicación.');
        };
        static::saving($refuse);
        static::deleting($refuse);
    }
}
