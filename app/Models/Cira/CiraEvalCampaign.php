<?php

namespace App\Models\Cira;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** TASK-0027: one evaluation campaign of the CIRA search engine by the Chamber. */
class CiraEvalCampaign extends Model
{
    protected $connection = 'pgsql';

    protected $table = 'cira_eval_campaigns';

    protected $fillable = ['code', 'name', 'description', 'status', 'opens_at', 'closes_at'];

    protected $casts = [
        'opens_at' => 'datetime',
        'closes_at' => 'datetime',
    ];

    public const STATUSES = ['draft' => 'Borrador', 'open' => 'Abierta', 'closed' => 'Cerrada'];

    public function invitations(): HasMany
    {
        return $this->hasMany(CiraEvalInvitation::class, 'campaign_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(CiraEvaluation::class, 'campaign_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(CiraEvalTestDecision::class, 'campaign_id');
    }

    /** Open for evaluation right now: status open and inside the optional window. */
    public function isOpenNow(): bool
    {
        $now = now();

        return $this->status === 'open'
            && ($this->opens_at === null || $this->opens_at <= $now)
            && ($this->closes_at === null || $this->closes_at > $now);
    }
}
