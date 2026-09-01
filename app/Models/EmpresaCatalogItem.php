<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Producto/servicio publicado por una empresa (marca, capacidad, ubicacion, palabras clave) -
 * ver database/migrations/2026_09_01_120004_create_empresa_catalog_items_table.php.
 */
class EmpresaCatalogItem extends Model
{
    protected $connection = 'pgsql';

    protected $fillable = [
        'empresa_id',
        'name',
        'description',
        'brand',
        'capacity',
        'location',
        'keywords',
    ];

    public function empresa()
    {
        return $this->belongsTo(EmpresaPgsql::class, 'empresa_id');
    }

    public function categories()
    {
        return $this->belongsToMany(
            SupplierCategory::class,
            'empresa_catalog_item_category',
            'catalog_item_id',
            'category_id'
        )->withTimestamps();
    }
}
