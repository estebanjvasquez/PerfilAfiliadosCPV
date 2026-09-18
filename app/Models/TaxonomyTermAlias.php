<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxonomyTermAlias extends Model
{
    protected $connection = 'pgsql';

    protected $fillable = [
        'term_id',
        'alias',
    ];

    public function term()
    {
        return $this->belongsTo(TaxonomyTerm::class, 'term_id');
    }
}
