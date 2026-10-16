<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\EmpresaResource;
use App\Filament\Resources\EmpresaResource\Pages\CreateEmpresa;
use App\Filament\Resources\EmpresaResource\RelationManagers\AssetsRelationManager;
use App\Filament\Resources\EmpresaResource\RelationManagers\ExperiencesRelationManager;
use App\Filament\Resources\EmpresaResource\RelationManagers\ManagementRelationManager;
use App\Filament\Resources\EmpresaResource\RelationManagers\PresenceRelationManager;
use App\Filament\Resources\EmpresaResource\RelationManagers\SustainabilitiesRelationManager;
use App\Models\Empresa;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Filament\Concerns\ProfileModuleFixtures;
use Tests\TestCase;

/**
 * CPV-FIRST-SAVE-POSTGRES-COMPATIBILITY (Issue #2 `6212967641`): integración acotada del alta de un
 * afiliado NUEVO.
 *
 * Supuestos declarados:
 * - **Usuario:** se crea como lo deja el registro (sin verificar); no se ejercita el formulario de
 *   registro, que tiene captcha.
 * - **Verificación de correo:** desde `6215451160`, por el ENLACE real del panel. El aviso se genera con
 *   User::sendEmailVerificationNotification() y se captura con Notification::fake(): no sale ningún
 *   correo. El correo de staging no está configurado. El registro con captcha está en
 *   EmailVerificationFlowTest.
 * - **Permisos:** los de las policies se otorgan como lo haría su rol en producción (ver
 *   ProfileModuleFixtures).
 *
 * Esto no valida la entrega real del correo de registro ni todas las funciones de la aplicación.
 */
class NewAffiliateOnboardingTest extends TestCase
{
    use DatabaseTransactions;
    use ProfileModuleFixtures;

    protected $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Mail::fake();
        $this->seedCatalogs();
    }

    #[Test]
    public function a_new_affiliate_verifies_by_the_link_creates_their_company_and_saves_each_module_for_the_first_time(): void
    {
        // Otra empresa, de otro usuario, que el afiliado nuevo no debe ver.
        $other = $this->affiliate();
        $otherCompany = $this->companyOf($other);
        auth()->logout();

        $user = User::create(['name' => 'ZZZ Afiliado Nuevo', 'email' => 'zzz-nuevo-'.uniqid().'@example.test', 'password' => Hash::make('zzz-secret-123')]);
        $user->givePermissionTo($this->permissions());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Sin verificar, el panel lleva a la página de verificación (antes, 500: `verification.notice`).
        $this->actingAs($user)->get(EmpresaResource::getUrl('index'))->assertRedirect(Filament::getPanel('admin')->getEmailVerificationPromptUrl());
        Notification::fake();
        $user->sendEmailVerificationNotification();
        $link = null;
        Notification::assertSentTo($user, \Filament\Notifications\Auth\VerifyEmail::class, function ($n) use (&$link) {
            $link = $n->url;

            return true;
        });
        $this->actingAs($user)->get($link)->assertRedirect(Filament::getPanel('admin')->getUrl());
        $this->assertNotNull($user->fresh()->email_verified_at);
        $user->refresh();
        $this->actingAs($user)->get(EmpresaResource::getUrl('index'))->assertOk();

        // Alta de su empresa por la página real: Empresa::created() lo vincula.
        $rif = 'J'.random_int(100000000, 999999999);
        Livewire::actingAs($user)->test(CreateEmpresa::class)
            ->fillForm(['rif' => $rif, 'name' => 'ZZZ EMPRESA NUEVA', 'city_id' => $this->catalog['city'], 'sector_principal_id' => $this->catalog['sector'], 'status_id' => '1'])
            ->call('create')
            ->assertHasNoFormErrors();
        $empresa = Empresa::where('rif', $rif)->firstOrFail();
        $this->assertTrue($empresa->users()->where('users.id', $user->id)->exists(), 'La empresa queda vinculada a quien la creó.');

        $this->withoutExceptionHandling();
        $this->actingAs($user)->get(EmpresaResource::getUrl('edit', ['record' => $empresa]))->assertOk();

        foreach (['management', 'assets', 'experiences', 'presences', 'sustainabilities'] as $table) {
            $this->assertCount(0, $this->rows($table, $empresa), "Precondición: sin fila en {$table}.");
        }

        $this->manager(ManagementRelationManager::class, $empresa, $user)->actWith('seguridad', data: ['no_aplica' => false, 'iso45001' => true, 'ovid' => true, 'security_otros' => false])->assertHasNoTableActionErrors();
        $this->manager(AssetsRelationManager::class, $empresa, $user)->actWith('instalaciones', data: ['no_aplica' => false, 'facility' => ['a' => ['facility_type' => '1', 'facility_q' => 1, 'facility_surf' => 80, 'facility_own' => '1']]])->assertHasNoTableActionErrors();
        $this->manager(ExperiencesRelationManager::class, $empresa, $user)->actWith('agregar_experiencia', data: ['exp_year' => '2024', 'Descripcion' => 'ZZZ primera experiencia'])->assertHasNoTableActionErrors();
        $this->manager(PresenceRelationManager::class, $empresa, $user)->actWith('create', data: ['has_offices' => true, 'office_data' => ['a' => ['country_id' => $this->catalog['country'], 'offices_surf' => '50', 'employees_q' => 2, 'status' => true]], 'has_experience' => false])->assertHasNoTableActionErrors();
        $this->manager(SustainabilitiesRelationManager::class, $empresa, $user)->actWith('create', data: ['areas_id' => $this->catalog['area2'], 'sust_status' => '1'])->assertHasNoTableActionErrors();

        foreach (['management', 'assets', 'experiences', 'presences', 'sustainabilities'] as $table) {
            $rows = $this->rows($table, $empresa);
            $this->assertCount(1, $rows, "{$table}: una fila tras el primer guardado.");
            $this->assertSame($user->id, (int) $rows[0]->user_id, "{$table}: el actor es el afiliado nuevo.");
        }

        $this->withExceptionHandling();
        $this->actingAs($user)->get(EmpresaResource::getUrl('edit', ['record' => $otherCompany]))->assertNotFound();
        Mail::assertNothingOutgoing();
    }
}
