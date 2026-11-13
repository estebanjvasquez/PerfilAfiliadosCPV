<?php

namespace App\Models\Cira;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** TASK-0027: per-campaign access of an EXISTING user account. Reports show only `pseudonym`. */
class CiraEvalInvitation extends Model
{
    protected $connection = 'pgsql';

    protected $table = 'cira_eval_invitations';

    protected $guarded = ['*'];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(CiraEvalCampaign::class, 'campaign_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(CiraEvaluation::class, 'invitation_id');
    }

    /** Usable right now: not revoked, not expired, campaign open. The caller reads it fresh at every request. */
    public function isActive(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at > now())
            && $this->campaign !== null
            && $this->campaign->isOpenNow();
    }
}
