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

        // CPV-FIRST-SAVE-POSTGRES-COMPATIBILITY (`6212967641`): el actor va en el INSERT, solo en la fila
        // creada. Este módulo admite varias filas por empresa, y antes el WHERE empresa_id le reescribía
        // el actor a todas. Ver el comentario en Management::boot().
        static::creating(function ($item) {
            $item->user_id = Auth::id();
        });

        static::created(function ($item) {
            $empresa = $item->empresa_id;

            EmpresaModuleStatus::setStatus($empresa, EmpresaModuleStatus::MODULE_SOSTENIBILIDAD, false);
            /* $user = strval(Auth::User()->id);
            DB::update(
                'update sustainabilities 
                inner join empresa_user on sustainabilities.empresa_id = empresa_user.empresa_id
                set sustainabilities.user_id = ?
                where empresa_user.user_id = ?',
                [$user, $user]
            ); */
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
