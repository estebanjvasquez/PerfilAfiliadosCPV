<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierCategoryTranslation extends Model
{
    protected $connection = 'pgsql';

    protected $fillable = [
        'category_id',
        'locale',
        'name',
        'description',
    ];

    public function category()
    {
        return $this->belongsTo(SupplierCategory::class, 'category_id');
    }
}
