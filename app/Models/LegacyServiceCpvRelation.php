<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegacyServiceCpvRelation extends Model
{
    protected $connection = 'pgsql';

    public const STATUS_CANDIDATE = 'candidate';
    public const STATUS_AUTO_MAPPED = 'auto_mapped';
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_DEPRECATED = 'deprecated';

    protected $fillable = [
        'legacy_service_id',
        'cpv_code',
        'category_id',
        'level',
        'relation_type',
        'weight',
        'confidence',
        'matched_on',
        'source',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'weight' => 'float',
        'confidence' => 'float',
        'reviewed_at' => 'datetime',
    ];

    public function legacyService()
    {
        return $this->belongsTo(LegacyService::class, 'legacy_service_id');
    }

    public function category()
    {
        return $this->belongsTo(TaxonomyCategory::class, 'category_id');
    }
}
