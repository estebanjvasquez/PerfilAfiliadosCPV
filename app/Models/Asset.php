<?php

namespace App\Models;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    use HasFactory;

    protected $casts = [
        'employee' => 'array',
        'machinery' => 'array',
        'facility' => 'array',
        'inventory' => 'array',
    ];

    protected $fillable = [
        'empresa_id',
        'user_id',
        'employee',
        'machinery',
        'facility',
        'inventory',
    ];

    public static function boot()
    {
        parent::boot();

        // CPV-FIRST-SAVE-POSTGRES-COMPATIBILITY (`6212967641`): el actor va en el INSERT, solo en la fila
        // creada. Ver el comentario en Management::boot().
        static::creating(function ($item) {
            $item->user_id = Auth::id();
        });

        static::created(function ($item) {
            //$user = strval(Auth::User()->id);
            $empresa = $item->empresa_id;

            //dd($empresa);
            //$empresas = $user->empresas()->get();

            /* DB::update(
                'update assets 
                inner join empresa_user on assets.empresa_id = empresa_user.empresa_id
                set assets.user_id = ?
                where empresa_user.user_id = ?',
                //[$user, $user]
                [Auth::id(), Auth::id()]
            );  */

            EmpresaModuleStatus::setStatus($empresa, EmpresaModuleStatus::MODULE_RECURSOS, false);

            //Log::info('Created event call: ' . $item);
        });
    }



    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
