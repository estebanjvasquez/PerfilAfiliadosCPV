<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierCategorySynonym extends Model
{
    protected $connection = 'pgsql';

    protected $fillable = [
        'category_id',
        'locale',
        'term',
        'origen',
    ];

    public function category()
    {
        return $this->belongsTo(SupplierCategory::class, 'category_id');
    }
}
