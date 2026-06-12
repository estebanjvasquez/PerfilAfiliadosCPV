<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Country extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'country_name',
    ];

    /* public function empresa()
    {
        return $this->hasOne(Empresa::class);
    } */

    public function states()
    {
        return $this->hasMany(State::class);
    }

    public function cities()
    {
        return $this->hasMany(City::class);
    }

    public function presence()
    {
        return $this->belongsTo(Presence::class);
    }

    public function getCountry()
    {
        $vcountry = DB::table('countries')
            ->select("*", DB::raw("('countries.id')"))
            ->from('countries')
            ->whereNotIn('id', [1]) // ELIMINANDO OPCION '1' (VENEZUELA) DEL QUERY. 
            ->get();

        return $vcountry;
    }
}
