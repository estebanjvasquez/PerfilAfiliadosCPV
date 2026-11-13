<?php

namespace App\Models\Cira;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-0013 table: the 👍/👎 votes cast in CIRA Test (through n8n and the Worker). It stays the single source of truth for
 * votes; the evaluation module (TASK-0027) only READS it and joins by search_log_id.
 */
class CiraSearchFeedback extends Model
{
    protected $connection = 'pgsql';

    protected $table = 'cira_search_feedback';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'created_at' => 'datetime',
        'vote' => 'integer',
        'rank_position' => 'integer',
    ];

    public function searchLog(): BelongsTo
    {
        return $this->belongsTo(CiraSearchLog::class, 'search_log_id');
    }

    protected static function booted(): void
    {
        $refuse = function (): void {
            throw new \LogicException('cira_search_feedback es de solo lectura para esta aplicación.');
        };
        static::saving($refuse);
        static::deleting($refuse);
    }
}
