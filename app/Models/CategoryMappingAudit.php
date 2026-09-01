<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bitacora de cada corrida del importador de la taxonomia SupplHi - ver
 * database/migrations/2026_09_01_120006_create_category_mapping_audit_table.php.
 */
class CategoryMappingAudit extends Model
{
    protected $connection = 'pgsql';

    protected $table = 'category_mapping_audit';

    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_UNCHANGED = 'unchanged';
    public const ACTION_ERROR = 'error';

    protected $fillable = [
        'category_id',
        'supplhi_code',
        'version',
        'action',
        'details',
        'imported_at',
    ];

    protected $casts = [
        'imported_at' => 'datetime',
    ];

    public function category()
    {
        return $this->belongsTo(SupplierCategory::class, 'category_id');
    }
}
