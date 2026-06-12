<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class Experience extends Model
{
    use HasFactory;

    protected $casts = [
        'content' => 'array',
        'exp_year' => 'array',
        //'infratypes_id' => 'array',
        //'infraestructura' => 'array',
    ];

    protected $fillable = [
        'empresa_id',
        'exp_year',
        //'infratypes.id'
        //'infraestructura',
        //'user_id',


    ];

    public static function boot()
    {
        parent::boot();

        static::created(function ($item) {
            $user = Auth::id();
            $empresa = $item->empresa_id;

            DB::update(
                'update experiences
                set experiences.user_id = ?
                where empresa_id = ?',
                [$user, $empresa]
            );

            EmpresaModuleStatus::setStatus($empresa, EmpresaModuleStatus::MODULE_EXPERIENCIAS, false);
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

    /* public function infrasectors()
    {
        return $this->hasMany(InfraSector::class);
    } */

    public function infratypes()
    {
        return $this->belongsTo(InfraType::class);
    }

    public function infrasystems()
    {
        return $this->belongsTo(InfraSystem::class);
    }

    public function infraregions()
    {
        return $this->belongsTo(InfraRegion::class);
    }

    public function infrafacilities()
    {
        return $this->belongsTo(InfraFacility::class);
    }
}
