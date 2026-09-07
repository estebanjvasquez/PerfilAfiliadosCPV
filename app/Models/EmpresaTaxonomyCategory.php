<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Vínculo empresa <-> categoría de la taxonomía CPV (el "tilde" de categoría, sin descripción ni
 * capacidad — eso es un módulo aparte, todavía no construido). Ver
 * database/migrations/pgsql/2026_09_01_120003_create_empresa_supplier_categories_table.php
 * (renombrada a `empresa_taxonomy_category` por la migración correctiva del 7 sep).
 *
 * Constantes de `origen` centralizadas acá (no en Empresa/TaxonomyCategory) porque son un detalle
 * de ESTE vínculo, no de ninguno de los 2 lados.
 */
class EmpresaTaxonomyCategory extends Model
{
    protected $connection = 'pgsql';

    protected $table = 'empresa_taxonomy_category';

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
        return $this->belongsTo(TaxonomyCategory::class, 'category_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(UserPgsql::class, 'approved_by');
    }
}
