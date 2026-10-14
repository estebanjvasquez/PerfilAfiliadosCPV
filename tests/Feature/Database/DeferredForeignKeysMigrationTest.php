<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Escenarios de regresión de `2022_12_02_000000_restore_deferred_foreign_keys`, pedidos por la
 * revisión `6167931953` punto 2.
 *
 * Lo que hay que demostrar, y que la entrega anterior no demostraba:
 *   1. replica limpia: sobre una base construida desde cero las cuatro claves existen;
 *   2. claves preexistentes con el nombre ORIGINAL: `up()` las deja intactas y no duplica;
 *   3. claves preexistentes con un nombre ALTERNATIVO: igual, sin fallar por el nombre;
 *   4. el rollback NO borra claves preexistentes;
 *   5. reejecutar `up()` no crea duplicados;
 *   6. filas sintéticas pobladas sobreviven a todo lo anterior.
 *
 * DÓNDE CORRE. Sólo contra el PostgreSQL DESECHABLE del entorno aislado; el test se niega a correr
 * si la base no se llama `cpv_test`. En PostgreSQL el DDL es transaccional, así que cada escenario
 * vive dentro de la transacción de `DatabaseTransactions` y el rollback lo deshace por completo,
 * incluso si una aserción falla a mitad. No hay `migrate:rollback` contra datos vivos, que es lo
 * que la revisión prohíbe, y no se copia ni un dato de cliente: las filas son inventadas aquí.
 */
class DeferredForeignKeysMigrationTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private const MIGRATION = '2022_12_02_000000_restore_deferred_foreign_keys.php';

    /** tabla => [columna, tabla referenciada, nombre que genera Laravel] */
    private const DEFERRED = [
        'empresas' => ['city_id', 'cities', 'empresas_city_id_foreign'],
        'sustainabilities' => ['areas_id', 'areas', 'sustainabilities_areas_id_foreign'],
        'infratypes' => ['infrasectors_id', 'infrasectors', 'infratypes_infrasectors_id_foreign'],
        'infrafacilities' => ['infraregion_infrasystem_id', 'infraregion_infrasystem', 'infrafacilities_infraregion_infrasystem_id_foreign'],
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

    /** @return array<int, array{name: string, column: string, on: string, on_delete: ?string}> */
    private function foreignKeysOn(string $table, string $column): array
    {
        return DB::connection('pgsql')->select(
            "select c.conname as name,
                    confrelid::regclass::text as referenced,
                    confdeltype as on_delete,
                    a.attname as column
             from pg_constraint c
             join unnest(c.conkey) as k(attnum) on true
             join pg_attribute a on a.attrelid = c.conrelid and a.attnum = k.attnum
             where c.contype = 'f'
               and c.conrelid = ?::regclass
               and a.attname = ?
             order by c.conname",
            [$table, $column]
        );
    }

    private function countForeignKeys(string $table, string $column): int
    {
        return count($this->foreignKeysOn($table, $column));
    }

    // ---------------------------------------------------------------------------------------
    // 1. Réplica limpia
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function a_fresh_replay_ends_with_all_four_deferred_foreign_keys(): void
    {
        foreach (self::DEFERRED as $table => [$column, $referenced, $name]) {
            $found = $this->foreignKeysOn($table, $column);
            $this->assertCount(1, $found, "Se esperaba exactamente una clave foránea en {$table}.{$column}.");
            $this->assertSame($referenced, $found[0]->referenced, "{$table}.{$column} debe referenciar {$referenced}.");
            $this->assertSame($name, $found[0]->name, 'El nombre debe ser el que genera Laravel.');
            // 'a' = NO ACTION en pg_constraint.confdeltype. Que no propague es parte de la
            // garantía: un CASCADE silencioso borraría filas que nadie pidió borrar.
            $this->assertSame('a', $found[0]->on_delete, "{$name} no debe propagar borrados.");
        }
    }

    // ---------------------------------------------------------------------------------------
    // 2 y 3. Claves preexistentes, con el nombre original y con uno alternativo
    // ---------------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: ?string}> null = el nombre que genera Laravel
     */
    public static function preexistingNameProvider(): array
    {
        return [
            'nombre original de Laravel' => [null],
            'nombre alternativo' => ['zzz_legacy_fk_name_from_elsewhere'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('preexistingNameProvider')]
    public function up_leaves_a_preexisting_foreign_key_intact_whatever_its_name(?string $alternativeName): void
    {
        [$column, $referenced, $laravelName] = self::DEFERRED['empresas'];
        $name = $alternativeName ?? $laravelName;

        // Reconstruye el estado "ya existía": se suelta la que puso el replay y se crea una
        // equivalente con el nombre del caso. Equivalente de verdad - misma columna, misma columna
        // referenciada, sin propagación - que es lo que la migración debe reconocer.
        DB::connection('pgsql')->statement("alter table empresas drop constraint {$laravelName}");
        DB::connection('pgsql')->statement(
            "alter table empresas add constraint {$name} foreign key ({$column}) references {$referenced} (id)"
        );
        $this->assertSame(1, $this->countForeignKeys('empresas', $column));

        $this->migration()->up();

        $after = $this->foreignKeysOn('empresas', $column);
        $this->assertCount(1, $after, 'up() no debe añadir una segunda clave foránea sobre la misma columna.');
        $this->assertSame($name, $after[0]->name, 'up() no debe renombrar ni reemplazar la restricción que ya estaba.');
        $this->assertSame($referenced, $after[0]->referenced);
    }

    // ---------------------------------------------------------------------------------------
    // 4. El rollback no borra lo ajeno
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function down_is_a_no_op_and_never_removes_a_preexisting_foreign_key(): void
    {
        $before = [];
        foreach (self::DEFERRED as $table => [$column, , ]) {
            $before[$table] = $this->foreignKeysOn($table, $column);
            $this->assertCount(1, $before[$table]);
        }

        $this->migration()->down();

        foreach (self::DEFERRED as $table => [$column, , $name]) {
            $after = $this->foreignKeysOn($table, $column);
            $this->assertCount(1, $after,
                "down() borró la clave foránea de {$table}.{$column}: sobre una base ya migrada eso destruye integridad histórica que esta migración no creó.");
            $this->assertSame($before[$table][0]->name, $after[0]->name);
        }
    }

    #[Test]
    public function down_then_up_is_safe_and_creates_no_duplicates(): void
    {
        $this->migration()->down();
        $this->migration()->up();

        foreach (self::DEFERRED as $table => [$column, , ]) {
            $this->assertSame(1, $this->countForeignKeys($table, $column),
                "down() seguido de up() dejó un número distinto de 1 clave foránea en {$table}.{$column}.");
        }
    }

    // ---------------------------------------------------------------------------------------
    // 5. Reejecutar up() no duplica
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function up_is_idempotent_across_repeated_runs(): void
    {
        $this->migration()->up();
        $this->migration()->up();
        $this->migration()->up();

        foreach (self::DEFERRED as $table => [$column, , $name]) {
            $found = $this->foreignKeysOn($table, $column);
            $this->assertCount(1, $found, "up() repetido duplicó la clave foránea de {$table}.{$column}.");
            $this->assertSame($name, $found[0]->name);
        }
    }

    // ---------------------------------------------------------------------------------------
    // 6. Las filas sobreviven
    // ---------------------------------------------------------------------------------------

    /**
     * Filas SINTÉTICAS, escritas aquí: ninguna viene de la base compartida ni de datos de clientes.
     * Importa porque una reparación de integridad que preserve las restricciones y pierda filas
     * seguiría siendo destructiva.
     */
    #[Test]
    public function synthetic_rows_survive_up_down_and_up_again(): void
    {
        // `cities` exige `country_id` y `states_id`, así que la cadena se arma entera. Todo
        // inventado aquí: ni un valor sale de la base compartida.
        $countryId = DB::connection('pgsql')->table('countries')->insertGetId([
            'country_name' => 'ZZZ Pais Ficticio '.substr(uniqid('', true), -8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $stateId = DB::connection('pgsql')->table('states')->insertGetId([
            'country_id' => $countryId,
            'state_name' => 'ZZZ Estado Ficticio '.substr(uniqid('', true), -8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cityId = DB::connection('pgsql')->table('cities')->insertGetId([
            'country_id' => $countryId,
            'states_id' => $stateId,
            'city_name' => 'ZZZ Ciudad Ficticia '.substr(uniqid('', true), -8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $empresaId = DB::connection('pgsql')->table('empresas')->insertGetId([
            // `rif` es UNIQUE y de 12 caracteres. La version anterior lo armaba con dos digitos
            // aleatorios y colisionaba con las empresas del fixture (J-00000000-1..3): falló de
            // verdad en una corrida, que es exactamente la clase de test inestable que este
            // trabajo viene corrigiendo. Prefijo 'Z' - que el fixture nunca usa - más uniqid, que
            // es único por proceso y por microsegundo.
            'rif' => substr('Z'.str_replace('.', '', uniqid('', true)), 0, 12),
            'name' => 'ZZZ Empresa Ficticia FK '.uniqid('', true),
            'city_id' => $cityId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->migration()->up();
        $this->migration()->down();
        $this->migration()->up();

        $row = DB::connection('pgsql')->table('empresas')->where('id', $empresaId)->first();
        $this->assertNotNull($row, 'La fila sintética desapareció al aplicar y revertir la migración.');
        $this->assertSame((int) $cityId, (int) $row->city_id, 'El vínculo de la fila se perdió.');
        $this->assertNotNull(DB::connection('pgsql')->table('cities')->where('id', $cityId)->first());

        // Y la restricción sigue haciendo su trabajo sobre esas filas.
        try {
            DB::connection('pgsql')->transaction(function () use ($empresaId) {
                DB::connection('pgsql')->table('empresas')->where('id', $empresaId)->update(['city_id' => 0]);
            });
            $this->fail('La clave foránea no está activa: se aceptó una ciudad inexistente.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertSame('23503', (string) $e->getCode());
        }
    }

    // ---------------------------------------------------------------------------------------
    // Fallos declarados: la migración tiene que gritar, no seguir en silencio
    // ---------------------------------------------------------------------------------------

    #[Test]
    public function a_missing_referenced_table_raises_instead_of_silently_succeeding(): void
    {
        // `cities` se referencia desde empresas; para poder soltarla hay que soltar primero esa FK.
        DB::connection('pgsql')->statement('alter table empresas drop constraint empresas_city_id_foreign');
        DB::connection('pgsql')->statement('drop table cities cascade');
        $this->assertFalse(Schema::connection('pgsql')->hasTable('cities'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/falta la tabla .*cities/');

        $this->migration()->up();
    }

    /**
     * Una clave foránea que apunte a otra parte, o que propague borrados, NO es la misma garantía.
     * Antes se habría aceptado como equivalente por coincidir la columna, dejando la base con un
     * comportamiento distinto del declarado y reportándolo como correcto.
     */
    #[Test]
    public function a_preexisting_but_non_equivalent_foreign_key_raises(): void
    {
        DB::connection('pgsql')->statement('alter table empresas drop constraint empresas_city_id_foreign');
        DB::connection('pgsql')->statement(
            'alter table empresas add constraint zzz_wrong_action_fk foreign key (city_id) references cities (id) on delete cascade'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no coincide con el objetivo/i');

        $this->migration()->up();
    }
}
