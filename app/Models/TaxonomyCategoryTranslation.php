<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxonomyCategoryTranslation extends Model
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
        return $this->belongsTo(TaxonomyCategory::class, 'category_id');
    }
}
