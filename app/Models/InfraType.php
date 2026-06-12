<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InfraType extends Model
{
    use HasFactory;

    protected $table = 'infratypes';

    protected $fillable = [
        'infrasectors_id',
        'type_name',
    ];

    public function infrasectors()
    {
        return $this->belongsTo(InfraSector::class, 'infrasectors_id');
    }

    public function infrasystems()
    {
        return $this->hasMany(InfraSystem::class, 'infratypes_id');
    }
}
