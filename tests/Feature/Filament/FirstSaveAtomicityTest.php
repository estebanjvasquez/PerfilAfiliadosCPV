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
            $this->manager($manager, $empresa, $user)->actWith($action, data: $data)->assertHasNoTableActionErrors();

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
            // EmpresaCompletionObserver (saved) recomputes the cached percentage. Before the fix, the
            // exception in created() meant saved() never ran, so the cache went stale.
            $cached = (int) DB::connection()->table('empresas')->where('id', $empresa->id)->value('completion_percentage');
            $this->assertSame(Empresa::query()->withCompletionData()->findOrFail($empresa->id)->completionPercentage(), $cached, "{$name}: cached completion percentage refreshed");

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

    // -----------------------------------------------------------------------------------------------
    // `6214463281`: prueba del camino de FALLA. Un guardado exitoso no prueba el rollback, así que aquí se
    // inyecta una excepción DESPUÉS del INSERT, en un paso identificado, con los eventos reales activos y
    // sin transacción externa del test. La inyección es solo del test: un listener temporal o un doble
    // del observer en el contenedor. El código de producción no se toca ni se saltan eventos.
    // -----------------------------------------------------------------------------------------------

    private const MODEL_OF = [
        'management' => \App\Models\Management::class,
        'assets' => \App\Models\Asset::class,
        'experiences' => \App\Models\Experience::class,
        'presences' => \App\Models\Presence::class,
        'sustainabilities' => \App\Models\Sustainability::class,
    ];

    /** Valor centinela del porcentaje cacheado: si el refresco de adentro se confirmara, dejaría de verse. */
    private const SENTINEL = 1;

    /** Activo mientras se quiere la falla; el reintento lo apaga. */
    private bool $armed = false;

    /** Lo que se midió dentro del paso donde se inyecta la falla (conexión, nivel de transacción, estado). */
    private array $probe = [];

    /**
     * Cinco caminos de primer guardado × dos pasos posteriores al INSERT:
     *  - `created`: después del hook `created` del modelo, que ya borró la marca «No aplica» del módulo;
     *  - `completion`: después de que EmpresaCompletionObserver::saved() escribió empresas.completion_percentage.
     */
    public static function failureCases(): array
    {
        $cases = [];
        foreach (array_keys(self::modules()) as $name) {
            foreach (['created', 'completion'] as $step) {
                $cases["{$name} @ {$step}"] = [$name, $step];
            }
        }

        return $cases;
    }

    private function inject(string $modelClass, string $step, array $events = ['saved']): void
    {
        new $modelClass; // el modelo ya está booteado: su `created` corre antes que este listener
        $this->armed = true;
        $this->probe = [];
        $test = $this;

        if ($step === 'created') {
            \Illuminate\Support\Facades\Event::listen("eloquent.created: {$modelClass}", function ($model) use ($test) {
                $test->failHere($model, 'created');
            });

            return;
        }

        // Doble del observer real: hace su trabajo (el refresco del porcentaje) y recién después falla.
        $this->app->instance(\App\Observers\EmpresaCompletionObserver::class, new class($test, $modelClass, $events) extends \App\Observers\EmpresaCompletionObserver
        {
            public function __construct(private $test, private string $target, private array $events) {}

            public function saved(\Illuminate\Database\Eloquent\Model $model): void
            {
                parent::saved($model);
                if ($model instanceof $this->target && in_array('saved', $this->events, true)) {
                    $this->test->failHere($model, 'completion');
                }
            }

            public function deleted(\Illuminate\Database\Eloquent\Model $model): void
            {
                parent::deleted($model);
                if ($model instanceof $this->target && in_array('deleted', $this->events, true)) {
                    $this->test->failHere($model, 'completion');
                }
            }
        });
    }

    /** @internal lo llama la inyección: mide adentro y lanza. */
    public function failHere(\Illuminate\Database\Eloquent\Model $model, string $step): void
    {
        if (! $this->armed) {
            return;
        }
        $this->armed = false;
        $conn = $model->getConnection();
        $this->probe = [
            'step' => $step,
            'model_connection' => $conn->getName(),
            'default_connection' => DB::connection()->getName(),
            'empresa_connection' => (new Empresa)->getConnection()->getName(),
            'status_connection' => (new EmpresaModuleStatus)->getConnection()->getName(),
            'transaction_level' => $conn->transactionLevel(),
            'rows_inside' => $conn->table($model->getTable())->where('empresa_id', $model->empresa_id)->count(),
            'completion_inside' => (int) $conn->table('empresas')->where('id', $model->empresa_id)->value('completion_percentage'),
        ];

        throw new \RuntimeException("injected failure after {$step}");
    }

    /** Foto de todo lo que una empresa tiene en los cinco módulos, su marca y su porcentaje cacheado. */
    private function snapshot(Empresa $empresa): array
    {
        $db = DB::connection();
        $out = [];
        foreach (array_keys(self::MODEL_OF) as $table) {
            $out[$table] = $db->table($table)->where('empresa_id', $empresa->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }
        $out['status'] = $db->table('empresa_module_status')->where('empresa_id', $empresa->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $out['completion'] = $db->table('empresas')->where('id', $empresa->id)->value('completion_percentage');

        return $out;
    }

    /** Empresa con la marca «No aplica» del módulo y el porcentaje cacheado en el centinela. */
    private function preparedCompany(string $module): array
    {
        [$empresa, $user] = $this->freshCompany();
        EmpresaModuleStatus::setStatus($empresa->id, $module, true);
        DB::connection()->table('empresas')->where('id', $empresa->id)->update(['completion_percentage' => self::SENTINEL]);

        return [$empresa, $user];
    }

    private function report(string $case, string $result): void
    {
        fwrite(STDERR, sprintf("ATOMICITY %s | %s | %s\n", $case, json_encode($this->probe), $result));
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('failureCases')]
    public function a_failure_after_the_insert_rolls_back_the_whole_first_save_and_a_retry_succeeds(string $name, string $step): void
    {
        [$table, $manager, $action, $data, $module] = self::modules()[$name];
        if (($data['areas_id'] ?? null) === 'area1') {
            $data['areas_id'] = $this->catalog['area1'];
        }
        $modelClass = self::MODEL_OF[$name];

        // Otra empresa, con su propia fila y su marca: no debe cambiar.
        [$other, $otherUser] = $this->preparedCompany($module);
        $this->assertNull($this->firstSave($manager, $action, $data, $other, $otherUser), "{$name}: setup of the other company");
        EmpresaModuleStatus::setStatus($other->id, $module, true);
        $otherBefore = $this->snapshot($other);

        [$empresa, $user] = $this->preparedCompany($module);
        $before = $this->snapshot($empresa);
        $this->assertSame([], $before[$table], "{$name}: the company starts with no row");
        $this->assertTrue(EmpresaModuleStatus::isNoAplica($empresa->id, $module));

        $this->inject($modelClass, $step);
        $error = $this->firstSave($manager, $action, $data, $empresa, $user);

        $this->assertInstanceOf(\RuntimeException::class, $error, "{$name}: the injected failure surfaced");
        $this->assertSame("injected failure after {$step}", $error->getMessage());
        // Medido adentro: el INSERT ya estaba hecho, en la conexión de los modelos, dentro de una transacción.
        $this->assertSame(1, $this->probe['rows_inside'], "{$name}: the INSERT ran before the failure");
        $this->assertSame($this->probe['default_connection'], $this->probe['model_connection'], "{$name}: model on the default connection");
        $this->assertSame($this->probe['model_connection'], $this->probe['empresa_connection'], "{$name}: completion written on the same connection");
        $this->assertSame($this->probe['model_connection'], $this->probe['status_connection'], "{$name}: status written on the same connection");
        $this->assertGreaterThanOrEqual(1, $this->probe['transaction_level'], "{$name}: the post-INSERT step runs inside an application transaction");
        if ($step === 'completion') {
            $this->assertNotSame(self::SENTINEL, $this->probe['completion_inside'], "{$name}: the completion refresh had been written inside");
        }

        // Después del rollback: nada confirmado, la marca y el porcentaje como antes, la otra empresa intacta.
        $this->assertSame($before, $this->snapshot($empresa), "{$name} @ {$step}: nothing committed");
        $this->assertTrue(EmpresaModuleStatus::isNoAplica($empresa->id, $module), "{$name}: pre-existing «No aplica» kept");
        $this->assertSame(self::SENTINEL, (int) $this->snapshot($empresa)['completion'], "{$name}: cached completion unchanged");
        $this->assertSame($otherBefore, $this->snapshot($other), "{$name}: other company unchanged");

        // Sin la falla, el reintento guarda bien.
        $this->armed = false;
        $this->assertNull($this->firstSave($manager, $action, $data, $empresa, $user), "{$name}: retry after the failure");
        $rows = $this->rows($table, $empresa);
        $this->assertCount(1, $rows, "{$name}: one row after the retry");
        $this->assertSame($user->id, (int) $rows[0]->user_id, "{$name}: actor on the retried row");
        $this->assertFalse(EmpresaModuleStatus::isNoAplica($empresa->id, $module), "{$name}: «No aplica» cleared by the retry");
        $cached = (int) DB::connection()->table('empresas')->where('id', $empresa->id)->value('completion_percentage');
        $this->assertSame(Empresa::query()->withCompletionData()->findOrFail($empresa->id)->completionPercentage(), $cached, "{$name}: cached completion refreshed by the retry");
        $this->assertSame($otherBefore, $this->snapshot($other), "{$name}: other company still unchanged");

        $this->report("{$name} @ {$step}", 'rolled back; retry OK');
    }

    /**
     * Caminos de actualización y borrado que escriben la fila y DESPUÉS el porcentaje cacheado.
     * (Gestión, Recursos y la edición de Experiencia pasan por los helpers ya cubiertos arriba.)
     */
    public static function updateFailureCases(): array
    {
        return [
            'presences edit' => ['presences', 'edit', ['saved']],
            'presences delete' => ['presences', 'delete', ['deleted']],
            'sustainabilities delete' => ['sustainabilities', 'delete', ['deleted']],
            'experiences eliminar' => ['experiences', 'eliminar', ['saved']],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('updateFailureCases')]
    public function a_failure_after_an_update_or_delete_rolls_it_back(string $name, string $action, array $events): void
    {
        [$table, $manager, $createAction, $data, $module] = self::modules()[$name];
        if (($data['areas_id'] ?? null) === 'area1') {
            $data['areas_id'] = $this->catalog['area1'];
        }
        [$empresa, $user] = $this->freshCompany();
        $this->assertNull($this->firstSave($manager, $createAction, $data, $empresa, $user), "{$name}: setup");
        DB::connection()->table('empresas')->where('id', $empresa->id)->update(['completion_percentage' => self::SENTINEL]);
        $before = $this->snapshot($empresa);

        $rm = $this->manager($manager, $empresa, $user);
        $record = $rm->instance()->getTableRecords()->first();
        $this->assertNotNull($record, "{$name}: a table row to act on");
        $payload = $action === 'edit' ? ['has_offices' => false, 'has_experience' => true, 'experience_data' => ['b' => ['expcountry_id' => $this->catalog['country'], 'projects_q' => 5, 'role' => '3', 'executed_q' => '4']]] : [];

        $this->inject(self::MODEL_OF[$name], 'completion', $events);
        try {
            $this->withoutExceptionHandling();
            $rm->actWith($action, $record, data: $payload);
            $error = null;
        } catch (Throwable $e) {
            $error = $e;
        }

        $this->assertInstanceOf(\RuntimeException::class, $error, "{$name}: the injected failure surfaced");
        $this->assertGreaterThanOrEqual(1, $this->probe['transaction_level'], "{$name}: inside an application transaction");
        $this->assertSame($this->probe['model_connection'], $this->probe['empresa_connection'], "{$name}: same connection");
        $this->assertSame($before, $this->snapshot($empresa), "{$name}: row and cached completion unchanged after the failure");

        $this->report($name, 'rolled back');
    }
}
