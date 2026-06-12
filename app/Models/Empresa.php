<?php

namespace App\Models;

use App\Filament\Resources\EmpresaResource\Pages\ListEmpresas;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

class Empresa extends Model
{
    use HasFactory;

    //protected $table = 'empresas';

    protected $casts = [
        'customers_country' => 'array',
    ];

    protected $fillable = [
        'id',
        'rif',
        'name',
        'ano_fund',
        'phone',
        'website',
        'street',
        'city_id',
        'linkedin_profile',
        'twitter_profile',
        'instagram_profile',
        'facebook_profile',
        'youtube_profile',
        'otros_profile',
        'billing_id',
        'employees_id',
        'status_id',
        'property_id',
        'origin_id',
        'customers_country',
        'sector_principal_id',
        'sector_secundario_id',


    ];

    protected $hidden = [
        //---
    ];

    public function getEmpresaUser($vemp)
    {
        $user = strval(Auth::User()->id);

        $emps = DB::table('empresa_user')
            ->whereNotIn('empresa_id', DB::table($vemp)->pluck('empresa_id'))
            ->select("*", DB::raw("('id') as empresa_name"))
            ->from('empresas')
            ->join('empresa_user', 'empresas.id', '=', 'empresa_user.empresa_id')
            ->where('empresa_user.user_id', '=', $user)

            ->get();

        return $emps;
    }

    public function getAllEmpresaUser()
    {
        $user = strval(Auth::User()->id);

        $emps = DB::table('empresa_user')
            ->select("*", DB::raw("('id') as empresa_name"))
            ->from('empresas')
            ->join('empresa_user', 'empresas.id', '=', 'empresa_user.empresa_id')
            ->where('empresa_user.user_id', '=', $user)

            ->get();

        return $emps;
    }

    public static function boot()
    {
        parent::boot();

        static::created(function ($item) {
            //$idempresa = DB::getPdo()->lastInsertId();
            $idempresa = $item->id;
            $user = strval(Auth::User()->id);

            DB::insert('insert into empresa_user (empresa_id, user_id, created_at, updated_at) 
            values (?, ?, ?, ?)', [$idempresa, $user, now(), now()]);

            DB::insert('insert into empresa_user (empresa_id, user_id, created_at, updated_at) 
            select ?, model_id, now(), now() from model_has_roles where role_id = 1 and model_id <> ?', [$idempresa, $user]);

            /* select (?, ?, ?, ?)', [$idempresa, ] 
            select 1, model_id, now(), now() from model_has_roles where role_id = 1 and model_id <> 3 */
        });
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function contacts()
    {
        return $this->belongsToMany(Contact::class);
    }

    public function chambers()
    {
        return $this->belongsToMany(Chamber::class);
    }

    public function users()
    {
        $user_id = Auth::User()->id;
        /*         $empresas =
            DB::table('empresas')
            ->selectRaw('empresas.name , group_concat(DISTINCT users.name)')
            ->join('empresa_user', 'empresas.id', '=', 'empresa_user.empresa_id')
            ->join('users', 'empresa_user.user_id', '=', 'users.id')
            ->where('users.id', '=', $user_id)
            ->groupBy('empresas.id', 'empresas.name')
            ->get(); */

        return $this->belongsToMany(User::class, 'empresa_user');
        //    ->wherePivot('user_id', $user_id);
    }

    public function sectors()
    {
        return $this->belongsToMany(Sector::class, 'empresa_sector_service');
    }

    public function services()
    {
        return $this->belongsToMany(Service::class, 'empresa_sector_service');
        //return $this->belongsTo(Service::class, 'empresa_sector_service');
    }

    public function assets()
    {
        return $this->hasMany(Asset::class);
    }

    public function presence()
    {
        return $this->hasOne(Presence::class);
    }

    public function management()
    {
        return $this->hasMany(Management::class);
    }

    public function experiences()
    {
        return $this->hasMany(Experience::class);
    }

    public function sustainabilities()
    {
        return $this->hasMany(Sustainability::class);
    }

    public function sectorPrincipal()
    {
        return $this->belongsTo(Sector::class, 'sector_principal_id');
    }

    public function sectorSecundario()
    {
        return $this->belongsTo(Sector::class, 'sector_secundario_id');
    }

    public function moduleStatuses()
    {
        return $this->hasMany(EmpresaModuleStatus::class);
    }

    /**
     * Ids de los sectores permitidos para la empresa (principal y secundario).
     */
    public function allowedSectorIds(): array
    {
        return array_map('intval', array_values(array_filter([
            $this->sector_principal_id,
            $this->sector_secundario_id,
        ])));
    }

    /**
     * Ids de los sectores distintos derivados de los servicios ya asociados.
     */
    public function distinctSectorIds(): array
    {
        return DB::table('empresa_sector_service')
            ->join('services', 'services.id', '=', 'empresa_sector_service.service_id')
            ->where('empresa_sector_service.empresa_id', $this->id)
            ->distinct()
            ->pluck('services.sectors_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function isModuleNoAplica(string $module): bool
    {
        return EmpresaModuleStatus::isNoAplica($this->id, $module);
    }

    /**
     * Estado de completitud por módulo del perfil.
     * Un módulo cuenta como completo si tiene datos o si fue marcado "No Aplica".
     */
    public function completionData(): array
    {
        $naFlags = EmpresaModuleStatus::flagsFor($this->id);

        return [
            'Datos Generales' => true,
            'Sectores y Servicios' => $this->services()->count() > 0,
            'Contactos' => $this->contacts()->count() > 0,
            EmpresaModuleStatus::MODULES[EmpresaModuleStatus::MODULE_RECURSOS] =>
                $this->assets()->count() > 0 || $naFlags[EmpresaModuleStatus::MODULE_RECURSOS],
            EmpresaModuleStatus::MODULES[EmpresaModuleStatus::MODULE_GESTION] =>
                $this->management()->count() > 0 || $naFlags[EmpresaModuleStatus::MODULE_GESTION],
            EmpresaModuleStatus::MODULES[EmpresaModuleStatus::MODULE_PRESENCIA] =>
                $this->presence()->count() > 0 || $naFlags[EmpresaModuleStatus::MODULE_PRESENCIA],
            EmpresaModuleStatus::MODULES[EmpresaModuleStatus::MODULE_EXPERIENCIAS] =>
                $this->experiences()->count() > 0 || $naFlags[EmpresaModuleStatus::MODULE_EXPERIENCIAS],
            EmpresaModuleStatus::MODULES[EmpresaModuleStatus::MODULE_SOSTENIBILIDAD] =>
                $this->sustainabilities()->count() > 0 || $naFlags[EmpresaModuleStatus::MODULE_SOSTENIBILIDAD],
        ];
    }

    public function completionPercentage(): int
    {
        $data = $this->completionData();

        return (int) round(100 * count(array_filter($data)) / count($data));
    }
}
