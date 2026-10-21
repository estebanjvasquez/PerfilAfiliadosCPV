<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\EmpresaResource;
use App\Filament\Resources\EmpresaResource\RelationManagers\AssetsRelationManager;
use App\Filament\Resources\EmpresaResource\RelationManagers\ExperiencesRelationManager;
use App\Filament\Resources\EmpresaResource\RelationManagers\ManagementRelationManager;
use App\Filament\Resources\EmpresaResource\RelationManagers\PresenceRelationManager;
use App\Filament\Resources\EmpresaResource\RelationManagers\SustainabilitiesRelationManager;
use App\Models\Empresa;
use App\Models\EmpresaModuleStatus;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Filament\Concerns\ProfileModuleFixtures;
use Tests\TestCase;

/**
 * CPV-VERIFICATION-FLOW-AND-COMPLETION (Issue #2 `6215451160`), parte B: el porcentaje cacheado
 * `empresas.completion_percentage` sigue a «No Aplica» cuando se activa y cuando se desactiva.
 *
 * Antes, al desactivar, `EmpresaModuleStatus::setStatus(false)` borraba por el query builder. Eso no
 * dispara eventos de modelo, así que EmpresaCompletionObserver no recalculaba y el cache quedaba viejo
 * hasta la reconciliación programada (cada 15 minutos).
 *
 * Todo pasa por las acciones reales del panel, con eventos reales. El valor esperado es SIEMPRE una
 * computación fresca de la fórmula existente (`Empresa::completionPercentage()`); no se duplica la fórmula.
 */
class NoAplicaCompletionTest extends TestCase
{
    use DatabaseTransactions;
    use ProfileModuleFixtures;

