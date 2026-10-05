<?php

namespace Tests\Unit\Taxonomy;

use App\Models\TaxonomyCandidateConceptLink;
use App\Models\TaxonomyCanonicalConcept;
use App\Models\TaxonomyReviewedProposal;
use App\Models\TaxonomyTerm;
use App\Models\User;
use App\Services\Taxonomy\ReviewedProposalService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-0006B re-audit (Issue #2 comentario `5938949812`, «OTHER REVIEW NOTE — GROUP CONCURRENCY»):
 * endurecimiento del bloqueo de grupo en el APPLY bilingüe.
 *
 * EL DEFECTO CORREGIDO: `apply()` bloqueaba PRIMERO la fila de entrada y DESPUÉS todas las filas del
 * grupo. Dos `apply()` concurrentes que entraban por hermanos distintos del mismo grupo tomaban
 * locks de primera fila OPUESTOS y quedaban en espera circular -> deadlock de PostgreSQL. Postgres lo
 * detecta y revierte una de las dos, así que la garantía de "cero conceptos duplicados" se mantenía,
 * pero "una de las dos peticiones muere con un error de deadlock" es más débil que el contrato de
 * concurrencia de TASK-0006B.
 *
 * LA CORRECCIÓN: el primer lock de la transacción es un advisory lock cuya clave se deriva del
 * `proposal_group_id`, así que es IDÉNTICA para todos los hermanos y la espera circular desaparece
 * por construcción.
 *
 * QUÉ PRUEBA ESTE ARCHIVO, Y QUÉ NO. Se prueba: (1) que la clave es determinística e idéntica para
 * hermanos del mismo grupo -el invariante que elimina el ciclo-, (2) que el advisory lock es
 * realmente de exclusión mutua, verificado con DOS conexiones reales a Postgres, (3) que `apply()`
 * efectivamente lo tiene tomado cuando aplica un grupo -consultado en `pg_locks`, no asumido-, y
 * (4) que sigue siendo imposible crear dos conceptos a partir del par. **No** se prueba con dos
 * procesos PHP en paralelo: eso exigiría commitear fixtures reales para que ambas conexiones los
 * vieran, y esta tarea no autoriza escrituras reales. La ausencia de deadlock se demuestra por
 * construcción (clave común tomada antes de cualquier lock de fila) más la exclusión mutua real
 * medida en (2), no por una carrera simulada.
 *
 * **Ningún APPLY real** se ejecuta acá: todo es fixture desechable dentro de `DatabaseTransactions`,
 * y la prohibición del comentario («DO NOT execute a real APPLY to test it; fixtures only») se
 * respeta literalmente.
 */
class ReviewedProposalGroupLockingTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    /**
     * Segunda conexión REAL al mismo Postgres, para poder observar el lock desde afuera de la
     * transacción del test. Se registra como una conexión aparte con la misma configuración: no
     * comparte transacción con `pgsql`, que es justo el punto.
     */
    private function probeConnection(): \Illuminate\Database\Connection
    {
        config(['database.connections.pgsql_lock_probe' => config('database.connections.pgsql')]);

        return DB::connection('pgsql_lock_probe');
    }

    protected function tearDown(): void
    {
        // La conexión sonda se cierra explícitamente para no dejar backends colgados en el pooler.
        try {
            DB::purge('pgsql_lock_probe');
        } catch (\Throwable) {
            // Si nunca se abrió, no hay nada que purgar.
        }

        parent::tearDown();
    }

    private function authorizedUser(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            'update_taxonomy::candidate::concept::link',
            'update_taxonomy::concept::relation',
        ]);

        return $user;
    }

    private function term(string $language, string $canonical): TaxonomyTerm
    {
        return TaxonomyTerm::create([
            'external_id' => 'c2b-lock-'.uniqid('', true),
            'term' => 'zzz_task0006b_lock_'.$language.'_'.uniqid('', true),
            'language' => $language,
            'canonical_term' => $canonical,
            'term_type' => TaxonomyTerm::TERM_TYPE_TECHNICAL,
            'region' => [], 'negative_context' => [], 'positive_context' => [],
            'mapping_review_status' => TaxonomyTerm::MAPPING_UNMAPPED,
        ]);
    }

    private function newConceptCandidate(string $language, string $canonical): TaxonomyCandidateConceptLink
    {
        return TaxonomyCandidateConceptLink::create([
            'suggested_term_id' => $this->term($language, $canonical)->id,
            'suggested_concept_id' => null,
            'suggested_new_concept_name' => 'zzz_task0006b_lock_sug_'.uniqid('', true),
            'signals' => [], 'confidence' => 0.5, 'tier' => TaxonomyCandidateConceptLink::TIER_REVIEW,
            'status' => TaxonomyCandidateConceptLink::STATUS_PENDING,
        ]);
    }

    /** @return array{0:array,1:TaxonomyCandidateConceptLink,2:TaxonomyCandidateConceptLink} */
    private function frozenBilingualGroup(User $user): array
    {
        $canonical = 'zzz_task0006b_lockpair_'.uniqid();
        $en = $this->newConceptCandidate('en', $canonical);
        $es = $this->newConceptCandidate('es', $canonical);

        $frozen = (new ReviewedProposalService())->freezeBilingualConceptGroup(
            [$en->id, $es->id],
            'zzz_task0006b_lock_es_'.uniqid(),
            'zzz_task0006b_lock_en_'.uniqid(),
            $user,
        );

        $this->assertSame(ReviewedProposalService::RESULT_FROZEN, $frozen['result']);

        return [$frozen, $en, $es];
    }

    // =========================================================================================
    // 1. La clave es determinística y COMÚN a todos los hermanos - el invariante que mata el ciclo
    // =========================================================================================

    #[Test]
    public function every_sibling_of_a_group_derives_the_exact_same_lock_key(): void
    {
        $user = $this->authorizedUser();
        [$frozen] = $this->frozenBilingualGroup($user);

        $keys = array_map(
            fn (TaxonomyReviewedProposal $p) => ReviewedProposalService::groupAdvisoryLockKey($p->proposal_group_id),
            $frozen['proposals'],
        );

        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1],
            'Si los hermanos derivaran claves distintas volvería la espera circular: cada uno tomaría un lock que el otro necesita.');
        // Y la clave entra en un bigint con signo (60 bits), así que Postgres nunca la desborda.
        $this->assertGreaterThan(0, $keys[0]);
        $this->assertLessThan(PHP_INT_MAX, $keys[0]);
    }

    #[Test]
    public function the_lock_key_is_stable_across_calls_and_distinct_across_groups(): void
    {
        $groupA = '043fce22-daf0-4fda-83ed-df666d89ace6';
        $groupB = '11111111-2222-3333-4444-555555555555';

        $this->assertSame(
            ReviewedProposalService::groupAdvisoryLockKey($groupA),
            ReviewedProposalService::groupAdvisoryLockKey($groupA),
            'La clave tiene que ser estable: si cambiara entre llamadas, dos hermanos del mismo grupo no se serializarían.',
        );
        $this->assertNotSame(
            ReviewedProposalService::groupAdvisoryLockKey($groupA),
            ReviewedProposalService::groupAdvisoryLockKey($groupB),
            'Grupos distintos no deben serializarse entre sí.',
        );
    }

    // =========================================================================================
    // 2. El advisory lock es de exclusión mutua REAL, medido con dos conexiones a Postgres
    // =========================================================================================

    #[Test]
    public function the_group_lock_is_genuinely_mutually_exclusive_across_connections(): void
    {
        $groupId = (string) \Illuminate\Support\Str::uuid();
        $otherGroupId = (string) \Illuminate\Support\Str::uuid();
        $key = ReviewedProposalService::groupAdvisoryLockKey($groupId);
        $otherKey = ReviewedProposalService::groupAdvisoryLockKey($otherGroupId);

        // La conexión del test toma el lock. `DatabaseTransactions` mantiene abierta la transacción
        // de nivel superior durante todo el test, así que un `pg_advisory_xact_lock` sigue tomado
        // hasta el rollback final - exactamente la ventana que se quiere observar.
        DB::connection('pgsql')->statement('SELECT pg_advisory_xact_lock(?)', [$key]);

        $probe = $this->probeConnection();

        // Desde OTRA conexión, intentar el MISMO lock tiene que fallar...
        $busy = $probe->selectOne('SELECT pg_try_advisory_lock(?) AS got', [$key]);
        $this->assertFalse($this->toBool($busy->got),
            'Otra conexión no puede tomar el advisory lock del mismo grupo mientras esta transacción lo tiene.');

        // ...y el de OTRO grupo tiene que poder tomarse, para descartar que la sonda esté fallando
        // por cualquier otro motivo (una aserción negativa sola no probaría nada).
        $free = $probe->selectOne('SELECT pg_try_advisory_lock(?) AS got', [$otherKey]);
        $this->assertTrue($this->toBool($free->got),
            'El lock de un grupo distinto tiene que estar libre - si no, la sonda no está midiendo lo que se cree.');

        // Se libera lo que la sonda tomó (es de sesión, no de transacción).
        $probe->statement('SELECT pg_advisory_unlock(?)', [$otherKey]);
    }

    // =========================================================================================
    // 3. `apply()` REALMENTE lo toma al aplicar un grupo - consultado, no asumido
    // =========================================================================================

    #[Test]
    public function applying_a_grouped_proposal_holds_the_group_advisory_lock(): void
    {
        $user = $this->authorizedUser();
        [$frozen, $en, $es] = $this->frozenBilingualGroup($user);
        $groupId = $frozen['group_id'];

        $this->assertFalse(ReviewedProposalService::holdsGroupAdvisoryLock($groupId),
            'Precondición: el lock no está tomado antes de aplicar.');

        $outcome = (new ReviewedProposalService())->apply($frozen['proposals'][0]->id, 'TASK-0006B lock test 1');

        $this->assertSame(ReviewedProposalService::RESULT_APPLIED, $outcome['result']);

        // El advisory lock es de TRANSACCIÓN y la transacción de `apply()` es un SAVEPOINT dentro de
        // la transacción del test, así que el lock sigue tomado acá. Eso permite comprobar en
        // `pg_locks` que `apply()` lo tomó de verdad, en lugar de confiar en que el código lo haga.
        $this->assertTrue(ReviewedProposalService::holdsGroupAdvisoryLock($groupId),
            'apply() debe haber tomado el advisory lock del grupo.');
    }

    #[Test]
    public function applying_an_ungrouped_proposal_takes_no_group_lock(): void
    {
        // La corrección no debe cambiar en nada el camino de una propuesta suelta: ahí el lock de
        // fila ya alcanzaba y no hay grupo que serializar.
        $user = $this->authorizedUser();
        $candidate = $this->newConceptCandidate('es', 'zzz_task0006b_lock_single_'.uniqid());

        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED,
            $user,
            ['context_reason' => 'zzz_task0006b sin grupo'],
        );

        $this->assertNull($frozen['proposal']->proposal_group_id);

        // TASK-0007 (Issue #2 comentario `5997693379`), PARTE 5: desde que `apply()` toma el advisory
        // lock COMÚN de ejecución C2 como primera acción de su transacción, "cuántos advisory locks
        // tiene esta sesión" dejó de ser una medida válida de «¿tomó el lock DEL GRUPO?» - que es lo
        // que este test afirma en su nombre y en su mensaje de error. La invariante que el test cubre
        // NO cambió (una propuesta suelta sigue sin tomar ningún lock de grupo); lo que cambió es que
        // el conteo total ya no la mide, porque ahora incluye un lock distinto y deliberado. Se mide
        // entonces exactamente lo que el test siempre quiso medir -los advisory locks que NO son el de
        // ejecución- y el de ejecución se verifica aparte, afirmando que SÍ está.
        $groupLocks = fn () => (int) DB::connection('pgsql')->selectOne(
            "SELECT COUNT(*) AS n FROM pg_locks
             WHERE locktype='advisory' AND pid=pg_backend_pid() AND granted AND objsubid = 1
               AND ((classid::bigint << 32) | objid::bigint) <> ?",
            [ReviewedProposalService::executionAdvisoryLockKey()],
        )->n;

        $before = $groupLocks();

        $outcome = (new ReviewedProposalService())->apply($frozen['proposal']->id, 'TASK-0006B lock test 2');

        $after = $groupLocks();

        $this->assertSame(ReviewedProposalService::RESULT_APPLIED, $outcome['result']);
        $this->assertSame($before, $after,
            'Una propuesta sin grupo no debe tomar ningún advisory lock DE GRUPO.');
        $this->assertTrue(ReviewedProposalService::holdsExecutionAdvisoryLock(),
            'El lock común de ejecución SÍ se toma, también para una propuesta suelta - es lo que impide que un apply suelto se intercale en un lote (TASK-0007 PARTE 5).');
    }

    // =========================================================================================
    // 4. La garantía de fondo sigue en pie: nunca dos conceptos a partir del par
    // =========================================================================================

    #[Test]
    public function the_hardened_path_still_creates_exactly_one_concept_for_the_pair(): void
    {
        $user = $this->authorizedUser();
        [$frozen, $en, $es] = $this->frozenBilingualGroup($user);
        $conceptsBefore = DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count();

        $first = (new ReviewedProposalService())->apply($frozen['proposals'][0]->id, 'TASK-0006B lock test 3');
        $second = (new ReviewedProposalService())->apply($frozen['proposals'][1]->id, 'TASK-0006B lock test 4');

        $this->assertSame(ReviewedProposalService::RESULT_APPLIED, $first['result']);
        $this->assertSame(ReviewedProposalService::RESULT_ALREADY_APPLIED, $second['result']);
        $this->assertSame($conceptsBefore + 1, DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count(),
            'El par tiene que converger en UN solo concepto, también con el bloqueo endurecido.');

        $conceptId = $first['application_result']['concept_id'];
        foreach ([$en, $es] as $member) {
            $this->assertSame(1, DB::connection('pgsql')->table('taxonomy_term_concepts')
                ->where('term_id', $member->suggested_term_id)->where('concept_id', $conceptId)->count());
        }
        $this->assertNotNull(TaxonomyCanonicalConcept::find($conceptId));
    }

    #[Test]
    public function entering_from_either_sibling_serialises_on_the_same_key(): void
    {
        // Comprobación directa del invariante que elimina el deadlock: no importa por qué hermano se
        // entre, el lock que se toma es el MISMO, así que no puede haber dos transacciones
        // esperándose en claves cruzadas.
        $user = $this->authorizedUser();
        [$frozen] = $this->frozenBilingualGroup($user);
        $groupId = $frozen['group_id'];

        foreach ([0, 1] as $entryIndex) {
            $this->assertSame(
                ReviewedProposalService::groupAdvisoryLockKey($groupId),
                ReviewedProposalService::groupAdvisoryLockKey($frozen['proposals'][$entryIndex]->proposal_group_id),
                "Entrar por el hermano {$entryIndex} tiene que serializar en la misma clave de grupo.",
            );
        }
    }

    /** PDO/pgsql puede devolver booleanos como `true`/`false` o como `'t'`/`'f'`. */
    private function toBool(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 1 || $value === '1';
    }
}
