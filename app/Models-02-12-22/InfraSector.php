<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InfraSector extends Model
{
    use HasFactory;

    protected $table = 'infrasectors';

    protected $fillable = [
        'sector_name',
    ];

    public function infratypes()
    {
        return $this->hasMany(InfraType::class, 'infrasectors_id');
    }
}
