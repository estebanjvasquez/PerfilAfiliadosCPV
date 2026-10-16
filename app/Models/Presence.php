<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class Presence extends Model
{
    use HasFactory;

    protected $casts = [
        'has_offices' => 'boolean',
        'has_experience' => 'boolean',
        'office_data' => 'array',
        'experience_data' => 'array',
    ];

    protected $fillable = [
        'empresa_id',
        'user_id',
        'has_offices',
        'office_data',
        'has_experience',
        'experience_data',
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
            $empresa = $item->empresa_id;

            EmpresaModuleStatus::setStatus($empresa, EmpresaModuleStatus::MODULE_PRESENCIA, false);
            /* $user = strval(Auth::User()->id);

            DB::update(
                'update presences 
                inner join empresa_user on presences.empresa_id = empresa_user.empresa_id
                set presences.user_id = ?
                where empresa_user.user_id = ?',
                [$user, $user]
            ); */
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

    public function countries()
    {
        return $this->hasMany(Country::class);
    }
}
