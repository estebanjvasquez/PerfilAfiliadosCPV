<?php

namespace Tests\Feature\Ux;

use App\Filament\Resources\EmpresaResource;
use App\Filament\Resources\EmpresaResource\Pages\EditEmpresa;
use App\Filament\Resources\EmpresaResource\RelationManagers\ManagementRelationManager;
use App\Filament\Support\CompletionBadge;
use App\Filament\Support\NoAplicaAction;
use App\Models\Empresa;
use App\Models\EmpresaModuleStatus;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Tables\Actions\Action;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TASK-0011 Bloque 2 (Issue #2 `6438570942`): la ficha de empresa dice dónde está el usuario, qué se guarda y qué
 * completar; las etiquetas intervenidas están en español; las secciones se ven sin carga diferida.
 */
class CompanyProfilePageTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private const AFFILIATE = [
        'view_any_empresa', 'view_empresa', 'update_empresa', 'export_empresa',
        'view_any_management', 'view_management', 'create_management', 'update_management',
        'view_any_chamber', 'view_any_sector', 'view_any_service',
    ];

    private User $user;

    private Empresa $empresa;

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
        $sector = DB::table('sectors')->insertGetId(['name' => 'ZZZSECTOR', 'created_at' => now(), 'updated_at' => now()]);

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p, 'web'), self::AFFILIATE));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->user = $this->user->fresh();

        $this->actingAs($this->user);
        $this->empresa = Empresa::create(['rif' => 'J'.random_int(100000000, 999999999), 'name' => 'ZZZFICHA', 'phone' => '+58-212-5550000',
            'sector_principal_id' => $sector, 'status_id' => 1, 'billing_id' => 1, 'employees_id' => 1, 'property_id' => 1, 'origin_id' => 0]);
    }

    #[Test]
    public function the_page_explains_what_is_saved_and_titles_the_profile_sections(): void
    {
        $this->get(EmpresaResource::getUrl('edit', ['record' => $this->empresa]))
            ->assertOk()
            ->assertSeeText('Secciones del perfil')
            ->assertSeeText('se guarda al instante', false)
            ->assertSeeText('Guardar cambios')
            ->assertSeeText('Puede abrir cualquier paso con su título');
    }

    #[Test]
    public function saving_works_from_any_step_and_a_validation_error_names_the_step(): void
    {
        Livewire::test(EditEmpresa::class, ['record' => $this->empresa->getRouteKey()])
            ->assertActionExists('guardar')
            ->set('data.street', 'ZZZ CALLE NUEVA')
            ->callAction('guardar')
            ->assertHasNoFormErrors();
        $this->assertSame('ZZZ CALLE NUEVA', $this->empresa->fresh()->street);

        Livewire::test(EditEmpresa::class, ['record' => $this->empresa->getRouteKey()])
            ->set('data.name', '')
            ->callAction('guardar')
            ->assertHasFormErrors(['name' => 'required'])
            ->assertNotified('No se guardó: hay datos por corregir');
        $this->assertSame('ZZZFICHA', $this->empresa->fresh()->name);
    }

    #[Test]
    public function every_profile_section_has_a_spanish_title_guidance_and_no_lazy_placeholder(): void
    {
        $english = ['Chambers', 'Contacts', 'Users', 'Services', 'Assets', 'Name', 'Export'];
        foreach (EmpresaResource::getRelations() as $manager) {
            $title = $manager::getTitle($this->empresa, EditEmpresa::class);
            $this->assertNotContains($title, $english, class_basename($manager).' tiene título en inglés');
            $this->assertStringNotContainsString('taxonomía nueva', $title);
            $this->assertFalse($manager::isLazy(), class_basename($manager).' no debe mostrarse como caja vacía antes de cargar');
        }

        $this->assertSame('Cámaras', EmpresaResource\RelationManagers\ChambersRelationManager::getTitle($this->empresa, EditEmpresa::class));
        $this->assertSame('Contactos', EmpresaResource\RelationManagers\ContactsRelationManager::getTitle($this->empresa, EditEmpresa::class));
        $this->assertSame('Categorías CPV', EmpresaResource\RelationManagers\TaxonomyCategoriesRelationManager::getTitle($this->empresa, EditEmpresa::class));
        $this->assertSame('Sectores y servicios (histórico)', EmpresaResource\RelationManagers\ServicesRelationManager::getTitle($this->empresa, EditEmpresa::class));
    }

    #[Test]
    public function the_completion_state_is_a_badge_and_not_applicable_is_a_secondary_action(): void
    {
        $this->assertSame(Action::BADGE_VIEW, CompletionBadge::make(EmpresaModuleStatus::MODULE_GESTION)->getView(), 'el avance es un estado');
        $noAplica = NoAplicaAction::make(EmpresaModuleStatus::MODULE_GESTION);
        $this->assertSame('gray', $noAplica->getColor());
        $this->assertTrue($noAplica->isOutlined());

        // En la pestaña real: la insignia existe y la sección tiene su línea de ayuda.
        Livewire::test(ManagementRelationManager::class, ['ownerRecord' => $this->empresa, 'pageClass' => EditEmpresa::class])
            ->call('loadTable')
            ->assertTableActionExists('completitud')
            ->assertSee('Certificaciones y sistemas de gestión');
    }
}
