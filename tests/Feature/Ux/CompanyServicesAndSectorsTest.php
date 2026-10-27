<?php

namespace Tests\Feature\Ux;

use App\Filament\Resources\EmpresaResource\Pages\EditEmpresa;
use App\Filament\Resources\EmpresaResource\RelationManagers\ServicesRelationManager;
use App\Filament\Resources\EmpresaResource\RelationManagers\TaxonomyCategoriesRelationManager;
use App\Models\Empresa;
use App\Models\EmpresaTaxonomyCategory;
use App\Models\Service;
use App\Models\TaxonomyCategory;
use App\Models\TaxonomySelectionSettings;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/**
 * TASK-0011 Bloque 1 (Issue #2 `6438570942`): servicios viejos y sector principal.
 *
 * Reglas de negocio escritas como literales (ver el archivo de la tarea, R1–R4):
 * - R1: quien puede editar la empresa (afiliado vinculado o admin vinculado) desvincula servicios viejos de SU empresa;
 *   el catálogo no cambia, vincular sigue cerrado, otra empresa no se toca y una página revocada no escribe;
 * - R2: sin sector principal, quien no es super_admin guarda igual (el campo no es obligatorio para él); el super_admin
 *   sí debe elegirlo y el mensaje sale en español;
 * - R3: un guardado se detiene solo si el cambio de sectores deja fuera servicios que estaban dentro; una
 *   inconsistencia anterior no impide guardar y se avisa;
 * - R4: el cambio de categoría principal con el límite lleno es secuencial y el aviso lo explica.
 *
 * Usuarios, empresas y catálogo sintéticos, en la PostgreSQL desechable del runner.
 */
class CompanyServicesAndSectorsTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    /** Mismo conjunto literal de permisos de afiliado que AccessMatrixTest/CompanyRelationManagersTest. */
    private const AFFILIATE = [
        'view_any_empresa', 'view_empresa', 'update_empresa', 'delete_any_empresa', 'export_empresa',
        'view_any_chamber', 'view_any_sector', 'view_any_service', 'view_dashboard', 'view_account_widget', 'view_my_profile', 'view_settings',
    ];

    private User $alfaUser;

    private User $betaUser;

    private Empresa $alfa;

    private Empresa $beta;

    /** @var array<string, int> */
    private array $sector = [];

    /** @var array<string, int> */
    private array $service = [];

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach (glob(base_path('tests/Fixtures/pgsql_report_views/*.php')) as $file) {
            (require $file)->up();
        }
        $country = DB::table('countries')->insertGetId(['country_name' => 'ZZZPAIS', 'created_at' => now(), 'updated_at' => now()]);
        $state = DB::table('states')->insertGetId(['country_id' => $country, 'state_name' => 'ZZZESTADO', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cities')->insert(['country_id' => $country, 'states_id' => $state, 'city_name' => 'ZZZCIUDAD', 'created_at' => now(), 'updated_at' => now()]);

        foreach (['P', 'S', 'X'] as $s) {
            $this->sector[$s] = DB::table('sectors')->insertGetId(['name' => "ZZZSECTOR{$s}", 'created_at' => now(), 'updated_at' => now()]);
            $this->service[$s] = DB::table('services')->insertGetId(['sectors_id' => $this->sector[$s], 'name' => "ZZZSERVICIO{$s}", 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->alfaUser = $this->user(self::AFFILIATE);
        $this->betaUser = $this->user(self::AFFILIATE);
        $this->alfa = $this->company('ALFA', $this->alfaUser, $this->sector['P']);
        $this->beta = $this->company('BETA', $this->betaUser, $this->sector['P']);
    }

    // ------------------------------------------------------------------ fixtures

    private function user(array $permissions = [], bool $superAdmin = false): User
    {
        $user = User::factory()->create();
        if ($permissions) {
            $user->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p, 'web'), $permissions));
        }
        if ($superAdmin) {
            $user->assignRole(Role::findOrCreate(config('filament-shield.super_admin.name'), 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function company(string $marker, User $owner, ?int $principal, ?int $secundario = null): Empresa
    {
        $this->actingAs($owner);
        $empresa = Empresa::create(['rif' => 'J'.random_int(100000000, 999999999), 'name' => "ZZZ{$marker}EMPRESA", 'phone' => '+58-212-5550000',
            'sector_principal_id' => $principal, 'sector_secundario_id' => $secundario,
            'status_id' => 1, 'billing_id' => 1, 'employees_id' => 1, 'property_id' => 1, 'origin_id' => 0]);
        auth()->logout();

        return $empresa;
    }

    private function link(Empresa $empresa, User $user): void
    {
        DB::table('empresa_user')->insertOrIgnore(['empresa_id' => $empresa->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function withServices(Empresa $empresa, string ...$keys): void
    {
        foreach ($keys as $k) {
            DB::table('empresa_sector_service')->insert(['empresa_id' => $empresa->id, 'service_id' => $this->service[$k], 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** @return int[] */
    private function linkedServices(Empresa $empresa): array
    {
        return DB::table('empresa_sector_service')->where('empresa_id', $empresa->id)->orderBy('service_id')->pluck('service_id')->map(fn ($v) => (int) $v)->all();
    }

    private function catalog(): array
    {
        return DB::table('services')->whereIn('id', $this->service)->orderBy('id')->get(['id', 'sectors_id', 'name'])->toArray();
    }

    private function manager(string $manager, User $actor, ?Empresa $owner = null)
    {
        return Livewire::actingAs($actor)
            ->test($manager, ['ownerRecord' => $owner ?? $this->alfa, 'pageClass' => EditEmpresa::class])
            ->call('loadTable');
    }

    private function page(User $actor, ?Empresa $empresa = null)
    {
        return Livewire::actingAs($actor)->test(EditEmpresa::class, ['record' => ($empresa ?? $this->alfa)->getRouteKey()]);
    }

    /** Acción de tabla como la manda el navegador; true si el servidor la rechazó (404) o el registro no se resolvió. */
    private function browserAction($component, string $name, int|string|null $key = null): bool
    {
        try {
            $component->call('mountTableAction', $name, $key === null ? null : (string) $key);
            if ($component->instance() === null) {
                $component->assertNotFound();

                return true;
            }
            $component->call('callMountedTableAction');

            return $component->instance() === null;
        } catch (HttpExceptionInterface|ModelNotFoundException) {
            return true;
        }
    }

    // ------------------------------------------------------------------ R3 (regla pura)

    #[Test]
    public function the_two_sector_rule_only_blocks_a_change_that_leaves_services_out(): void
    {
        // Coherente antes y después.
        $this->assertSame(['blocked' => [], 'outside' => []], EditEmpresa::sectorCoherence([1, 2], [1, 2], [1, 2]));
        // Legacy sin sectores: ya estaba todo fuera; guardar otros datos no empeora.
        $this->assertSame(['blocked' => [], 'outside' => [1, 2]], EditEmpresa::sectorCoherence([1, 2], [], []));
        // Definir el principal mejora la coherencia: no se bloquea, queda el aviso por el resto.
        $this->assertSame(['blocked' => [], 'outside' => [2]], EditEmpresa::sectorCoherence([1, 2], [], [1]));
        // Cambiar el secundario deja fuera el sector 2 que estaba dentro: se bloquea.
        $this->assertSame(['blocked' => [2], 'outside' => [2]], EditEmpresa::sectorCoherence([1, 2], [1, 2], [1, 3]));
    }

    // ------------------------------------------------------------------ R2/R3 en la ficha (afiliado)

    #[Test]
    public function an_affiliate_saves_a_company_without_principal_sector_and_with_legacy_services(): void
    {
        // Como GEOHIDRA (id 72): sin sector principal y con servicios viejos de varios sectores.
        $legacy = $this->company('LEGACY', $this->alfaUser, null);
        $this->withServices($legacy, 'P', 'X');

        $this->page($this->alfaUser, $legacy)
            ->set('data.name', 'ZZZ LEGACY CORREGIDA')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Cambios guardados, con un pendiente');

        $fresh = $legacy->fresh();
        $this->assertSame('ZZZ LEGACY CORREGIDA', $fresh->name, 'el afiliado guardó un cambio válido');
        $this->assertNull($fresh->sector_principal_id, 'el afiliado no define el sector principal');
        $this->assertSame([$this->service['P'], $this->service['X']], $this->linkedServices($legacy), 'guardar no toca los servicios');
    }

    #[Test]
    public function an_affiliate_with_services_in_its_principal_sector_is_no_longer_blocked(): void
    {
        // D3: el principal deshabilitado no viene en el formulario; antes sus servicios contaban como «fuera».
        $this->withServices($this->alfa, 'P');

        $this->page($this->alfaUser)
            ->set('data.name', 'ZZZ ALFA NUEVO NOMBRE')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('ZZZ ALFA NUEVO NOMBRE', $this->alfa->fresh()->name);
        Notification::assertNotNotified('Cambios guardados, con un pendiente');
    }

    #[Test]
    public function a_secondary_sector_change_that_leaves_services_out_is_stopped_and_nothing_is_saved(): void
    {
        $empresa = $this->company('DOS', $this->alfaUser, $this->sector['P'], $this->sector['S']);
        $this->withServices($empresa, 'P', 'S');

        $this->page($this->alfaUser, $empresa)
            ->set('data.name', 'ZZZ NO DEBE GUARDARSE')
            ->set('data.sector_secundario_id', $this->sector['X'])
            ->call('save')
            ->assertNotified('Este cambio de sectores deja servicios fuera');

        $fresh = $empresa->fresh();
        $this->assertSame('ZZZDOSEMPRESA', $fresh->name, 'nada se guardó');
        $this->assertSame($this->sector['S'], (int) $fresh->sector_secundario_id);

        // Secuencial: primero se desvincula el servicio del sector S y después el cambio pasa.
        $this->manager(ServicesRelationManager::class, $this->alfaUser, $empresa)
            ->callTableAction('detach', $this->service['S'])
            ->assertHasNoTableActionErrors();
        $this->page($this->alfaUser, $empresa)
            ->set('data.sector_secundario_id', $this->sector['X'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame($this->sector['X'], (int) $empresa->fresh()->sector_secundario_id);
    }

    #[Test]
    public function the_affiliate_cannot_set_the_principal_sector_even_by_forging_the_field(): void
    {
        $this->page($this->alfaUser)
            ->set('data.sector_principal_id', $this->sector['X'])
            ->call('save');

        $this->assertSame($this->sector['P'], (int) $this->alfa->fresh()->sector_principal_id, 'el campo deshabilitado no se envía');
    }

    // ------------------------------------------------------------------ R2 (super_admin)

    #[Test]
    public function the_super_admin_must_choose_the_principal_sector_and_the_message_is_in_spanish(): void
    {
        $admin = $this->user(self::AFFILIATE, superAdmin: true);
        $legacy = $this->company('ADMIN', $admin, null);
        $this->withServices($legacy, 'P', 'X');

        $this->page($admin, $legacy)
            ->call('save')
            ->assertHasFormErrors(['sector_principal_id' => 'required']);
        $errors = $this->page($admin, $legacy)->call('save')->errors()->get('data.sector_principal_id');
        $this->assertSame(['El campo sector principal es obligatorio.'], $errors);

        // Establece el principal: mejora la coherencia, se guarda y avisa por el servicio del sector X.
        $this->page($admin, $legacy)
            ->set('data.sector_principal_id', $this->sector['P'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Cambios guardados, con un pendiente');
        $this->assertSame($this->sector['P'], (int) $legacy->fresh()->sector_principal_id);
    }

    // ------------------------------------------------------------------ R1 (desvincular servicios viejos)

    #[Test]
    public function the_owner_detaches_its_own_legacy_services_and_nothing_else_changes(): void
    {
        $this->withServices($this->alfa, 'P', 'S', 'X');
        $this->withServices($this->beta, 'S');
        $catalog = $this->catalog();

        $rm = $this->manager(ServicesRelationManager::class, $this->alfaUser);
        $rm->assertTableActionExists('detach')
            ->assertTableActionDoesNotExist('attach')
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete');
        $rm->callTableAction('detach', $this->service['X'])->assertHasNoTableActionErrors();

        $this->assertSame([$this->service['P'], $this->service['S']], $this->linkedServices($this->alfa), 'solo se quitó el vínculo elegido');
        $this->assertSame([$this->service['S']], $this->linkedServices($this->beta), 'BETA no cambió aunque comparte el servicio S');
        $this->assertEquals($catalog, $this->catalog(), 'el catálogo de servicios no cambió');

        // En lote: quita adicionales de ALFA; una clave de otra empresa en la selección no se resuelve.
        $this->manager(ServicesRelationManager::class, $this->alfaUser)
            ->callTableBulkAction('detach', [$this->service['S']])
            ->assertHasNoTableBulkActionErrors();
        $this->assertSame([$this->service['P']], $this->linkedServices($this->alfa));
        $this->assertSame([$this->service['S']], $this->linkedServices($this->beta));
    }

    #[Test]
    public function another_companys_service_link_cannot_be_detached_from_alfa(): void
    {
        $this->withServices($this->alfa, 'P');
        $this->withServices($this->beta, 'X');

        $this->browserAction($this->manager(ServicesRelationManager::class, $this->alfaUser), 'detach', $this->service['X']);
        $this->assertSame([$this->service['X']], $this->linkedServices($this->beta), 'BETA no cambió');
        $this->assertSame([$this->service['P']], $this->linkedServices($this->alfa));

        // Vinculado a BETA no está: el gestor ni siquiera monta sobre BETA.
        $this->expectException(HttpExceptionInterface::class);
        $this->withoutExceptionHandling();
        $this->manager(ServicesRelationManager::class, $this->alfaUser, $this->beta);
    }

    #[Test]
    public function a_services_tab_mounted_before_the_link_was_revoked_cannot_detach(): void
    {
        $this->withServices($this->alfa, 'P', 'X');
        $rm = $this->manager(ServicesRelationManager::class, $this->alfaUser);
        DB::table('empresa_user')->where(['empresa_id' => $this->alfa->id, 'user_id' => $this->alfaUser->id])->delete();

        $this->assertTrue($this->browserAction($rm, 'detach', $this->service['X']), 'rechazado (404)');
        $this->assertSame([$this->service['P'], $this->service['X']], $this->linkedServices($this->alfa));
    }

    #[Test]
    public function a_linked_admin_detaches_with_the_same_rule(): void
    {
        $admin = $this->user(self::AFFILIATE, superAdmin: true);
        $this->link($this->alfa, $admin);
        $this->withServices($this->alfa, 'P', 'X');

        $this->manager(ServicesRelationManager::class, $admin)->callTableAction('detach', $this->service['X'])->assertHasNoTableActionErrors();
        $this->assertSame([$this->service['P']], $this->linkedServices($this->alfa));
    }

    #[Test]
    public function a_linked_user_without_the_company_edit_permission_cannot_detach(): void
    {
        $viewer = $this->user(['view_any_empresa', 'view_empresa']);
        $this->link($this->alfa, $viewer);
        $this->withServices($this->alfa, 'X');

        $this->browserAction($this->manager(ServicesRelationManager::class, $viewer), 'detach', $this->service['X']);
        $this->assertSame([$this->service['X']], $this->linkedServices($this->alfa));
    }

    // ------------------------------------------------------------------ R4 (principal de Categorías CPV)

    #[Test]
    public function changing_the_principal_category_with_the_limit_full_is_sequential(): void
    {
        TaxonomySelectionSettings::current()->update(['max_categorias_principales' => 1, 'max_categorias_secundarias' => null]);
        $first = $this->linkCategory(true);
        $second = $this->linkCategory(false);

        $this->manager(TaxonomyCategoriesRelationManager::class, $this->alfaUser)
            ->callTableAction('togglePrincipal', $second->getKey())
            ->assertNotified('Límite de categorías principales alcanzado');
        $this->assertFalse((bool) $second->fresh()->es_principal, 'no hay dos principales');

        $this->manager(TaxonomyCategoriesRelationManager::class, $this->alfaUser)->callTableAction('togglePrincipal', $first->getKey());
        $this->manager(TaxonomyCategoriesRelationManager::class, $this->alfaUser)->callTableAction('togglePrincipal', $second->getKey());
        $this->assertFalse((bool) $first->fresh()->es_principal);
        $this->assertTrue((bool) $second->fresh()->es_principal, 'cambio de principal en dos pasos');
    }

    private function linkCategory(bool $principal): EmpresaTaxonomyCategory
    {
        $grupo = TaxonomyCategory::create(['code' => 'CPV-'.random_int(1000, 9999), 'parent_id' => null, 'is_active' => true, 'source_version' => 'test']);
        $familia = TaxonomyCategory::create(['code' => $grupo->code.'.'.random_int(10, 98), 'parent_id' => $grupo->id, 'is_active' => true, 'source_version' => 'test']);
        $leaf = TaxonomyCategory::create(['code' => $familia->code.'.'.random_int(10, 98).'G', 'parent_id' => $familia->id, 'is_active' => true, 'source_version' => 'test']);

        return EmpresaTaxonomyCategory::create(['empresa_id' => $this->alfa->id, 'category_id' => $leaf->id, 'origen' => EmpresaTaxonomyCategory::ORIGEN_SELF_DECLARED, 'es_principal' => $principal]);
    }
}
