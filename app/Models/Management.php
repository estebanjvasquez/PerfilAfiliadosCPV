<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class Management extends Model
{
    use HasFactory;


    protected $casts = [
        'iso9001' => 'boolean',
        'iso17025' => 'boolean',
        'quality_otros' => 'boolean',
        'quality_data' => 'array',

        'iso14001' => 'boolean',
        'iso50001' => 'boolean',
        'environment_otros' => 'boolean',
        'environment_data' => 'array',

        'dun' => 'boolean',
        'iso37001' => 'boolean',
        'credibility_otros' => 'boolean',
        'credibility_data' => 'array',

        'iso45001' => 'boolean',
        'ovid' => 'boolean',
        'security_otros' => 'boolean',
        'security_data' => 'array',

        'pmi' => 'boolean',
        'pmi_otros' => 'boolean',
        'pmi_data' => 'array',

        'iso27001' => 'boolean',
        'info_otros' => 'boolean',
        'info_data' => 'array',
    ];

    protected $fillable = [
        'empresa_id',
        'user_id',

        'iso9001',
        'iso17025',
        'quality_otros',
        'quality_data',

        'iso14001',
        'iso50001',
        'environment_otros',
        'environment_data',

        'dun',
        'iso37001',
        'credibility_otros',
        'credibility_data',

        'iso45001',
        'ovid',
        'security_otros',
        'security_data',

        'pmi',
        'pmi_otros',
        'pmi_data',

        'iso27001',
        'info_otros',
        'info_data',
    ];

    public static function boot()
    {
        parent::boot();

        // CPV-FIRST-SAVE-POSTGRES-COMPATIBILITY (Issue #2 `6212967641`): el actor se escribe en el mismo
        // INSERT y solo en la fila creada. Antes era un segundo statement después del insert,
        // `update management set management.user_id = ? where empresa_id = ?`. PostgreSQL rechaza un SET
        // calificado con la tabla (SQLSTATE 42703), así que el primer guardado fallaba después de dejar una
        // fila parcial. Además, el WHERE reescribía el actor de todas las filas de la empresa.
        static::creating(function ($item) {
            $item->user_id = Auth::id();
        });

        static::created(function ($item) {
            $empresa = $item->empresa_id;

            EmpresaModuleStatus::setStatus($empresa, EmpresaModuleStatus::MODULE_GESTION, false);
            /* $user = strval(Auth::User()->id);

            DB::update(
                'update management 
                inner join empresa_user on management.empresa_id = empresa_user.empresa_id
                set management.user_id = ?
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
}
