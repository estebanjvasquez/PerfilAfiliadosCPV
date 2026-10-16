<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\EmpresaResource\RelationManagers\AssetsRelationManager;
use App\Filament\Resources\EmpresaResource\RelationManagers\ExperiencesRelationManager;
use App\Filament\Resources\EmpresaResource\RelationManagers\ManagementRelationManager;
use App\Filament\Resources\EmpresaResource\RelationManagers\PresenceRelationManager;
use App\Filament\Resources\EmpresaResource\RelationManagers\SustainabilitiesRelationManager;
use App\Models\Empresa;
use App\Models\EmpresaModuleStatus;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Filament\Concerns\ProfileModuleFixtures;
use Tests\TestCase;
use Throwable;

/**
 * CPV-FIRST-SAVE-POSTGRES-COMPATIBILITY (Issue #2 `6212967641`): atomicidad del primer guardado.
 *
 * SIN DatabaseTransactions a propósito. La aplicación no envuelve estas acciones en una transacción: las
 * acciones de tabla de Filament no la usan, y el panel no la activa. Así que el INSERT queda
 * confirmado aunque falle un statement posterior. Envolver el test en una transacción escondería
 * justamente eso. Los datos son sintéticos y se borran en tearDown(); la base es la desechable del
 * entorno aislado.
 *
 * Antes de la reparación, el hook `created` hacía un `update <tabla> set <tabla>.user_id` que PostgreSQL
 * rechaza. Este test documenta, por módulo, si el primer guardado falla y si deja una fila parcial.
 */
class FirstSaveAtomicityTest extends TestCase
{
    use ProfileModuleFixtures;

    /** @var list<int> */
    private array $empresas = [];

    /** @var list<int> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->seedCatalogs();
    }

    protected function tearDown(): void
    {
        $db = DB::connection();
        foreach (['management', 'assets', 'experiences', 'presences', 'sustainabilities', 'empresa_module_status', 'empresa_user'] as $table) {
            $db->table($table)->whereIn('empresa_id', $this->empresas)->delete();
        }
        $db->table('empresas')->whereIn('id', $this->empresas)->delete();
        $db->table('model_has_permissions')->where('model_type', \App\Models\User::class)->whereIn('model_id', $this->users)->delete();
        $db->table('users')->whereIn('id', $this->users)->delete();
        $db->table('areas')->whereIn('id', [$this->catalog['area1'], $this->catalog['area2']])->delete();
        $db->table('cities')->where('id', $this->catalog['city'])->delete();
        $db->table('states')->where('id', $this->catalog['state'])->delete();
        $db->table('countries')->where('id', $this->catalog['country'])->delete();
        $db->table('sectors')->where('id', $this->catalog['sector'])->delete();
        $db->table('infrasectors')->where('id', $this->catalog['infrasector'])->delete();

        parent::tearDown();
    }

    private function freshCompany(): array
    {
        $user = $this->affiliate();
        $this->users[] = $user->id;
        $empresa = $this->companyOf($user);
        $this->empresas[] = $empresa->id;

        return [$empresa, $user];
    }

    /** Ejecuta el primer guardado real y devuelve la excepción, si la hubo (sin tragarla: se informa). */
    private function firstSave(string $manager, string $action, array $data, Empresa $empresa, $user): ?Throwable
    {
        try {
            $this->withoutExceptionHandling();
            $this->manager($manager, $empresa, $user)->callTableAction($action, data: $data)->assertHasNoTableActionErrors();

            return null;
        } catch (Throwable $e) {
            return $e;
        }
    }

    public static function modules(): array
    {
        return [
            'management' => ['management', ManagementRelationManager::class, 'calidad', ['no_aplica' => false, 'iso9001' => true, 'quality_otros' => false], EmpresaModuleStatus::MODULE_GESTION],
            'assets' => ['assets', AssetsRelationManager::class, 'recursos_humanos', ['no_aplica' => false, 'employee' => ['a' => ['employee_type' => '1', 'junior_q' => 1, 'medium_q' => 0, 'senior_q' => 0]]], EmpresaModuleStatus::MODULE_RECURSOS],
            'experiences' => ['experiences', ExperiencesRelationManager::class, 'agregar_experiencia', ['exp_year' => '2019', 'Descripcion' => 'ZZZ atomicidad'], EmpresaModuleStatus::MODULE_EXPERIENCIAS],
            'presences' => ['presences', PresenceRelationManager::class, 'create', ['has_offices' => false, 'has_experience' => false], EmpresaModuleStatus::MODULE_PRESENCIA],
            'sustainabilities' => ['sustainabilities', SustainabilitiesRelationManager::class, 'create', ['areas_id' => 'area1', 'sust_status' => '2'], EmpresaModuleStatus::MODULE_SOSTENIBILIDAD],
        ];
    }

    #[Test]
    public function each_first_save_commits_exactly_one_attributed_row_and_a_retry_does_not_duplicate(): void
    {
        $failures = [];
        foreach (self::modules() as $name => [$table, $manager, $action, $data, $module]) {
            [$empresa, $user] = $this->freshCompany();
            if (($data['areas_id'] ?? null) === 'area1') {
                $data['areas_id'] = $this->catalog['area1'];
            }
            // «No aplica» del módulo marcado antes: el primer guardado real debe quitarlo, como siempre.
            EmpresaModuleStatus::setStatus($empresa->id, $module, true);

            $error = $this->firstSave($manager, $action, $data, $empresa, $user);
            $rows = $this->rows($table, $empresa);
            if ($error) {
                $failures[] = sprintf('%s: first save threw %s: %s | rows left committed=%d user_id=%s | module no_aplica still set=%s',
                    $name, get_class($error), strtok($error->getMessage(), "\n"), $rows->count(),
                    json_encode($rows->pluck('user_id')->all()), EmpresaModuleStatus::isNoAplica($empresa->id, $module) ? 'yes' : 'no');

                continue;
            }

            $this->assertCount(1, $rows, "{$name}: exactly one committed row");
            $this->assertSame($user->id, (int) $rows[0]->user_id, "{$name}: actor on the created row");
            $this->assertFalse(EmpresaModuleStatus::isNoAplica($empresa->id, $module), "{$name}: module «No aplica» cleared by the first save");

            // Reintento (doble click o segundo intento). Los módulos de una fila no duplican; en
            // Sostenibilidad, un segundo «Crear» es un enfoque más, que es legítimo.
            if ($name !== 'presences') {
                $this->assertNull($this->firstSave($manager, $action, $data, $empresa, $user), "{$name}: retry");
            }
            $expected = $name === 'sustainabilities' ? 2 : 1;
            $this->assertCount($expected, $this->rows($table, $empresa), "{$name}: rows after retry");
        }

        $this->assertSame([], $failures, "First-save failures on PostgreSQL:\n".implode("\n", $failures));
    }
}
