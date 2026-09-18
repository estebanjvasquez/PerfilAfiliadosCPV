<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Espejo pgsql del catálogo legacy `services` (mysql) - ver docblock de la migración
 * `create_legacy_services_table`. `id` coincide siempre con `services.id` en mysql.
 */
class LegacyService extends Model
{
    protected $connection = 'pgsql';

    public $incrementing = false;

    public const STATUS_LEGACY_ACTIVE = 'legacy_active';
    public const STATUS_DEPRECATED = 'deprecated';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'id',
        'sectors_id',
        'name',
        'status',
    ];

    public function cpvRelations()
    {
        return $this->hasMany(LegacyServiceCpvRelation::class, 'legacy_service_id');
    }

    public function termRelations()
    {
        return $this->hasMany(TaxonomyTermServiceRelation::class, 'legacy_service_id');
    }
}
