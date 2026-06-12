<?php

namespace App\Models;

use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class GenCatalog extends Model
{
    use HasFactory;

    protected $table = 'catalogoView';

    //AGREGADO 25/10
    /* public function users()
    {
        $user_id = Auth::User()->id;

        return $this->belongsToMany(User::class, 'empresa_user');
    } */
}
