<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxonomyTermServiceRelation extends Model
{
    protected $connection = 'pgsql';

    protected $fillable = [
        'term_id',
        'legacy_service_id',
        'relation_type',
        'weight',
        'confidence',
        'status',
    ];

    protected $casts = [
        'weight' => 'float',
        'confidence' => 'float',
    ];

    public function term()
    {
        return $this->belongsTo(TaxonomyTerm::class, 'term_id');
    }

    public function legacyService()
    {
        return $this->belongsTo(LegacyService::class, 'legacy_service_id');
    }
}
