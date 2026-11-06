<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-0013 (Issue #2 `6684941317`): `cira_search_log` y `cira_search_feedback`.
 *
 * Cubre up/down/up, idempotencia, RLS y cada restricción que protege la calidad de los votos.
 *
 * DÓNDE CORRE. Sólo contra el PostgreSQL desechable del entorno aislado: se niega a correr si la base
 * no se llama `cpv_test`. El DDL de PostgreSQL es transaccional, así que todo vive dentro de la
 * transacción de `DatabaseTransactions` y el rollback final lo deshace, incluso si una aserción falla.
 */
class CiraSearchLogMigrationTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private const MIGRATION = '2026_11_06_120000_create_cira_search_log_and_feedback_tables.php';

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

    private function db()
    {
        return DB::connection('pgsql');
    }

    private function tablas(): array
    {
        return [
            Schema::connection('pgsql')->hasTable('cira_search_log'),
            Schema::connection('pgsql')->hasTable('cira_search_feedback'),
        ];
    }

    private function empresaId(int $offset = 0): int
    {
        $id = $this->db()->table('empresas')->orderBy('id')->offset($offset)->value('id');
        $this->assertNotNull($id, 'El fixture sintético debería haber cargado empresas.');

        return (int) $id;
    }

    private function nuevaBusqueda(array $extra = []): int
    {
        return (int) $this->db()->table('cira_search_log')->insertGetId($extra + [
            'user_input' => 'zzz cabrias',
            'agent_tool' => 'search_empresas',
            'agent_params' => json_encode(['query' => 'cabria', 'resolvedPhrases' => ['cabrias']]),
            'result_count' => 2,
            'result_empresa_ids' => '{'.$this->empresaId().','.$this->empresaId(1).'}',
        ]);
    }

    /** @return array<string, mixed> */
    private function voto(int $logId, array $extra = []): array
    {
        return $extra + [
            'search_log_id' => $logId,
            'empresa_id' => $this->empresaId(),
            'rank_position' => 1,
            'vote' => -1,
            'reason' => 'otro_sector',
            'voter_kind' => 'staff',
            'voter_ref' => 'zzz-staff-1',
        ];
    }

    #[Test]
    public function up_down_up_creates_reverts_and_recreates_only_this_tasks_tables(): void
    {
        $migration = $this->migration();
        $migration->up();
        $this->assertSame([true, true], $this->tablas());

        $empresasAntes = $this->db()->table('empresas')->count();
        $this->assertGreaterThan(0, $empresasAntes);

        $migration->down();
        $this->assertSame([false, false], $this->tablas(), 'down() tiene que soltar las dos tablas.');
        $this->assertSame($empresasAntes, $this->db()->table('empresas')->count(), 'down() no debe tocar empresas.');

        $migration->up();
        $this->assertSame([true, true], $this->tablas());

        // La tabla recreada sirve: guarda una búsqueda con su arreglo ordenado y un voto válido.
        $logId = $this->nuevaBusqueda();
        $this->db()->table('cira_search_feedback')->insert($this->voto($logId));

        $ids = $this->db()->selectOne('select result_empresa_ids::text as ids from cira_search_log where id = ?', [$logId])->ids;
        $this->assertSame('{'.$this->empresaId().','.$this->empresaId(1).'}', $ids, 'El orden de los ids devueltos tiene que conservarse.');
        $this->assertSame(1, $this->db()->table('cira_search_feedback')->where('search_log_id', $logId)->count());
    }

    #[Test]
    public function up_is_idempotent_and_creates_no_duplicate_indexes(): void
    {
        $migration = $this->migration();
        $migration->up();
        $antes = $this->indices();

        $migration->up();
        $migration->up();

        $this->assertSame($antes, $this->indices(), 'Un up() repetido no debe duplicar índices.');
        $this->assertContains('cira_search_feedback_one_vote_idx', $antes);
    }

    #[Test]
    public function both_tables_have_row_level_security_enabled(): void
    {
        $this->migration()->up();

        $rls = $this->db()->select(
            "select relname, relrowsecurity from pg_class where relname in ('cira_search_log', 'cira_search_feedback') order by relname"
        );

        $this->assertCount(2, $rls);
        foreach ($rls as $fila) {
            $this->assertTrue((bool) $fila->relrowsecurity, "RLS desactivado en {$fila->relname}: la API REST de Supabase podría leerla.");
        }
    }

    #[Test]
    public function the_log_rejects_invalid_rows(): void
    {
        $this->migration()->up();

        $this->assertRechaza('cira_search_log', ['source' => 'desconocido', 'user_input' => 'x'], '23514', 'el CHECK de source');
        $this->assertRechaza('cira_search_log', ['user_input' => ''], '23514', 'el CHECK de user_input vacío');
        $this->assertRechaza('cira_search_log', ['user_input' => 'x', 'result_count' => -1], '23514', 'el CHECK de result_count');
        $this->assertRechaza('cira_search_log', ['user_input' => 'x', 'response_ms' => -5], '23514', 'el CHECK de response_ms');
    }

    #[Test]
    public function the_feedback_constraints_protect_vote_quality(): void
    {
        $this->migration()->up();
        $logId = $this->nuevaBusqueda();

        $this->assertRechaza('cira_search_feedback', $this->voto($logId, ['vote' => 0]), '23514', 'el CHECK de vote');
        $this->assertRechaza('cira_search_feedback', $this->voto($logId, ['vote' => 1, 'reason' => 'otro_sector']), '23514', 'motivo solo en voto negativo');
        $this->assertRechaza('cira_search_feedback', $this->voto($logId, ['reason' => 'inventado']), '23514', 'el CHECK de reason');
        $this->assertRechaza('cira_search_feedback', $this->voto($logId, ['missing_empresa_id' => $this->empresaId(1)]), '23514', 'empresa faltante sin el motivo falta_empresa');
        $this->assertRechaza('cira_search_feedback', $this->voto($logId, ['voter_kind' => 'bot']), '23514', 'el CHECK de voter_kind');
        $this->assertRechaza('cira_search_feedback', $this->voto($logId, ['rank_position' => 0]), '23514', 'el CHECK de rank_position');
        $this->assertRechaza('cira_search_feedback', $this->voto($logId, ['comment' => str_repeat('a', 1001)]), '23514', 'el largo máximo de comment');
        $this->assertRechaza('cira_search_feedback', $this->voto($logId, ['empresa_id' => 0]), '23503', 'la clave foránea a empresas');
        $this->assertRechaza('cira_search_feedback', $this->voto(0), '23503', 'la clave foránea a cira_search_log');

        // Un voto "falta esta empresa" bien formado sí entra.
        $this->db()->table('cira_search_feedback')->insert($this->voto($logId, [
            'empresa_id' => null, 'rank_position' => null, 'reason' => 'falta_empresa', 'missing_empresa_id' => $this->empresaId(1),
        ]));
        $this->assertSame(1, $this->db()->table('cira_search_feedback')->where('reason', 'falta_empresa')->count());
    }

    #[Test]
    public function one_vote_per_voter_search_and_company(): void
    {
        $this->migration()->up();
        $logId = $this->nuevaBusqueda();

        $this->db()->table('cira_search_feedback')->insert($this->voto($logId));
        $this->assertRechaza('cira_search_feedback', $this->voto($logId, ['vote' => -1, 'reason' => 'otro']), '23505', 'un segundo voto del mismo votante a la misma empresa');

        // Otro votante sí puede votar la misma empresa, y el mismo votante puede votar otra empresa.
        $this->db()->table('cira_search_feedback')->insert($this->voto($logId, ['voter_ref' => 'zzz-staff-2']));
        $this->db()->table('cira_search_feedback')->insert($this->voto($logId, ['empresa_id' => $this->empresaId(1), 'rank_position' => 2]));

        // El voto anónimo sin referencia sobre el resultado completo también es único por búsqueda.
        $completo = ['empresa_id' => null, 'rank_position' => null, 'voter_ref' => null, 'voter_kind' => 'anonymous'];
        $this->db()->table('cira_search_feedback')->insert($this->voto($logId, $completo));
        $this->assertRechaza('cira_search_feedback', $this->voto($logId, $completo), '23505', 'un segundo voto anónimo sobre el resultado completo');

        $this->assertSame(4, $this->db()->table('cira_search_feedback')->where('search_log_id', $logId)->count());
    }

    #[Test]
    public function deleting_a_search_removes_its_votes_and_deleting_a_company_keeps_the_vote(): void
    {
        $this->migration()->up();
        $logId = $this->nuevaBusqueda();
        $this->db()->table('cira_search_feedback')->insert($this->voto($logId));

        $this->db()->table('cira_search_log')->where('id', $logId)->delete();
        $this->assertSame(0, $this->db()->table('cira_search_feedback')->where('search_log_id', $logId)->count(),
            'Borrar la búsqueda tiene que borrar sus votos (ON DELETE CASCADE).');

        // Verifica la regla ON DELETE SET NULL en el catálogo, sin borrar empresas del fixture.
        $regla = $this->db()->selectOne(<<<'SQL'
            select rc.delete_rule
            from information_schema.referential_constraints rc
            join information_schema.key_column_usage k on k.constraint_name = rc.constraint_name
            where k.table_name = 'cira_search_feedback' and k.column_name = 'empresa_id'
        SQL);
        $this->assertSame('SET NULL', $regla->delete_rule, 'Borrar una empresa no debe borrar el voto: lo deja sin empresa.');
    }

    /**
     * Cada inserción va en su propio SAVEPOINT. En PostgreSQL el primer error aborta la transacción
     * entera y todo lo que sigue falla con `25P02`; `transaction()` anidado emite un SAVEPOINT y
     * vuelve a él (mismo patrón que `TaxonomyCategoryRequestsMigrationTest`).
     *
     * @param  array<string, mixed>  $fila
     */
    private function assertRechaza(string $tabla, array $fila, string $sqlstate, string $queGarantia): void
    {
        $capturada = null;

        try {
            $this->db()->transaction(function () use ($tabla, $fila) {
                $this->db()->table($tabla)->insert($fila);
            });
        } catch (QueryException $e) {
            $capturada = $e;
        }

        $this->assertNotNull($capturada, "No está activa {$queGarantia}: la base aceptó una fila inválida.");
        $this->assertSame($sqlstate, (string) $capturada->getCode(),
            "Se esperaba SQLSTATE {$sqlstate} por {$queGarantia}, llegó {$capturada->getCode()}.");
    }

    /** @return array<int, string> */
    private function indices(): array
    {
        return $this->db()->table('pg_indexes')
            ->whereIn('tablename', ['cira_search_log', 'cira_search_feedback'])
            ->orderBy('indexname')
            ->pluck('indexname')
            ->all();
    }
}
