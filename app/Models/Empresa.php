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
     * Estado (completo/incompleto) de cada tipo de Recursos: tiene datos cargados
     * en el Asset, o fue marcado "No Aplica" a nivel de ese tipo individual.
     */
    public function recursosSubTypeStatus(): array
    {
        $asset = $this->assets()->first();
        $naFlags = EmpresaModuleStatus::subTypeFlagsFor($this->id, EmpresaModuleStatus::MODULE_RECURSOS);

        return [
            'employee' => ! empty($asset?->employee) || $naFlags['employee'],
            'machinery' => ! empty($asset?->machinery) || $naFlags['machinery'],
            'facility' => ! empty($asset?->facility) || $naFlags['facility'],
            'inventory' => ! empty($asset?->inventory) || $naFlags['inventory'],
        ];
    }

    /**
     * Estado (completo/incompleto) de cada sección de Sistemas de Gestión: tiene
     * al menos una certificación marcada o "Otros" activado, o fue marcada
     * "No Aplica" a nivel de esa sección individual.
     */
    public function gestionSubTypeStatus(): array
    {
        $management = $this->management()->first();
        $naFlags = EmpresaModuleStatus::subTypeFlagsFor($this->id, EmpresaModuleStatus::MODULE_GESTION);

        return [
            'calidad' => (bool) ($management?->iso9001 || $management?->iso17025 || $management?->quality_otros) || $naFlags['calidad'],
            'ambiente' => (bool) ($management?->iso14001 || $management?->iso50001 || $management?->environment_otros) || $naFlags['ambiente'],
            'credibilidad' => (bool) ($management?->dun || $management?->iso37001 || $management?->credibility_otros) || $naFlags['credibilidad'],
            'seguridad' => (bool) ($management?->iso45001 || $management?->ovid || $management?->security_otros) || $naFlags['seguridad'],
            'proyectos' => (bool) ($management?->pmi || $management?->pmi_otros) || $naFlags['proyectos'],
            'seguridad_info' => (bool) ($management?->iso27001 || $management?->info_otros) || $naFlags['seguridad_info'],
        ];
    }

    /**
     * % de completitud de un módulo con sub-tipos (Recursos/Gestión): 100% si el
     * módulo entero está en "No Aplica" (independiente de los sub-tipos), si no,
     * la fracción de sub-tipos completos.
     */
    private function subTypeModulePercentage(bool $wholeModuleNoAplica, array $subTypeStatus): int
    {
        if ($wholeModuleNoAplica) {
            return 100;
        }

        if (empty($subTypeStatus)) {
            return 100;
        }

        return (int) round(100 * count(array_filter($subTypeStatus)) / count($subTypeStatus));
    }

    /**
     * Detalle de completitud del perfil, un renglón por módulo/sección. Fuente
     * única de verdad para completionData()/completionPercentage() y para el
     * reporte/vista de completitud de administradores.
     */
    public function moduleBreakdown(): array
    {
        $naFlags = EmpresaModuleStatus::flagsFor($this->id);

        $recursosDetail = $this->recursosSubTypeStatus();
        $gestionDetail = $this->gestionSubTypeStatus();

        return [
            'datos_generales' => [
                'label' => 'Datos Generales',
                'percentage' => 100,
                'detail' => null,
            ],
            'sectores' => [
                'label' => 'Sectores y Servicios',
                'percentage' => $this->services()->count() > 0 ? 100 : 0,
                'detail' => null,
            ],
            'contactos' => [
                'label' => 'Contactos',
                'percentage' => $this->contacts()->count() > 0 ? 100 : 0,
                'detail' => null,
            ],
            EmpresaModuleStatus::MODULE_RECURSOS => [
                'label' => EmpresaModuleStatus::MODULES[EmpresaModuleStatus::MODULE_RECURSOS],
                'percentage' => $this->subTypeModulePercentage($naFlags[EmpresaModuleStatus::MODULE_RECURSOS], $recursosDetail),
                'detail' => $recursosDetail,
            ],
            EmpresaModuleStatus::MODULE_GESTION => [
                'label' => EmpresaModuleStatus::MODULES[EmpresaModuleStatus::MODULE_GESTION],
                'percentage' => $this->subTypeModulePercentage($naFlags[EmpresaModuleStatus::MODULE_GESTION], $gestionDetail),
                'detail' => $gestionDetail,
            ],
            EmpresaModuleStatus::MODULE_PRESENCIA => [
                'label' => EmpresaModuleStatus::MODULES[EmpresaModuleStatus::MODULE_PRESENCIA],
                'percentage' => ($this->presence()->count() > 0 || $naFlags[EmpresaModuleStatus::MODULE_PRESENCIA]) ? 100 : 0,
                'detail' => null,
            ],
            EmpresaModuleStatus::MODULE_EXPERIENCIAS => [
                'label' => EmpresaModuleStatus::MODULES[EmpresaModuleStatus::MODULE_EXPERIENCIAS],
                'percentage' => ($this->experiences()->count() > 0 || $naFlags[EmpresaModuleStatus::MODULE_EXPERIENCIAS]) ? 100 : 0,
                'detail' => null,
            ],
            EmpresaModuleStatus::MODULE_SOSTENIBILIDAD => [
                'label' => EmpresaModuleStatus::MODULES[EmpresaModuleStatus::MODULE_SOSTENIBILIDAD],
                'percentage' => ($this->sustainabilities()->count() > 0 || $naFlags[EmpresaModuleStatus::MODULE_SOSTENIBILIDAD]) ? 100 : 0,
                'detail' => null,
            ],
        ];
    }

    /**
     * Estado de completitud por módulo del perfil.
     * Un módulo cuenta como completo si tiene datos o si fue marcado "No Aplica".
     */
    public function completionData(): array
    {
        return collect($this->moduleBreakdown())
            ->mapWithKeys(fn (array $info) => [$info['label'] => $info['percentage'] >= 100])
            ->all();
    }

    public function completionPercentage(): int
    {
        return (int) round(collect($this->moduleBreakdown())->avg('percentage'));
    }

    /**
     * Borra la empresa y todo lo que depende exclusivamente de ella. Las FK de
     * assets/management/experiences/presences/sustainabilities/contact_empresa/
     * chamber_empresa/empresa_sector_service/empresa_user no tienen
     * cascadeOnDelete (ver migraciones), asi que MySQL bloquea el borrado si no
     * se limpian antes. Los registros hasMany/hasOne son exclusivos de la
     * empresa y se borran; las relaciones belongsToMany a catalogos
     * compartidos (Chamber, Service/Sector, User) solo se desvinculan
     * (detach), nunca se borran esas entidades. Contact no es un catalogo
     * real compartido, asi que se desvincula y luego se borran los que
     * quedaron sin ninguna empresa asociada.
     */
    public function deleteWithDependencies(): void
    {
        DB::transaction(function () {
            $this->assets()->delete();
            $this->management()->delete();
            $this->experiences()->delete();
            $this->presence()->delete();
            $this->sustainabilities()->delete();

            $contactIds = $this->contacts()->pluck('contacts.id')->all();
            $this->contacts()->detach();
            Contact::whereIn('id', $contactIds)->whereDoesntHave('empresas')->delete();

            $this->chambers()->detach();
            $this->services()->detach(); // limpia empresa_sector_service (cubre tambien sectors())
            $this->users()->detach();

            $this->delete(); // empresa_module_status cae solo via FK cascadeOnDelete
        });
    }
}
