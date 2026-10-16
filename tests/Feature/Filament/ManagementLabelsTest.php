<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\EmpresaResource\Pages\EditEmpresa;
use App\Filament\Resources\EmpresaResource\RelationManagers\ManagementRelationManager;
use App\Models\Empresa;
use App\Models\User;
use App\Support\UserManual\UserManualRenderer;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CPV-MODEL-COMPATIBILITY-AND-LABELS (Issue #2 `6211997650`): la casilla `ovid` decía «COVID.», y las de
 * `iso9001`/`iso17025` no tenían etiqueta propia (Filament mostraba «Iso9001»). Los nombres de los campos
 * y los valores guardados no cambian.
 */
class ManagementLabelsTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @return array{0: Empresa, 1: User} */
    private function empresaConUsuario(): array
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $empresa = Empresa::create([
            'rif' => 'J'.random_int(10000000, 99999999).'-'.random_int(0, 9),
            'name' => 'zzz_test_gestion_'.uniqid('', true),
            'phone' => '0212-5551234',
            'website' => 'https://ejemplo.test',
        ]);

        return [$empresa, $user];
    }

    private function manager(Empresa $empresa, User $user)
    {
        return Livewire::actingAs($user)->test(ManagementRelationManager::class, [
            'ownerRecord' => $empresa,
            'pageClass' => EditEmpresa::class,
        ]);
    }

    #[Test]
    public function the_security_form_labels_ovid_as_ovid_and_saves_the_same_field(): void
    {
        [$empresa, $user] = $this->empresaConUsuario();

        $this->manager($empresa, $user)
            ->mountTableAction('seguridad')
            ->assertSee('OVID')
            ->assertDontSee('COVID')
            ->setTableActionData(['no_aplica' => false, 'iso45001' => false, 'ovid' => true, 'security_otros' => false])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $management = $empresa->management()->first();
        $this->assertNotNull($management);
        $this->assertTrue((bool) $management->ovid, 'El valor se guarda en el mismo campo `ovid`.');
        $this->assertFalse((bool) $management->iso45001);
    }

    #[Test]
    public function the_quality_form_labels_iso_9001_and_iso_17025_and_saves_them(): void
    {
        [$empresa, $user] = $this->empresaConUsuario();

        $this->manager($empresa, $user)
            ->mountTableAction('calidad')
            ->assertSee('ISO 9001')
            ->assertSee('ISO 17025')
            ->assertDontSee('Iso9001')
            ->assertDontSee('Iso17025')
            ->setTableActionData(['no_aplica' => false, 'iso9001' => true, 'iso17025' => false, 'quality_otros' => false])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $management = $empresa->management()->first();
        $this->assertTrue((bool) $management->iso9001);
        $this->assertFalse((bool) $management->iso17025);
    }

    #[Test]
    public function the_summary_column_and_the_other_outputs_say_ovid(): void
    {
        [$empresa, $user] = $this->empresaConUsuario();
        $empresa->management()->create(['ovid' => true]);

        $this->manager($empresa, $user)->assertSee('OVID')->assertDontSee('COVID');

        // Mismo campo en el PDF del perfil, la vista de administración y el export: sin «COVID».
        foreach (['resources/views/pdf.blade.php', 'resources/views/filament/pages/management-det-view.blade.php', 'app/Exports/ManagementDetExport.php'] as $file) {
            $this->assertStringNotContainsString('COVID', (string) file_get_contents(base_path($file)), $file);
            $this->assertStringContainsString('OVID', (string) file_get_contents(base_path($file)), $file);
        }
    }

    #[Test]
    public function the_manual_lists_ovid_under_security_and_keeps_its_anchor(): void
    {
        $html = app(UserManualRenderer::class)->render()['html'];
        $start = strpos($html, '<h2 id="sistemas-de-gestion">');
        $this->assertNotFalse($start);
        $section = substr($html, $start, (strpos($html, '<h2 ', $start + 1) ?: strlen($html)) - $start);

        $this->assertStringContainsString('ISO 45001 u OVID', $section);
        $this->assertStringContainsString('ISO 9001 o ISO 17025', $section);
        $this->assertStringNotContainsString('COVID', $html);
    }
}
