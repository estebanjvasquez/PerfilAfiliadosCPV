<?php

namespace App\Models\Cira;

use App\Casts\PgBigintArray;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-0027: one evaluator's judgement of one search for one guided test. Written only through CiraEvaluationService,
 * which sets the evidence (snapshot, hash, query, search link) once; a database trigger refuses any later change to it.
 */
class CiraEvaluation extends Model
{
    protected $connection = 'pgsql';

    protected $table = 'cira_evaluations';

    protected $guarded = ['*'];

    protected $casts = [
        'snapshot' => 'array',
        'extra_empresa_ids' => PgBigintArray::class,
        'missing_empresa_ids' => PgBigintArray::class,
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(CiraEvalCampaign::class, 'campaign_id');
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(CiraEvalInvitation::class, 'invitation_id');
    }

    public function searchLog(): BelongsTo
    {
        return $this->belongsTo(CiraSearchLog::class, 'search_log_id');
    }
}
