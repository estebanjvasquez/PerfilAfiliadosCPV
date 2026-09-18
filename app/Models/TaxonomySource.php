<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fuente del diccionario de términos (TAXV2-1) - las 3 crawleables del JSON V2 (SLB/OSHA/IADC) más
 * `CURATED` (sembrada a mano, no crawleable - ver migración).
 */
class TaxonomySource extends Model
{
    protected $connection = 'pgsql';

    public const CURATED = 'CURATED';

    protected $fillable = [
        'source_id',
        'name',
        'base_url',
        'discovery_url',
        'crawlable',
        'enabled',
        'respect_robots_txt',
        'rate_limit_rpm',
        'requires_admin_approval_before_import',
    ];

    protected $casts = [
        'crawlable' => 'boolean',
        'enabled' => 'boolean',
        'respect_robots_txt' => 'boolean',
        'requires_admin_approval_before_import' => 'boolean',
        'rate_limit_rpm' => 'integer',
    ];

    public function terms()
    {
        return $this->hasMany(TaxonomyTerm::class, 'source_id', 'source_id');
    }
}
