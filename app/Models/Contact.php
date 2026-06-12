<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'position',
        'phone',
        'email',
    ];
    public function empresas()
    {
        return $this->belongsToMany(Empresa::class);
    }

    public function contact_empresa()
    {
        return $this->hasMany(contact_empresa::class);
    }
}
