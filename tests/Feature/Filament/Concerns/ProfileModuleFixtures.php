<?php

namespace Tests\Feature\Filament\Concerns;

use App\Filament\Resources\EmpresaResource\Pages\EditEmpresa;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * CPV-FIRST-SAVE-POSTGRES-COMPATIBILITY (Issue #2 `6212967641`): datos SINTÉTICOS para los cinco módulos
 * del perfil, sobre el PostgreSQL desechable del entorno aislado.
 *
 * Catálogos mínimos y realistas que esos formularios necesitan para renderizar: país, estado y ciudad;
 * sector; sector de infraestructura; y dos áreas de sostenibilidad. Los catálogos se insertan
 * directamente porque son preparación. Las filas de los módulos, en cambio, se crean SIEMPRE por la
 * acción real de la interfaz, con los eventos de Eloquent activos.
 *
 * Supuesto de aprovisionamiento declarado: el entorno aislado no trae los permisos de Shield que
 * producción guarda en su base, y super_admin no pasa por la gate (define_via_gate = false). Por eso se
 * otorgan los permisos que exigen las policies reales de cada módulo: al afiliado directamente, y al
 * admin a través de su rol.
 */
trait ProfileModuleFixtures
{
    /** Permisos que piden las policies reales (app/Policies/*Policy.php). Sustainability no tiene policy. */
    protected const MODULE_PERMISSIONS = [
        'view_any_empresa', 'view_empresa', 'create_empresa', 'update_empresa',
        'view_any_management', 'view_management', 'create_management', 'update_management',
        'view_any_asset', 'view_asset', 'create_asset', 'update_asset',
        'view_any_experience', 'view_experience', 'create_experience', 'update_experience',
        'view_any_presence', 'view_presence', 'create_presence', 'update_presence', 'delete_presence',
    ];

    /** @var array{country: int, city: int, sector: int, infrasector: int, area1: int, area2: int} */
    protected array $catalog = [];

    protected function seedCatalogs(): void
    {
        $now = now();
        $db = DB::connection();
        $countryId = $db->table('countries')->insertGetId(['country_name' => 'ZZZ País sintético', 'created_at' => $now, 'updated_at' => $now]);
        $stateId = $db->table('states')->insertGetId(['country_id' => $countryId, 'state_name' => 'ZZZ Estado sintético', 'created_at' => $now, 'updated_at' => $now]);
        $cityId = $db->table('cities')->insertGetId(['country_id' => $countryId, 'states_id' => $stateId, 'city_name' => 'ZZZ Ciudad sintética', 'created_at' => $now, 'updated_at' => $now]);
        $this->catalog = [
            'country' => $countryId,
            'state' => $stateId,
            'city' => $cityId,
            'sector' => $db->table('sectors')->insertGetId(['name' => 'ZZZ Sector sintético', 'created_at' => $now, 'updated_at' => $now]),
            'infrasector' => $db->table('infrasectors')->insertGetId(['sector_name' => 'ZZZ Infraestructura sintética', 'created_at' => $now, 'updated_at' => $now]),
            'area1' => $db->table('areas')->insertGetId(['sust_title' => 'ZZZ Área uno', 'sust_description' => 'Área sintética uno', 'created_at' => $now, 'updated_at' => $now]),
            'area2' => $db->table('areas')->insertGetId(['sust_title' => 'ZZZ Área dos', 'sust_description' => 'Área sintética dos', 'created_at' => $now, 'updated_at' => $now]),
        ];
    }

    protected function permissions(): array
    {
        return array_map(fn ($p) => Permission::findOrCreate($p, 'web'), self::MODULE_PERMISSIONS);
    }

    protected function affiliate(bool $withPermissions = true): User
    {
        $user = User::factory()->create();
        if ($withPermissions) {
            $user->givePermissionTo($this->permissions());
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    protected function admin(): User
    {
        $role = Role::findOrCreate(config('filament-shield.super_admin.name'), 'web');
        $role->givePermissionTo($this->permissions());
        $user = User::factory()->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /** Empresa creada por su usuario, como en el panel: Empresa::created() la vincula a Auth::user(). */
    protected function companyOf(User $user): Empresa
    {
        $this->actingAs($user);

        return Empresa::create([
            'rif' => 'J'.random_int(100000000, 999999999),
            'name' => 'ZZZ EMPRESA '.strtoupper(uniqid()),
            'phone' => '+58-212-5551234',
            'website' => 'https://ejemplo.test',
        ]);
    }

    protected function manager(string $class, Empresa $empresa, User $user): Testable
    {
        return Livewire::actingAs($user)->test($class, ['ownerRecord' => $empresa, 'pageClass' => EditEmpresa::class]);
    }

    protected function rows(string $table, Empresa $empresa): \Illuminate\Support\Collection
    {
        return DB::connection()->table($table)->where('empresa_id', $empresa->id)->orderBy('id')->get();
    }
}
