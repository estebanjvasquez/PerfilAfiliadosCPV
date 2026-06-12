<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sector extends Model
{
    use HasFactory;

    protected $fillable = [

        'name',
    ];

    public function empresas()
    {
        return $this->belongsToMany(Empresa::class, 'empresa_sector_service');
    }
    public function services()
    {
        return $this->hasMany(Service::class, 'sectors_id');
    }
}
