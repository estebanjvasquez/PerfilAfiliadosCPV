<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'service.id',
        'sectors_id',
        'name',
        //'sector_id',
        //'service_id'

    ];

    public function empresas()
    {
        return $this->belongsToMany(Empresa::class, 'empresa_sector_service');
    }

    public function sectors()
    {
        return $this->belongsTo(Sector::class);
    }
}
