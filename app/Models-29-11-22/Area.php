<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class Area extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'sust_title',
        'sust_description'
    ];

    public function getArea()
    {
        $user = strval(Auth::User()->id);

        $varea = DB::table('areas')
            /*->whereNotIn(
                'areas.id',
                DB::table('sustainabilities')
                    ->where('sustainabilities.user_id', '=', $user)->pluck('areas_id')

            ) */

            ->select("*", DB::raw("('areas.id') as area_name"))
            ->from('areas')

            ->get();

        return $varea;
    }

    public function sustainabilities()
    {
        return $this->hasMany(Sustainability::class, 'areas_id');
    }
}
