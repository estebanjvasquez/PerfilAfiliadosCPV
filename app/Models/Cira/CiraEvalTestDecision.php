<?php

namespace App\Models\Cira;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** TASK-0027: the Chamber's explicit decision for one test of one campaign. Nothing in the application computes it. */
class CiraEvalTestDecision extends Model
{
    protected $connection = 'pgsql';

    protected $table = 'cira_eval_test_decisions';

    protected $guarded = ['*'];

    protected $casts = ['decided_at' => 'datetime'];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(CiraEvalCampaign::class, 'campaign_id');
    }
}
