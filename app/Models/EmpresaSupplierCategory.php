<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Vinculo empresa <-> categoria SupplHi (el "tilde" de categoria). Ver
 * database/migrations/2026_09_01_120003_create_empresa_supplier_categories_table.php.
 *
 * Constantes de `origen` centralizadas aca (no en Empresa/SupplierCategory) porque son un detalle
 * de ESTE vinculo, no de ninguno de los 2 lados.
 */
class EmpresaSupplierCategory extends Model
{
    protected $connection = 'pgsql';

    protected $table = 'empresa_supplier_categories';

    public const ORIGEN_SELF_DECLARED = 'self_declared';
    public const ORIGEN_SUGGESTED = 'suggested';
    public const ORIGEN_VALIDATED = 'validated';

    protected $fillable = [
        'empresa_id',
        'category_id',
        'origen',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    public function empresa()
    {
        return $this->belongsTo(EmpresaPgsql::class, 'empresa_id');
    }

    public function category()
    {
        return $this->belongsTo(SupplierCategory::class, 'category_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(UserPgsql::class, 'approved_by');
    }
}