    protected $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->seedCatalogs();
    }

    public static function modules(): array
    {
        return [
            'gestion' => [ManagementRelationManager::class, EmpresaModuleStatus::MODULE_GESTION],
            'recursos' => [AssetsRelationManager::class, EmpresaModuleStatus::MODULE_RECURSOS],
            'experiencias' => [ExperiencesRelationManager::class, EmpresaModuleStatus::MODULE_EXPERIENCIAS],
            'presencia' => [PresenceRelationManager::class, EmpresaModuleStatus::MODULE_PRESENCIA],
            'sostenibilidad' => [SustainabilitiesRelationManager::class, EmpresaModuleStatus::MODULE_SOSTENIBILIDAD],
        ];
    }

    private function cached(Empresa $empresa): int
    {
        return (int) DB::connection()->table('empresas')->where('id', $empresa->id)->value('completion_percentage');
    }

    private function fresh(Empresa $empresa): int
    {
        return Empresa::query()->withCompletionData()->findOrFail($empresa->id)->completionPercentage();
    }

    private function updatedAt(Empresa $empresa): ?string
    {
        return DB::connection()->table('empresas')->where('id', $empresa->id)->value('updated_at');
    }

    private function toggle(string $manager, Empresa $empresa, $user, bool $on): void
    {
        $this->manager($manager, $empresa, $user)->actWith('no_aplica', data: ['no_aplica' => $on])->assertHasNoTableActionErrors();
    }

    private function profileRows(Empresa $empresa): array
    {
        $out = [];
        foreach (['management', 'assets', 'experiences', 'presences', 'sustainabilities'] as $t) {
            $out[$t] = DB::connection()->table($t)->where('empresa_id', $empresa->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return $out;
    }

    #[Test]
    #[DataProvider('modules')]
    public function whole_module_toggle_on_and_off_keeps_the_cache_equal_to_a_fresh_computation(string $manager, string $module): void
    {
        $user = $this->affiliate();
        $empresa = $this->companyOf($user);
        $other = $this->companyOf($this->affiliate());
        $this->actingAs($user);
        $otherBefore = [$this->cached($other), $this->updatedAt($other), EmpresaModuleStatus::flagsFor($other->id)];
        $updatedAt = $this->updatedAt($empresa);
        $start = $this->fresh($empresa);

        // Sin filas del módulo. Encendido, repetido; apagado, repetido.
        foreach ([true, true, false, false, true, false] as $i => $on) {
            $this->toggle($manager, $empresa, $user, $on);
            $this->assertSame($on, EmpresaModuleStatus::isNoAplica($empresa->id, $module), "paso {$i}: marca");
            $this->assertSame($this->fresh($empresa), $this->cached($empresa), "paso {$i} (".($on ? 'on' : 'off').'): cache = cálculo fresco');
        }
        $this->assertSame($start, $this->cached($empresa), 'Apagado al final: vuelve al valor de partida.');
        $this->assertSame(0, DB::connection()->table('empresa_module_status')->where('empresa_id', $empresa->id)->where('module', $module)->count(), 'Apagado: no queda ninguna fila de marca.');

        // El recálculo interno no se hace pasar por una edición del perfil.
        $this->assertSame($updatedAt, $this->updatedAt($empresa), 'empresas.updated_at intacto.');
        $this->assertSame($otherBefore, [$this->cached($other), $this->updatedAt($other), EmpresaModuleStatus::flagsFor($other->id)], 'La otra empresa no cambia.');
    }

    #[Test]
    public function toggling_keeps_existing_data_and_sibling_sections(): void
    {
        $user = $this->affiliate();
        $empresa = $this->companyOf($user);

        // Datos parciales reales: Gestión con Calidad cargada y Ambiente marcada No Aplica por sección;
        // Recursos con Recursos humanos; Sostenibilidad con un enfoque.
        $gestion = $this->manager(ManagementRelationManager::class, $empresa, $user);
        $gestion->actWith('calidad', data: ['no_aplica' => false, 'iso9001' => true, 'quality_otros' => false])->assertHasNoTableActionErrors();
        $this->manager(ManagementRelationManager::class, $empresa, $user)->actWith('ambiente', data: ['no_aplica' => true])->assertHasNoTableActionErrors();
        $this->manager(AssetsRelationManager::class, $empresa, $user)->actWith('recursos_humanos', data: ['no_aplica' => false, 'employee' => ['a' => ['employee_type' => '1', 'junior_q' => 2, 'medium_q' => 0, 'senior_q' => 0]]])->assertHasNoTableActionErrors();
        $this->manager(SustainabilitiesRelationManager::class, $empresa, $user)->actWith('create', data: ['areas_id' => $this->catalog['area1'], 'sust_status' => '2'])->assertHasNoTableActionErrors();
        $this->assertSame($this->fresh($empresa), $this->cached($empresa));
        $rows = $this->profileRows($empresa);
        $sections = EmpresaModuleStatus::subTypeFlagsFor($empresa->id, EmpresaModuleStatus::MODULE_GESTION);
        $this->assertTrue($sections['ambiente']);

        foreach ([[ManagementRelationManager::class, EmpresaModuleStatus::MODULE_GESTION], [AssetsRelationManager::class, EmpresaModuleStatus::MODULE_RECURSOS], [SustainabilitiesRelationManager::class, EmpresaModuleStatus::MODULE_SOSTENIBILIDAD]] as [$manager, $module]) {
            foreach ([true, false] as $on) {
                $this->toggle($manager, $empresa, $user, $on);
                $this->assertSame($this->fresh($empresa), $this->cached($empresa), "{$module} ".($on ? 'on' : 'off'));
                $this->assertSame($rows, $this->profileRows($empresa), "{$module}: marcar o desmarcar No Aplica no borra ni cambia datos");
            }
        }
        $this->assertSame($sections, EmpresaModuleStatus::subTypeFlagsFor($empresa->id, EmpresaModuleStatus::MODULE_GESTION), 'Las marcas por sección no cambian con el No Aplica del módulo.');
    }

    #[Test]
    public function subtype_toggles_on_and_off_keep_the_cache_fresh(): void
    {
        $user = $this->affiliate();
        $empresa = $this->companyOf($user);

        $cases = [
            [ManagementRelationManager::class, 'credibilidad', EmpresaModuleStatus::MODULE_GESTION, ['no_aplica' => true], ['no_aplica' => false, 'iso37001' => true, 'credibility_otros' => false]],
            [AssetsRelationManager::class, 'maquinaria', EmpresaModuleStatus::MODULE_RECURSOS, ['no_aplica' => true], ['no_aplica' => false, 'machinery' => []]],
        ];
        foreach ($cases as [$manager, $action, $module, $on, $off]) {
            $subType = ['credibilidad' => 'credibilidad', 'maquinaria' => 'machinery'][$action];
            $this->manager($manager, $empresa, $user)->actWith($action, data: $on)->assertHasNoTableActionErrors();
            $this->assertTrue(EmpresaModuleStatus::isNoAplica($empresa->id, $module, $subType));
            $this->assertSame($this->fresh($empresa), $this->cached($empresa), "{$action} on");
            $this->manager($manager, $empresa, $user)->actWith($action, data: $off)->assertHasNoTableActionErrors();
            $this->assertFalse(EmpresaModuleStatus::isNoAplica($empresa->id, $module, $subType));
            $this->assertSame($this->fresh($empresa), $this->cached($empresa), "{$action} off");
        }
    }

    #[Test]
    public function the_action_keeps_the_existing_authorization(): void
    {
        $owner = $this->affiliate();
        $empresa = $this->companyOf($owner);

        // Sin el permiso del módulo, el gestor de la pestaña no se monta.
        $noPerms = $this->affiliate(withPermissions: false);
        $empresa->users()->attach($noPerms->id);
        $this->withoutExceptionHandling();
        try {
            $this->manager(ManagementRelationManager::class, $empresa, $noPerms)->actWith('no_aplica', data: ['no_aplica' => true]);
            $this->fail('Sin permiso no debería poder usar la acción.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            // CPV-SEC-01-R3: the company access re-check (F-SEC01-R3-01) now refuses first, with the same 404 as for a
            // company that is not the user's; before it, Filament refused with 403. Either is the denial (R2-REV-03).
            $this->assertContains($e->getStatusCode(), [403, 404]);
        }
        $this->assertFalse(EmpresaModuleStatus::isNoAplica($empresa->id, EmpresaModuleStatus::MODULE_GESTION));

        // Otra empresa: su página de edición no se abre.
        $this->withExceptionHandling();
        $stranger = $this->affiliate();
        $this->companyOf($stranger);
        $this->actingAs($stranger)->get(EmpresaResource::getUrl('edit', ['record' => $empresa]))->assertNotFound();
    }
}
