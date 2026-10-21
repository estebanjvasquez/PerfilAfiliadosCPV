<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\EmpresaResource\Pages\EditEmpresa;
use App\Filament\Resources\EmpresaResource\RelationManagers\AssetsRelationManager;
use App\Filament\Resources\EmpresaResource\RelationManagers\ExperiencesRelationManager;
use App\Filament\Resources\EmpresaResource\RelationManagers\ManagementRelationManager;
use App\Filament\Resources\EmpresaResource\RelationManagers\PresenceRelationManager;
use App\Filament\Resources\EmpresaResource\RelationManagers\ServicesRelationManager;
use App\Filament\Resources\EmpresaResource\RelationManagers\SustainabilitiesRelationManager;
use App\Filament\Resources\EmpresaResource\RelationManagers\TaxonomyCategoriesRelationManager;
use App\Models\Asset;
use App\Models\Empresa;
use App\Models\EmpresaTaxonomyCategory;
use App\Models\Experience;
use App\Models\Management;
use App\Models\Presence;
use App\Models\Sustainability;
use App\Models\TaxonomyCategory;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/**
 * CPV-SEC-01-R3 (Issue #2 `6316775771`): the seven Empresa relation managers not covered by R1 — Assets, Management,
 * Experiences, Presence, Sustainabilities, Services and TaxonomyCategories (R1 covered Contacts, Users, Chambers).
 *
 * Expectations are BUSINESS RULES written as literals, not read from the gate under test:
 * - a verified, linked ALFA affiliate with the module permissions writes ALFA's module data from ALFA's page;
 * - another company's (BETA) record keys are not resolvable from ALFA's managers, and BETA's rows never change;
 * - forged owner/company ids in action data do not move a record to another company;
 * - a user whose company link is revoked (or who lost the company edit permission) can no longer write through a page
 *   that was mounted before the change — every later request is re-authorized;
 * - shared/global catalogs (services, taxonomy categories, sustainability areas) are never mutated or deleted from a
 *   company's tab; only the company's own link rows change;
 * - taxonomy: only specific categories are linked by the company; a company cannot set an origin other than its
 *   self-declaration, and never sets the Cámara approver.
 * An unexpected exception fails the test; a refusal (not found / forbidden) is accepted as a denial and the database
 * is then asserted unchanged.
 *
 * How ALFA's page binds a manager to its company: the manager's `ownerRecord` is server-issued and locked (also through
 * lazy loading, whose mount params travel in a checksummed snapshot), so a browser cannot point ALFA's page at BETA.
 * Mounting a manager with BETA directly (only possible in a test harness) is checked as defence in depth.
 *
 * Synthetic users, companies and catalog rows in the disposable PostgreSQL only.
 */
class CompanyRelationManagersTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    /** The affiliate role's company-related permissions (same literal set as AccessMatrixTest). */
    private const AFFILIATE = [
        'view_any_empresa', 'view_empresa', 'update_empresa', 'delete_any_empresa', 'export_empresa',
        'view_any_asset', 'view_asset', 'create_asset', 'update_asset', 'delete_asset', 'delete_any_asset', 'export_asset',
        'view_any_experience', 'view_experience', 'create_experience', 'update_experience', 'delete_experience', 'delete_any_experience', 'export_experience',
        'view_any_management', 'view_management', 'create_management', 'update_management', 'delete_management', 'delete_any_management', 'export_management',
        'view_any_presence', 'view_presence', 'create_presence', 'update_presence', 'delete_presence', 'delete_any_presence', 'export_presence',
        'view_any_chamber', 'view_any_sector', 'view_any_service', 'view_dashboard', 'view_account_widget', 'view_my_profile', 'view_settings',
    ];

    private User $alfaUser;

    private User $betaUser;

    private Empresa $alfa;

    private Empresa $beta;

    private int $area;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach (glob(base_path('tests/Fixtures/pgsql_report_views/*.php')) as $file) {
            (require $file)->up();
        }
        // EditEmpresa::getSteps() needs one city (registered reliability defect, not a security control).
        $country = DB::table('countries')->insertGetId(['country_name' => 'ZZZPAIS', 'created_at' => now(), 'updated_at' => now()]);
        $state = DB::table('states')->insertGetId(['country_id' => $country, 'state_name' => 'ZZZESTADO', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cities')->insert(['country_id' => $country, 'states_id' => $state, 'city_name' => 'ZZZCIUDAD', 'created_at' => now(), 'updated_at' => now()]);
        // The sustainability form reads the areas catalog.
        $this->area = DB::table('areas')->insertGetId(['sust_title' => 'ZZZAREA', 'sust_description' => 'ZZZ descripcion', 'created_at' => now(), 'updated_at' => now()]);

        $this->alfaUser = $this->user(self::AFFILIATE);
        $this->betaUser = $this->user(self::AFFILIATE);
        $this->alfa = $this->company('ALFA', $this->alfaUser);
        $this->beta = $this->company('BETA', $this->betaUser);
    }

    // ------------------------------------------------------------------ fixtures

    private function user(array $permissions = []): User
    {
        $user = User::factory()->create();
        if ($permissions) {
            $user->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p, 'web'), $permissions));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function company(string $marker, User $owner): Empresa
    {
        $sector = DB::table('sectors')->insertGetId(['name' => "ZZZSEC{$marker}", 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner);
        $empresa = Empresa::create(['rif' => 'J'.random_int(100000000, 999999999), 'name' => "ZZZ{$marker}EMPRESA", 'phone' => '+58-212-5550000',
            'sector_principal_id' => $sector, 'status_id' => 1, 'billing_id' => 1, 'employees_id' => 1, 'property_id' => 1, 'origin_id' => 0]);
        auth()->logout();

        return $empresa;
    }

    private function link(Empresa $empresa, User $user): void
    {
        DB::table('empresa_user')->insertOrIgnore(['empresa_id' => $empresa->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function revoke(Empresa $empresa, User $user): void
    {
        DB::table('empresa_user')->where(['empresa_id' => $empresa->id, 'user_id' => $user->id])->delete();
    }

    /** A row created as its company's user (the models stamp the actor on insert). */
    private function as(User $user, callable $create): mixed
    {
        $this->actingAs($user);
        $row = $create();
        auth()->logout();

        return $row;
    }

    /** Mount `$manager` on `$owner`'s edit page as `$actor`, the way the page renders it. */
    private function manager(string $manager, User $actor, ?Empresa $owner = null)
    {
        return Livewire::actingAs($actor)
            ->test($manager, ['ownerRecord' => $owner ?? $this->alfa, 'pageClass' => EditEmpresa::class])
            ->call('loadTable');
    }

    /** A refusal (not found / forbidden / unresolved record) is a denial; anything else is an unexpected failure. */
    private function attempt(callable $call): void
    {
        try {
            $call();
        } catch (HttpExceptionInterface|ModelNotFoundException) {
            // denied; the database is asserted by the caller
        }
    }

    private function titleKey(string $section): string
    {
        $titles = json_decode(file_get_contents(storage_path('tituloshr.json')), true);

        return (string) array_key_first($titles[$section][0]);
    }

    private function leafCategory(bool $active = true): TaxonomyCategory
    {
        $grupo = TaxonomyCategory::create(['code' => 'CPV-'.random_int(1000, 9999), 'parent_id' => null, 'is_active' => true, 'source_version' => 'test']);
        $familia = TaxonomyCategory::create(['code' => $grupo->code.'.'.random_int(10, 98), 'parent_id' => $grupo->id, 'is_active' => true, 'source_version' => 'test']);

        return TaxonomyCategory::create(['code' => $familia->code.'.'.random_int(10, 98).'G', 'parent_id' => $familia->id, 'is_active' => $active, 'source_version' => 'test']);
    }

    // ------------------------------------------------------------------ Assets (Recursos en Venezuela)

    #[Test]
    public function assets_the_owner_writes_its_own_resources_and_another_companys_stay_untouched(): void
    {
        $type = $this->titleKey('personal');
        $betaAsset = $this->as($this->betaUser, fn () => $this->beta->assets()->create(['employee' => [['employee_type' => $type, 'junior_q' => 7, 'medium_q' => 7, 'senior_q' => 7]]]));
        $betaBefore = $betaAsset->fresh()->employee;

        // Allowed, plus a forged company id in the action data (not a form field: never taken).
        $this->manager(AssetsRelationManager::class, $this->alfaUser)
            ->callTableAction('recursos_humanos', data: [
                'no_aplica' => false,
                'employee' => [['employee_type' => $type, 'junior_q' => 1, 'medium_q' => 2, 'senior_q' => 3]],
                'empresa_id' => $this->beta->id,
            ])
            ->assertHasNoTableActionErrors();

        $alfaAsset = Asset::where('empresa_id', $this->alfa->id)->firstOrFail();
        $this->assertEquals(1, collect($alfaAsset->employee)->first()['junior_q'], 'allowed: ALFA saved its resources');
        $this->assertSame(1, Asset::where('empresa_id', $this->beta->id)->count(), 'no row moved or added to BETA');
        $this->assertEquals($betaBefore, $betaAsset->fresh()->employee, 'BETA resources unchanged');
    }

    // ------------------------------------------------------------------ Management (Sistemas de gestión)

    #[Test]
    public function management_the_owner_writes_its_own_systems_and_another_companys_stay_untouched(): void
    {
        $betaRow = $this->as($this->betaUser, fn () => $this->beta->management()->create(['iso9001' => false]));

        $this->manager(ManagementRelationManager::class, $this->alfaUser)
            ->callTableAction('calidad', data: ['no_aplica' => false, 'iso9001' => true, 'iso17025' => false, 'quality_otros' => false, 'empresa_id' => $this->beta->id])
            ->assertHasNoTableActionErrors();

        $this->assertTrue((bool) Management::where('empresa_id', $this->alfa->id)->firstOrFail()->iso9001, 'allowed: ALFA saved its section');
        $this->assertFalse((bool) $betaRow->fresh()->iso9001, 'BETA unchanged');
        $this->assertSame(1, Management::where('empresa_id', $this->beta->id)->count());
    }

    // ------------------------------------------------------------------ Experiences (Experiencia relevante)

    #[Test]
    public function experiences_another_companys_entry_keys_cannot_be_edited_or_deleted_from_alfa(): void
    {
        $betaKey = 'zzz-beta-entry';
        $betaRow = $this->as($this->betaUser, fn () => $this->beta->experiences()->create(['exp_year' => [
            $betaKey => ['type' => 'Experiencia Relevante', 'data' => ['exp_year' => 2001]],
        ]]));

        $rm = $this->manager(ExperiencesRelationManager::class, $this->alfaUser);
        $rm->callTableAction('agregar_experiencia', data: ['exp_year' => 2020])->assertHasNoTableActionErrors();
        $alfaEntries = Experience::where('empresa_id', $this->alfa->id)->firstOrFail()->exp_year;
        $this->assertSame([2020], array_map(fn ($e) => (int) $e['data']['exp_year'], array_values($alfaEntries)), 'allowed: ALFA added its entry');

        // BETA's key, as a browser would send it: resolved against ALFA's own entries only.
        $this->attempt(fn () => $this->manager(ExperiencesRelationManager::class, $this->alfaUser)->callTableAction('editar', $betaKey, data: ['exp_year' => 1999]));
        $this->attempt(fn () => $this->manager(ExperiencesRelationManager::class, $this->alfaUser)->callTableAction('eliminar', $betaKey));

        $this->assertSame([$betaKey => ['type' => 'Experiencia Relevante', 'data' => ['exp_year' => 2001]]], $betaRow->fresh()->exp_year, 'BETA entries unchanged');
        $this->assertCount(1, Experience::where('empresa_id', $this->alfa->id)->firstOrFail()->exp_year, 'ALFA did not gain BETA\'s key');
    }

    // ------------------------------------------------------------------ Presence (Presencia internacional)

    #[Test]
    public function presence_create_edit_delete_and_export_stay_inside_the_company(): void
    {
        $betaRow = $this->as($this->betaUser, fn () => $this->beta->presence()->create(['has_offices' => true, 'has_experience' => true]));

        // Allowed create, with a forged company id in the data.
        $this->manager(PresenceRelationManager::class, $this->alfaUser)
            ->callTableAction('create', data: ['has_offices' => false, 'has_experience' => false, 'empresa_id' => $this->beta->id])
            ->assertHasNoTableActionErrors();
        $alfaRow = Presence::where('empresa_id', $this->alfa->id)->firstOrFail();
        $this->assertSame(1, Presence::where('empresa_id', $this->beta->id)->count(), 'the forged id did not add a row to BETA');

        // BETA's id from ALFA's tab: not resolvable.
        $this->attempt(fn () => $this->manager(PresenceRelationManager::class, $this->alfaUser)->callTableAction('edit', $betaRow->getKey(), data: ['has_offices' => false, 'has_experience' => false]));
        $this->attempt(fn () => $this->manager(PresenceRelationManager::class, $this->alfaUser)->callTableAction('delete', $betaRow->getKey()));
        $fresh = $betaRow->fresh();
        $this->assertNotNull($fresh, 'BETA presence not deleted');
        $this->assertTrue((bool) $fresh->has_offices, 'BETA presence not edited');

        // Mixed bulk export selection: only ALFA's row is resolved.
        $rm = $this->manager(PresenceRelationManager::class, $this->alfaUser);
        $rm->set('selectedTableRecords', [(string) $alfaRow->getKey(), (string) $betaRow->getKey()]);
        $this->assertSame([$alfaRow->getKey()], array_map('intval', $rm->instance()->getSelectedTableRecords()->modelKeys()));

        // A linked editor without the presence write permissions is refused by the module policy.
        $editor = $this->user(['view_any_empresa', 'view_empresa', 'update_empresa', 'view_any_presence', 'view_presence']);
        $this->link($this->alfa, $editor);
        $this->attempt(fn () => $this->manager(PresenceRelationManager::class, $editor)->callTableAction('delete', $alfaRow->getKey()));
        $this->assertNotNull($alfaRow->fresh(), 'denied: no delete_presence');
    }

    // ------------------------------------------------------------------ Sustainabilities (Enfoques de sostenibilidad)

    #[Test]
    public function sustainabilities_create_and_delete_stay_inside_the_company_and_the_area_catalog_is_untouched(): void
    {
        $betaRow = $this->as($this->betaUser, fn () => $this->beta->sustainabilities()->create(['areas_id' => $this->area, 'sust_status' => '1']));
        $areasBefore = DB::table('areas')->orderBy('id')->get()->toArray();

        $this->manager(SustainabilitiesRelationManager::class, $this->alfaUser)
            ->callTableAction('create', data: ['areas_id' => $this->area, 'sust_status' => '2', 'empresa_id' => $this->beta->id])
            ->assertHasNoTableActionErrors();
        $this->assertSame(1, Sustainability::where('empresa_id', $this->alfa->id)->count(), 'allowed: ALFA added its approach');
        $this->assertSame(1, Sustainability::where('empresa_id', $this->beta->id)->count(), 'the forged id did not add a row to BETA');

        $this->attempt(fn () => $this->manager(SustainabilitiesRelationManager::class, $this->alfaUser)->callTableAction('delete', $betaRow->getKey()));
        $this->assertNotNull($betaRow->fresh(), 'BETA approach not deleted');
        $this->assertEquals($areasBefore, DB::table('areas')->orderBy('id')->get()->toArray(), 'the shared area catalog is unchanged');
    }

    // ------------------------------------------------------------------ Services (legacy catalog, read only)

    #[Test]
    public function services_tab_is_read_only_shows_only_the_companys_links_and_never_changes_the_catalog(): void
    {
        $sector = DB::table('sectors')->insertGetId(['name' => 'ZZZSECSERV', 'created_at' => now(), 'updated_at' => now()]);
        $alfaService = DB::table('services')->insertGetId(['sectors_id' => $sector, 'name' => 'ZZZSERVALFA', 'created_at' => now(), 'updated_at' => now()]);
        $betaService = DB::table('services')->insertGetId(['sectors_id' => $sector, 'name' => 'ZZZSERVBETA', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('empresa_sector_service')->insert([
            ['empresa_id' => $this->alfa->id, 'service_id' => $alfaService, 'created_at' => now(), 'updated_at' => now()],
            ['empresa_id' => $this->beta->id, 'service_id' => $betaService, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $catalogBefore = DB::table('services')->whereIn('id', [$alfaService, $betaService])->orderBy('id')->get()->toArray();
        $links = fn () => DB::table('empresa_sector_service')->orderBy('empresa_id')->orderBy('service_id')->get(['empresa_id', 'service_id'])->toArray();
        $linksBefore = $links();

        $rm = $this->manager(ServicesRelationManager::class, $this->alfaUser);
        $rm->assertCanSeeTableRecords([\App\Models\Service::find($alfaService)])
            ->assertCanNotSeeTableRecords([\App\Models\Service::find($betaService)]);
        foreach (['detach', 'delete', 'edit', 'attach', 'create', 'dissociate'] as $action) {
            $rm->assertTableActionDoesNotExist($action);
        }
        $this->attempt(fn () => $this->manager(ServicesRelationManager::class, $this->alfaUser)->callTableAction('detach', $betaService));

        $this->assertEquals($catalogBefore, DB::table('services')->whereIn('id', [$alfaService, $betaService])->orderBy('id')->get()->toArray(), 'catalog unchanged');
        $this->assertEquals($linksBefore, $links(), 'links unchanged');
    }

    // ------------------------------------------------------------------ TaxonomyCategories (Categorías CPV)

    #[Test]
    public function taxonomy_links_stay_inside_the_company_and_respect_the_governance_rules(): void
    {
        $leaf = $this->leafCategory();
        $other = $this->leafCategory();
        $family = TaxonomyCategory::findOrFail($leaf->parent_id);
        $betaLink = EmpresaTaxonomyCategory::create(['empresa_id' => $this->beta->id, 'category_id' => $other->id, 'origen' => EmpresaTaxonomyCategory::ORIGEN_SUGGESTED, 'es_principal' => false]);
        $categoriesBefore = TaxonomyCategory::query()->whereIn('id', [$leaf->id, $other->id, $family->id])->orderBy('id')->get(['id', 'code', 'parent_id', 'is_active', 'level'])->toArray();

        // Allowed: ALFA links a specific category as its own declaration; a family in the same request is not linked.
        $this->manager(TaxonomyCategoriesRelationManager::class, $this->alfaUser)
            ->callTableAction('buscarYAgregar', data: ['categorias' => [$leaf->id, $family->id], 'tipo' => 'secundaria'])
            ->assertHasNoTableActionErrors();
        $alfaLinks = EmpresaTaxonomyCategory::where('empresa_id', $this->alfa->id)->get();
        $this->assertSame([$leaf->id], $alfaLinks->pluck('category_id')->map(fn ($v) => (int) $v)->all(), 'only the specific category is linked');
        $this->assertSame(EmpresaTaxonomyCategory::ORIGEN_SELF_DECLARED, $alfaLinks->first()->origen, 'a company link is a self-declaration');
        $this->assertNull($alfaLinks->first()->approved_by, 'the company never sets the Cámara approver');

        // A self-declared row cannot be turned into «Confirmada» (the action is offered for suggestions only).
        $own = $alfaLinks->first();
        $this->attempt(fn () => $this->manager(TaxonomyCategoriesRelationManager::class, $this->alfaUser)->callTableAction('confirmar', $own->getKey()));
        $this->assertSame(EmpresaTaxonomyCategory::ORIGEN_SELF_DECLARED, $own->fresh()->origen, 'no origin change on a self-declared link');

        // BETA's link from ALFA's tab: not resolvable for confirm, principal toggle or removal.
        foreach (['confirmar', 'togglePrincipal', 'quitar'] as $action) {
            $this->attempt(fn () => $this->manager(TaxonomyCategoriesRelationManager::class, $this->alfaUser)->callTableAction($action, $betaLink->getKey()));
        }
        $betaFresh = $betaLink->fresh();
        $this->assertNotNull($betaFresh, 'BETA link not removed');
        $this->assertSame(EmpresaTaxonomyCategory::ORIGEN_SUGGESTED, $betaFresh->origen, 'BETA origin unchanged');
        $this->assertFalse((bool) $betaFresh->es_principal, 'BETA principal flag unchanged');

        // Removing ALFA's own link deletes only that link row, never the shared category.
        $this->manager(TaxonomyCategoriesRelationManager::class, $this->alfaUser)->callTableAction('quitar', $own->getKey());
        $this->assertNull($own->fresh(), 'allowed: ALFA removed its link');
        $this->assertEquals($categoriesBefore, TaxonomyCategory::query()->whereIn('id', [$leaf->id, $other->id, $family->id])->orderBy('id')->get(['id', 'code', 'parent_id', 'is_active', 'level'])->toArray(), 'the shared taxonomy is unchanged');
    }

    // ------------------------------------------------------------------ re-authorization after mount (all seven + the page)

    /**
     * A page mounted while the user was linked must not keep writing after the link is revoked. Each request after
     * mount re-checks: authenticated, verified, linked to the owner company now, and allowed to edit it.
     */
    #[Test]
    public function a_page_mounted_before_the_link_was_revoked_cannot_write_any_module(): void
    {
        $type = $this->titleKey('personal');
        $leaf = $this->leafCategory();
        $presence = $this->as($this->alfaUser, fn () => $this->alfa->presence()->create(['has_offices' => true, 'has_experience' => true]));
        $sustain = $this->as($this->alfaUser, fn () => $this->alfa->sustainabilities()->create(['areas_id' => $this->area, 'sust_status' => '1']));
        $entryKey = 'zzz-alfa-entry';
        $this->as($this->alfaUser, fn () => $this->alfa->experiences()->create(['exp_year' => [$entryKey => ['type' => 'Experiencia Relevante', 'data' => ['exp_year' => 2010]]]]));
        $link = EmpresaTaxonomyCategory::create(['empresa_id' => $this->alfa->id, 'category_id' => $leaf->id, 'origen' => EmpresaTaxonomyCategory::ORIGEN_SELF_DECLARED, 'es_principal' => false]);

        // Mounted while linked …
        $mounted = [
            'assets' => $this->manager(AssetsRelationManager::class, $this->alfaUser),
            'management' => $this->manager(ManagementRelationManager::class, $this->alfaUser),
            'experiences' => $this->manager(ExperiencesRelationManager::class, $this->alfaUser),
            'presence' => $this->manager(PresenceRelationManager::class, $this->alfaUser),
            'sustain' => $this->manager(SustainabilitiesRelationManager::class, $this->alfaUser),
            'taxonomy' => $this->manager(TaxonomyCategoriesRelationManager::class, $this->alfaUser),
            'page' => Livewire::actingAs($this->alfaUser)->test(EditEmpresa::class, ['record' => $this->alfa->getRouteKey()]),
        ];
        // … then the link is revoked.
        $this->revoke($this->alfa, $this->alfaUser);

        $this->attempt(fn () => $mounted['assets']->callTableAction('recursos_humanos', data: ['no_aplica' => false, 'employee' => [['employee_type' => $type, 'junior_q' => 9, 'medium_q' => 9, 'senior_q' => 9]]]));
        $this->attempt(fn () => $mounted['management']->callTableAction('calidad', data: ['no_aplica' => false, 'iso9001' => true, 'iso17025' => true, 'quality_otros' => false]));
        $this->attempt(fn () => $mounted['experiences']->callTableAction('eliminar', $entryKey));
        $this->attempt(fn () => $mounted['presence']->callTableAction('delete', $presence->getKey()));
        $this->attempt(fn () => $mounted['sustain']->callTableAction('delete', $sustain->getKey()));
        $this->attempt(fn () => $mounted['taxonomy']->callTableAction('quitar', $link->getKey()));
        $this->attempt(fn () => $mounted['page']->set('data.name', 'ZZZ REVOCADA')->call('save'));

        $this->assertSame(0, Asset::where('empresa_id', $this->alfa->id)->count(), 'assets: no write after revocation');
        $this->assertSame(0, Management::where('empresa_id', $this->alfa->id)->count(), 'management: no write after revocation');
        $this->assertArrayHasKey($entryKey, Experience::where('empresa_id', $this->alfa->id)->firstOrFail()->exp_year, 'experiences: no delete after revocation');
        $this->assertNotNull($presence->fresh(), 'presence: no delete after revocation');
        $this->assertNotNull($sustain->fresh(), 'sustainabilities: no delete after revocation');
        $this->assertNotNull($link->fresh(), 'taxonomy: no removal after revocation');
        $this->assertSame('ZZZALFAEMPRESA', $this->alfa->fresh()->name, 'company page: no save after revocation');
    }

    #[Test]
    public function a_page_mounted_before_the_edit_permission_was_withdrawn_cannot_write(): void
    {
        $presence = $this->as($this->alfaUser, fn () => $this->alfa->presence()->create(['has_offices' => true, 'has_experience' => true]));
        $mounted = $this->manager(ManagementRelationManager::class, $this->alfaUser);
        $presenceTab = $this->manager(PresenceRelationManager::class, $this->alfaUser);

        $this->alfaUser->revokePermissionTo('update_empresa');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->attempt(fn () => $mounted->callTableAction('calidad', data: ['no_aplica' => false, 'iso9001' => true, 'iso17025' => false, 'quality_otros' => false]));
        $this->attempt(fn () => $presenceTab->callTableAction('delete', $presence->getKey()));
        $this->assertSame(0, Management::where('empresa_id', $this->alfa->id)->count(), 'no write without the company edit permission');
        $this->assertNotNull($presence->fresh());
    }

    #[Test]
    public function a_manager_bound_to_another_company_is_refused_even_outside_the_page(): void
    {
        // Defence in depth: only a test harness can bind a manager directly; ALFA's user must still be refused on BETA.
        foreach ([AssetsRelationManager::class, ManagementRelationManager::class, ExperiencesRelationManager::class,
            PresenceRelationManager::class, SustainabilitiesRelationManager::class, ServicesRelationManager::class,
            TaxonomyCategoriesRelationManager::class] as $manager) {
            $refused = false;
            try {
                $this->manager($manager, $this->alfaUser, $this->beta);
            } catch (HttpExceptionInterface|ModelNotFoundException) {
                $refused = true;
            }
            $this->assertTrue($refused, class_basename($manager).' refuses a company the user is not linked to');
        }
        $this->assertSame(0, Asset::where('empresa_id', $this->beta->id)->count());
    }
}
