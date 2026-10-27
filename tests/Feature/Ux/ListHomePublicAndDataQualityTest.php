<?php

namespace Tests\Feature\Ux;

use App\Filament\Resources\EmpresaResource\Pages\EditEmpresa;
use App\Filament\Resources\EmpresaResource\Pages\ListEmpresas;
use App\Filament\Widgets\MyCompaniesWidget;
use App\Models\Empresa;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TASK-0011 Bloque 3 (Issue #2 `6438570942`): listado de Empresas, Inicio, página pública, registro y la web de la
 * empresa. Datos sintéticos en la PostgreSQL desechable del runner.
 */
class ListHomePublicAndDataQualityTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private const AFFILIATE = ['view_any_empresa', 'view_empresa', 'update_empresa', 'export_empresa'];

    private int $sector;

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
        $this->sector = DB::table('sectors')->insertGetId(['name' => 'ZZZSECTOR', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function affiliate(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p, 'web'), self::AFFILIATE));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function company(User $owner, string $name, array $extra = []): Empresa
    {
        $this->actingAs($owner);

        return Empresa::create(array_merge(['rif' => 'J'.random_int(100000000, 999999999), 'name' => $name, 'phone' => '+58-212-5550000',
            'sector_principal_id' => $this->sector, 'status_id' => 1, 'billing_id' => 1, 'employees_id' => 1, 'property_id' => 1, 'origin_id' => 0], $extra));
    }

    // ------------------------------------------------------------------ listado de Empresas

    #[Test]
    public function the_company_list_offers_edit_profile_first_and_ten_rows_per_page(): void
    {
        $user = $this->affiliate();
        $empresa = $this->company($user, 'ZZZLISTADO');

        $list = Livewire::actingAs($user)->test(ListEmpresas::class)->call('loadTable');
        $list->assertTableActionExists('editar_perfil')
            ->assertTableActionHasUrl('editar_perfil', \App\Filament\Resources\EmpresaResource::getUrl('edit', ['record' => $empresa]), $empresa)
            ->assertTableColumnExists('completion_percentage');
        $this->assertSame(10, $list->instance()->getTable()->getDefaultPaginationPageOption());
        $names = array_map(fn ($action) => $action->getName(), array_values($list->instance()->getTable()->getActions()));
        $this->assertSame(['editar_perfil', 'perfil_completo', 'perfil_secciones'], $names, '«Editar perfil» es la primera acción de la fila');
    }

    // ------------------------------------------------------------------ Inicio

    #[Test]
    public function home_shows_the_affiliates_company_with_its_progress_and_pending_sections(): void
    {
        $user = $this->affiliate();
        $this->company($user, 'ZZZMIEMPRESA');

        $this->actingAs($user);
        $this->assertTrue(MyCompaniesWidget::canView());
        Livewire::test(MyCompaniesWidget::class)
            ->assertSee('Mi empresa')
            ->assertSee('ZZZMIEMPRESA')
            ->assertSee('Pendiente:')
            ->assertSee('Editar perfil');
    }

    #[Test]
    public function home_summarizes_many_companies_and_hides_the_card_without_companies(): void
    {
        $staff = $this->affiliate();
        foreach (range(1, MyCompaniesWidget::MAX_DETAILED + 1) as $i) {
            $this->company($staff, "ZZZMUCHAS{$i}");
        }
        $this->actingAs($staff);
        Livewire::test(MyCompaniesWidget::class)
            ->assertSee('Tiene acceso a '.(MyCompaniesWidget::MAX_DETAILED + 1).' empresas')
            ->assertDontSee('ZZZMUCHAS1');

        $this->actingAs($this->affiliate());
        $this->assertFalse(MyCompaniesWidget::canView(), 'sin empresas vinculadas no hay tarjeta');
    }

    // ------------------------------------------------------------------ página pública y registro

    #[Test]
    public function the_public_page_has_the_accent_and_a_working_join_link(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<title>Cámara Petrolera de Venezuela</title>', false)
            ->assertDontSee('Camara Petrolera', false)
            ->assertSee('href="'.route('filament.admin.auth.register').'">haz clic aquí y regístrate</a>', false);
    }

    #[Test]
    public function registration_and_the_verification_page_say_what_comes_next(): void
    {
        $this->get(route('filament.admin.auth.register'))->assertOk()->assertSeeText('Después de registrarse');

        $this->actingAs(User::factory()->unverified()->create());
        $this->get(route('filament.admin.auth.email-verification.prompt'))
            ->assertOk()
            ->assertSeeText('Próximos pasos')
            ->assertSeeText('Avise a la Cámara que se registró');
    }

    // ------------------------------------------------------------------ web de la empresa

    #[Test]
    public function the_website_is_normalized_and_validated_only_when_it_changes(): void
    {
        $this->assertNull(EditEmpresa::normalizeWebsite('  '));
        $this->assertSame('https://www.x.com', EditEmpresa::normalizeWebsite(' www.x.com '));
        $this->assertSame('http://www.x.com', EditEmpresa::normalizeWebsite('http://www.x.com'));
        $this->assertTrue(EditEmpresa::isValidWebsite('https://www.geohidra.com'));
        $this->assertFalse(EditEmpresa::isValidWebsite('http://wwwgeohidracom'));
        $this->assertFalse(EditEmpresa::isValidWebsite('ftp://www.x.com'));

        $user = $this->affiliate();
        $legacy = $this->company($user, 'ZZZWEBVIEJA', ['website' => 'http://wwwgeohidracom']);

        // Una web vieja mal escrita, sin tocar, no impide guardar otro dato y no se reescribe.
        Livewire::actingAs($user)->test(EditEmpresa::class, ['record' => $legacy->getRouteKey()])
            ->set('data.street', 'ZZZ CALLE')
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('http://wwwgeohidracom', $legacy->fresh()->website);

        // Si la cambia, se valida…
        Livewire::actingAs($user)->test(EditEmpresa::class, ['record' => $legacy->getRouteKey()])
            ->set('data.website', 'wwwotramalcom')
            ->call('save')
            ->assertHasFormErrors(['website']);
        $this->assertSame('http://wwwgeohidracom', $legacy->fresh()->website);

        // … y se normaliza.
        Livewire::actingAs($user)->test(EditEmpresa::class, ['record' => $legacy->getRouteKey()])
            ->set('data.website', 'www.geohidra.com')
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('https://www.geohidra.com', $legacy->fresh()->website);
    }
}
