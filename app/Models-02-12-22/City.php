<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class City extends Model
{
    use HasFactory;

    protected $fillable = [
        'countries_id',
        'states_id',
        'city_name',
    ];

    public function getFullCity()
    {
        $city = DB::table('cities')
            ->select("*", DB::raw("CONCAT(cities.city_name,', ',countries.country_name) as full_location"))
            ->from('countries')
            ->join('cities', 'cities.country_id', '=', 'countries.id')
            //->where('cities.city_name', '=', 'Maracaibo')
            //->groupBy('city_name', 'country_id')
            ->get();

        return $city;
    }

    public function states()
    {
        return $this->belongsTo(State::class);
    }

    public function countries()
    {
        return $this->belongsTo(Country::class);
    }

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }
}
