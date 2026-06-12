<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class Sustainability extends Model
{
    use HasFactory;

    protected $fillable = [
        'empresa_id',
        'areas_id',
        'temp_description',
        'sust_status',
    ];

    public static function boot()
    {
        parent::boot();

        static::created(function ($item) {
            $user = strval(Auth::User()->id);
            DB::update(
                'update sustainabilities 
                inner join empresa_user on sustainabilities.empresa_id = empresa_user.empresa_id
                set sustainabilities.user_id = ?
                where empresa_user.user_id = ?',
                [$user, $user]
            );
        });
    }

    public function empresas()
    {
        return $this->belongsTo(Empresa::class);
    }
    public function areas()
    {
        return $this->belongsTo(Area::class);
    }
}
