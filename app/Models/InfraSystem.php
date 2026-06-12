<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InfraSystem extends Model
{
    use HasFactory;

    protected $table = 'infrasystems';

    protected $fillable = [
        'infratypes_id',
        'system_name',
    ];

    public function infratypes()
    {
        return $this->belongsTo(InfraType::class, 'infratypes_id');
    }

    public function infraregions()
    {
        return $this->belongsToMany(InfraRegion::class, 'infraregion_infrasystem', 'infrasystem_id', 'infraregion_id');
    }
}
