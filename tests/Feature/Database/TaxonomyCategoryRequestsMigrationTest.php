<?php

namespace Tests\Feature\Database;

use App\Models\TaxonomySelectionSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-0010A, corrección de la revisión `6171023100`: ejecutar up/down/up en la base DESECHABLE y
 * comprobar que los objetos nuevos se crean y se revierten, y que el esquema y las filas
 * preexistentes que no son de esta tarea sobreviven.
 *
 * La entrega anterior declaraba como su límite más relevante que el `down()` no estaba cubierto por
 * ningún test. Esto lo cubre.
 *
 * DÓNDE CORRE. Sólo contra el PostgreSQL desechable del entorno aislado, nunca contra la base
 * compartida: el propio test se niega a correr si la base no se llama `cpv_test`. En PostgreSQL el
 * DDL es transaccional, así que todo esto vive dentro de la transacción de `DatabaseTransactions` y
 * el rollback final lo deshace por completo, incluso si una aserción falla a mitad.
 *
 * NO es un `migrate:rollback` contra datos vivos, que es exactamente lo que la revisión prohíbe.
 */
class TaxonomyCategoryRequestsMigrationTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private const MIGRATION = '2026_10_09_120000_create_taxonomy_category_requests_table.php';

    /** Columnas del singleton que NO son de esta tarea y tienen que sobrevivir a up/down/up. */
    private const COLUMNAS_AJENAS = [
        'id',
        'max_categorias_principales',
        'max_categorias_secundarias',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $base = DB::connection('pgsql')->getDatabaseName();
        if ($base !== 'cpv_test') {
            $this->markTestSkipped("Este test aplica y revierte DDL: sólo corre contra la base desechable 'cpv_test', no contra '{$base}'.");
        }
    }

    private function migration(): object
    {
        return require database_path('migrations/'.self::MIGRATION);
    }

    /**
     * TASK-0010C agregó una migración POSTERIOR cuya tabla de historial tiene una FK a
     * `taxonomy_category_requests`. Un rollback real va en orden inverso: primero se revierte esa y
     * después esta. Los ciclos de este test hacen lo mismo, y al final la vuelven a aplicar, para
     * que el esquema termine igual que como lo deja `migrate`.
     */
    private const MIGRATION_POSTERIOR = '2026_10_15_120000_add_delivery_operations_to_taxonomy_category_requests.php';

    private function revertirPosterior(): void
    {
        if (Schema::connection('pgsql')->hasTable('taxonomy_category_request_delivery_attempts')) {
            (require database_path('migrations/'.self::MIGRATION_POSTERIOR))->down();
        }
    }

    private function reaplicarPosterior(): void
    {
        (require database_path('migrations/'.self::MIGRATION_POSTERIOR))->up();
    }

    private function tablaExiste(): bool
    {
        return Schema::connection('pgsql')->hasTable('taxonomy_category_requests');
    }

    private function columnaExiste(): bool
    {
        return Schema::connection('pgsql')->hasColumn('taxonomy_selection_settings', 'category_request_recipient_email');
    }

    #[Test]
    public function up_down_up_creates_reverts_and_recreates_only_this_tasks_objects(): void
    {
        $migration = $this->migration();

        // Punto de partida: en el entorno aislado `migrate` ya aplicó esta migración de verdad.
        $this->assertTrue($this->tablaExiste(), 'La migración debería estar aplicada por migrate en el entorno aislado.');
        $this->assertTrue($this->columnaExiste());

        // Testigos que NO son de esta tarea y tienen que seguir ahí al final. El singleton es una
        // fila real preexistente; la segunda tabla es un vecino arbitrario del esquema.
        $ajustes = TaxonomySelectionSettings::current();
        $ajustes->update(['max_categorias_principales' => 7]);
        $settingsId = $ajustes->getKey();
        $empresasAntes = DB::connection('pgsql')->table('empresas')->count();
        $categoriasAntes = DB::connection('pgsql')->table('taxonomy_categories')->count();
        $this->assertGreaterThan(0, $empresasAntes, 'El fixture sintético debería haber cargado empresas.');

        // --- DOWN: los dos objetos de esta tarea desaparecen (tras revertir la posterior) ---
        $this->revertirPosterior();
        $migration->down();

        $this->assertFalse($this->tablaExiste(), 'down() tiene que soltar la tabla.');
        $this->assertFalse($this->columnaExiste(), 'down() tiene que soltar la columna del singleton.');

        // ...y NADA ajeno se cae con ellos.
        $this->assertTrue(Schema::connection('pgsql')->hasTable('taxonomy_selection_settings'),
            'down() no debe tocar la tabla del singleton, sólo su columna nueva.');
        foreach (self::COLUMNAS_AJENAS as $columna) {
            $this->assertTrue(Schema::connection('pgsql')->hasColumn('taxonomy_selection_settings', $columna),
                "down() borró una columna ajena a esta tarea: {$columna}");
        }
        $this->assertSame(7, (int) DB::connection('pgsql')->table('taxonomy_selection_settings')
            ->where('id', $settingsId)->value('max_categorias_principales'),
            'La fila preexistente del singleton tiene que conservar sus valores.');
        $this->assertSame($empresasAntes, DB::connection('pgsql')->table('empresas')->count());
        $this->assertSame($categoriasAntes, DB::connection('pgsql')->table('taxonomy_categories')->count());

        // --- UP otra vez: se reconstruye todo ---
        $migration->up();
        $this->reaplicarPosterior();

        $this->assertTrue($this->tablaExiste());
        $this->assertTrue($this->columnaExiste());

        // Y la tabla recreada sirve: acepta una fila con sus FK e integridad intactas.
        $empresaId = DB::connection('pgsql')->table('empresas')->orderBy('id')->value('id');
        $id = DB::connection('pgsql')->table('taxonomy_category_requests')->insertGetId([
            'empresa_id' => $empresaId,
            'user_id' => null,
            'necesidad' => 'zzz prueba de reconstrucción',
            'justificacion' => 'NO_ESTOY_SEGURO',
            'detalle' => 'Comprobación de que la tabla recreada acepta una fila válida.',
            'context_snapshot' => '{}',
            'request_status' => 'pending_review',
            'delivery_status' => 'pending',
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertNotNull($id);

        $this->assertSame(7, (int) DB::connection('pgsql')->table('taxonomy_selection_settings')
            ->where('id', $settingsId)->value('max_categorias_principales'));
    }

    /**
     * `up()` es idempotente (`IF NOT EXISTS` / `ADD COLUMN IF NOT EXISTS`), así que aplicarla sobre
     * una base donde ya está no puede fallar ni duplicar nada. Importa porque los tests de la tarea
     * la aplican condicionalmente en su propio `setUp()`.
     */
    #[Test]
    public function up_is_idempotent_and_creates_no_duplicates(): void
    {
        $migration = $this->migration();

        $indicesAntes = $this->indices();
        $columnasAntes = count(Schema::connection('pgsql')->getColumnListing('taxonomy_selection_settings'));

        $migration->up();
        $migration->up();

        $this->assertTrue($this->tablaExiste());
        $this->assertTrue($this->columnaExiste());
        $this->assertSame($indicesAntes, $this->indices(), 'Un up() repetido no debe duplicar índices.');
        $this->assertSame($columnasAntes, count(Schema::connection('pgsql')->getColumnListing('taxonomy_selection_settings')),
            'Un up() repetido no debe agregar la columna dos veces.');
    }

    /**
     * Las dos restricciones CHECK son parte de la integridad que el contrato pide preservar: la
     * lista de justificaciones y el estado de entrega quedan cerrados EN LA BASE, no sólo en la
     * validación de PHP. Si `down()`/`up()` las perdiera, la tabla aceptaría basura en silencio.
     */
    #[Test]
    public function the_check_constraints_survive_a_down_and_up_cycle(): void
    {
        $migration = $this->migration();
        $this->revertirPosterior();
        $migration->down();
        $migration->up();
        $this->reaplicarPosterior();

        $empresaId = DB::connection('pgsql')->table('empresas')->orderBy('id')->value('id');
        $fila = [
            'empresa_id' => $empresaId,
            'user_id' => null,
            'necesidad' => 'zzz prueba de restricciones',
            'justificacion' => 'NO_ESTOY_SEGURO',
            'detalle' => 'Comprobación de que los CHECK siguen vivos tras reconstruir la tabla.',
            'context_snapshot' => '{}',
            'request_status' => 'pending_review',
            'delivery_status' => 'pending',
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $this->assertRechaza('23514', ['justificacion' => 'NO_EXISTE_ESTE_MOTIVO'] + $fila, 'el CHECK de justificacion');
        $this->assertRechaza('23514', ['delivery_status' => 'quien_sabe'] + $fila, 'el CHECK de delivery_status');
        $this->assertRechaza('23503', ['empresa_id' => 0] + $fila, 'la clave foránea a empresas');
    }

    /**
     * Comprueba que la base RECHAZA una fila con un SQLSTATE concreto.
     *
     * CADA inserción va en su propio SAVEPOINT. En PostgreSQL el primer error aborta la
     * transacción entera y todo lo que sigue falla con `25P02` (`in_failed_sql_transaction`), así
     * que sin esto sólo la primera comprobación mide algo y las demás miden el daño colateral de
     * la primera - que es exactamente lo que pasó al escribir este test.
     *
     * `DB::transaction()` anidado dentro de la transacción de `DatabaseTransactions` emite un
     * SAVEPOINT y vuelve a él al propagarse la excepción, dejando la transacción sana.
     *
     * @param  array<string, mixed>  $fila
     */
    private function assertRechaza(string $sqlstate, array $fila, string $queGarantia): void
    {
        $capturada = null;

        try {
            DB::connection('pgsql')->transaction(function () use ($fila) {
                DB::connection('pgsql')->table('taxonomy_category_requests')->insert($fila);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            $capturada = $e;
        }

        $this->assertNotNull($capturada, "No está activa {$queGarantia}: la base aceptó una fila inválida.");
        $this->assertSame($sqlstate, (string) $capturada->getCode(),
            "Se esperaba SQLSTATE {$sqlstate} por {$queGarantia}, llegó {$capturada->getCode()}.");
    }

    /** @return array<int, string> */
    private function indices(): array
    {
        if (! $this->tablaExiste()) {
            return [];
        }

        return DB::connection('pgsql')
            ->table('pg_indexes')
            ->where('tablename', 'taxonomy_category_requests')
            ->orderBy('indexname')
            ->pluck('indexname')
            ->all();
    }
}
