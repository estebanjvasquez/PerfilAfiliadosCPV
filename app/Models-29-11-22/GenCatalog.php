<?php

namespace App\Models;

use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class GenCatalog extends Model
{
    use HasFactory;

    protected $table = 'catalogoView';

    /*  public function users()
    {
        $user_id = Auth::User()->id;

        return $this->belongsToMany(User::class, 'empresa_user');
    } */
}
