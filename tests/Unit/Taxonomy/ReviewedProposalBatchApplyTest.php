<?php

namespace Tests\Unit\Taxonomy;

use App\Models\TaxonomyCandidateConceptLink;
use App\Models\TaxonomyCanonicalConcept;
use App\Models\TaxonomyConceptRelation;
use App\Models\TaxonomyReviewedProposal;
use App\Models\TaxonomyTerm;
use App\Models\User;
use App\Services\Taxonomy\CanonicalConceptBuilderService;
use App\Services\Taxonomy\ReviewedProposalBatchManifest;
use App\Services\Taxonomy\ReviewedProposalService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-0007 (Issue #2 comentario `5997693379`), PARTE 9: cobertura del lote atómico.
 *
 * TODO ES FIXTURE DESECHABLE dentro de `DatabaseTransactions` sobre `pgsql`: nada commitea, y ninguna
 * de las 12 propuestas reales (#491, #492-#495, #629/#630, #631/#632, #1688-#1690) ni las tres
 * supersedidas (#420/#421/#422) participa de ningún test. La ronda 1 autoriza explícitamente
 * «fixture-only batch execution tests» y prohíbe el APPLY real - acá se respeta literalmente: cada
 * test crea sus propios términos, conceptos, candidatos, relaciones y propuestas congeladas.
 *
 * EL TEST QUE JUSTIFICA LA TAREA ENTERA es `sequential_single_applies_stale_the_later_proposal...`
 * (grupo B del pedido): demuestra, ejecutando de verdad sobre fixtures, que encadenar `apply()` sobre
 * dos propuestas congeladas contra el MISMO snapshot ABORTA la segunda de forma terminal en cuanto la
 * primera cambia el grafo. No es una hipótesis del diseño: es el comportamiento medido, y es la razón
 * por la que un lote no es una optimización sino la única ejecución correcta de un conjunto revisado.
 *
 * ORDEN DE LOS FIXTURES, que no es un detalle de estilo: `dryRunInputFingerprint()` incluye el grafo
 * de conceptos (`taxonomy_canonical_concepts` + `taxonomy_term_concepts`) y una señal de versión de
 * `taxonomy_terms`/`taxonomy_concept_relations`, así que CREAR un fixture cambia el fingerprint.
 * Por eso cada escenario crea TODAS sus filas fuente primero y congela TODAS sus propuestas después:
 * así todas comparten un único `taxonomy_state_fingerprint`, que es justamente la situación que un
 * lote tiene que saber ejecutar.
 */
class ReviewedProposalBatchApplyTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private const AUTH_REFERENCE = 'TASK-0007 fixture batch test 5997693379';

    protected function tearDown(): void
    {
        try {
            DB::purge('pgsql_batch_lock_probe');
        } catch (\Throwable) {
            // Si nunca se abrió, no hay nada que purgar.
        }

        foreach ($this->temporaryManifestFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->temporaryManifestFiles = [];

        parent::tearDown();
    }

    // =========================================================================================
    // Fixtures
    // =========================================================================================

    private function authorizedUser(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            'update_taxonomy::candidate::concept::link',
            'update_taxonomy::concept::relation',
        ]);

        return $user;
    }

    private function term(string $language = 'es'): TaxonomyTerm
    {
        return TaxonomyTerm::create([
            'external_id' => 'c2-batch-'.uniqid('', true),
            'term' => 'zzz_batch_'.uniqid('', true),
            'language' => $language,
            'canonical_term' => 'zzz_batch_'.uniqid('', true),
            'term_type' => TaxonomyTerm::TERM_TYPE_TECHNICAL,
            'region' => [], 'negative_context' => [], 'positive_context' => [],
            'mapping_review_status' => TaxonomyTerm::MAPPING_UNMAPPED,
        ]);
    }

    private function concept(?string $name = null): TaxonomyCanonicalConcept
    {
        return TaxonomyCanonicalConcept::create([
            'canonical_name_es' => $name ?? 'zzz_batch_concept_'.uniqid('', true),
            'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE,
        ]);
    }

    private function pendingCandidate(string $language = 'es', ?int $conceptId = null, ?string $suggestedNewName = null): TaxonomyCandidateConceptLink
    {
        return TaxonomyCandidateConceptLink::create([
            'suggested_term_id' => $this->term($language)->id,
            'suggested_concept_id' => $conceptId,
            'suggested_new_concept_name' => $suggestedNewName,
            'signals' => [], 'confidence' => 0.5, 'tier' => TaxonomyCandidateConceptLink::TIER_REVIEW,
            'status' => TaxonomyCandidateConceptLink::STATUS_PENDING,
        ]);
    }

    private function candidateRelation(): TaxonomyConceptRelation
    {
        return TaxonomyConceptRelation::create([
            'source_concept_id' => $this->concept()->id,
            'target_concept_id' => $this->concept()->id,
            'relation_type' => 'RELATED_TO',
            'weight' => 0.5, 'confidence' => 0.5,
            'status' => TaxonomyConceptRelation::STATUS_CANDIDATE,
        ]);
    }

    private function freezeContextRequired(TaxonomyCandidateConceptLink $candidate, User $user, ?string $actorType = null): TaxonomyReviewedProposal
    {
        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED,
            $user,
            ['context_reason' => 'zzz_batch término genérico '.uniqid()],
            $actorType,
        );

        $this->assertSame(ReviewedProposalService::RESULT_FROZEN, $frozen['result']);

        return $frozen['proposal'];
    }

    private function freezeMapToExisting(TaxonomyCandidateConceptLink $candidate, int $conceptId, User $user): TaxonomyReviewedProposal
    {
        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING,
            $user,
            ['target_concept_id' => $conceptId],
        );

        $this->assertSame(ReviewedProposalService::RESULT_FROZEN, $frozen['result']);

        return $frozen['proposal'];
    }

    private function freezeRelationDecision(TaxonomyConceptRelation $relation, string $decision, User $user): TaxonomyReviewedProposal
    {
        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_CONCEPT_RELATION,
            $relation->id,
            $decision,
            $user,
        );

        $this->assertSame(ReviewedProposalService::RESULT_FROZEN, $frozen['result']);

        return $frozen['proposal'];
    }

    /**
     * El escenario REPRESENTATIVO que pide el grupo A: varias CONTEXT_REQUIRED + una MAP_TO_EXISTING +
     * un grupo bilingüe CREATE_NEW + un REJECT de relación, todo congelado contra UN snapshot.
     *
     * @return array{user:User, ids:int[], proposals:array<string,mixed>, candidates:array<string,TaxonomyCandidateConceptLink>, relation:TaxonomyConceptRelation, map_concept:TaxonomyCanonicalConcept, group_id:string}
     */
    private function representativeBatch(): array
    {
        $user = $this->authorizedUser();

        // 1) TODAS las filas fuente primero: crear cambia el fingerprint, congelar no.
        $ctxA = $this->pendingCandidate('es');
        $ctxB = $this->pendingCandidate('en');
        $mapConcept = $this->concept();
        $mapCandidate = $this->pendingCandidate('en', $mapConcept->id);
        $groupCanonical = 'zzz_batch_group_sug_'.uniqid();
        $groupEn = $this->pendingCandidate('en', null, $groupCanonical);
        $groupEs = $this->pendingCandidate('es', null, $groupCanonical);
        $relation = $this->candidateRelation();

        // 2) Y recién entonces TODAS las propuestas, que quedan con el mismo snapshot congelado.
        $pCtxA = $this->freezeContextRequired($ctxA, $user);
        $pCtxB = $this->freezeContextRequired($ctxB, $user);
        $pMap = $this->freezeMapToExisting($mapCandidate, $mapConcept->id, $user);

        $group = (new ReviewedProposalService())->freezeBilingualConceptGroup(
            [$groupEn->id, $groupEs->id],
            'zzz_batch_es_'.uniqid(),
            'zzz_batch_en_'.uniqid(),
            $user,
        );
        $this->assertSame(ReviewedProposalService::RESULT_FROZEN, $group['result']);

        $pRelation = $this->freezeRelationDecision($relation, TaxonomyReviewedProposal::DECISION_REJECT, $user);

        $ids = [
            (int) $pCtxA->id, (int) $pCtxB->id, (int) $pMap->id,
            (int) $group['proposals'][0]->id, (int) $group['proposals'][1]->id,
            (int) $pRelation->id,
        ];
        sort($ids);

        // Precondición del escenario: un solo fingerprint para todo el conjunto.
        $fingerprints = TaxonomyReviewedProposal::query()->whereIn('id', $ids)->pluck('taxonomy_state_fingerprint')->unique();
        $this->assertCount(1, $fingerprints, 'El fixture no congeló todo contra un único snapshot: el escenario no sería el que la tarea tiene que resolver.');
        $this->assertSame(CanonicalConceptBuilderService::dryRunInputFingerprint(), $fingerprints->first());

        return [
            'user' => $user,
            'ids' => $ids,
            'proposals' => [
                'ctx_a' => $pCtxA, 'ctx_b' => $pCtxB, 'map' => $pMap,
                'group' => $group['proposals'], 'relation' => $pRelation,
            ],
            'candidates' => [
                'ctx_a' => $ctxA, 'ctx_b' => $ctxB, 'map' => $mapCandidate,
                'group_en' => $groupEn, 'group_es' => $groupEs,
            ],
            'relation' => $relation,
            'map_concept' => $mapConcept,
            'group_id' => $group['group_id'],
        ];
    }

    // =========================================================================================
    // Instrumentación
    // =========================================================================================

    /** Corre `$callback` midiendo todos los statements de ESCRITURA que emita. */
    private function captureWrites(callable $callback, ?array &$writes = null): mixed
    {
        $observed = [];
        $recording = true;
        DB::listen(function ($query) use (&$observed, &$recording) {
            if ($recording && preg_match('/^\s*(insert|update|delete|truncate|alter|create|drop)\b/i', $query->sql)) {
                $observed[] = $query->sql;
            }
        });

        try {
            return $callback();
        } finally {
            $recording = false;
            $writes = $observed;
        }
    }

    private function counts(): array
    {
        return [
            'concepts' => DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count(),
            'term_concepts' => DB::connection('pgsql')->table('taxonomy_term_concepts')->count(),
            'term_cpv' => DB::connection('pgsql')->table('taxonomy_term_cpv_relations')->count(),
            'candidates_published' => DB::connection('pgsql')->table('taxonomy_candidate_concept_links')->where('status', TaxonomyCandidateConceptLink::STATUS_PUBLISHED)->count(),
            'relations_approved' => DB::connection('pgsql')->table('taxonomy_concept_relations')->where('status', TaxonomyConceptRelation::STATUS_APPROVED)->count(),
            'proposals_applied' => DB::connection('pgsql')->table('taxonomy_reviewed_proposals')->where('status', TaxonomyReviewedProposal::STATUS_APPLIED)->count(),
            'proposals_aborted' => DB::connection('pgsql')->table('taxonomy_reviewed_proposals')->where('status', TaxonomyReviewedProposal::STATUS_ABORTED)->count(),
            'audit' => DB::connection('pgsql')->table('taxonomy_audit_log')->count(),
        ];
    }

    /**
     * La aserción del grupo C, completa y en un solo lugar: el lote se bloquea con el código esperado
     * y NADA cambió - cero escrituras SQL, cero filas de taxonomía, cero propuestas APPLIED, cero
     * propuestas ABORTED y cero filas de auditoría.
     */
    private function assertBatchBlockedWithoutAnyWrite(array $ids, string $expectedBlocker, ?array $manifest = null, ?string $expectedFingerprint = null): array
    {
        $before = $this->counts();
        $statuses = TaxonomyReviewedProposal::query()->whereIn('id', $ids)->orderBy('id')->pluck('status', 'id')->all();

        $writes = [];
        $result = $this->captureWrites(
            fn () => (new ReviewedProposalService())->applyBatch($ids, self::AUTH_REFERENCE, $manifest, $expectedFingerprint),
            $writes,
        );

        $this->assertSame([], $writes, 'El lote bloqueado emitió statements de ESCRITURA: '.implode(' | ', $writes));
        $this->assertSame($expectedBlocker, $result['blocker']);
        $this->assertNotSame(ReviewedProposalService::BATCH_RESULT_APPLIED, $result['result']);
        $this->assertSame([], $result['applied_proposal_ids']);
        $this->assertSame($before, $this->counts(), 'El lote bloqueado cambió algún conteo protegido.');
        $this->assertSame(
            $statuses,
            TaxonomyReviewedProposal::query()->whereIn('id', $ids)->orderBy('id')->pluck('status', 'id')->all(),
            'El lote bloqueado cambió el status de alguna propuesta: un lote bloqueado NO quema su contenido.',
        );

        return $result;
    }

    // =========================================================================================
    // A. HAPPY PATH
    // =========================================================================================

    #[Test]
    public function a_representative_batch_commits_atomically_against_one_baseline(): void
    {
        $scenario = $this->representativeBatch();
        $before = $this->counts();

        $result = (new ReviewedProposalService())->applyBatch($scenario['ids'], self::AUTH_REFERENCE);

        $this->assertSame(ReviewedProposalService::BATCH_RESULT_APPLIED, $result['result']);
        $this->assertNull($result['blocker']);

        // 6 filas -> 5 unidades, porque el grupo bilingüe es UNA unidad indivisible.
        $this->assertSame(5, $result['execution_unit_count']);
        $this->assertSame($scenario['ids'], $result['applied_proposal_ids']);
        $this->assertSame(6, $result['applied_proposal_count']);

        // EL PUNTO DEL GRUPO A: las escrituras que cambian el grafo (la MAP_TO_EXISTING y el grupo)
        // NO dejan obsoletos a los miembros posteriores del MISMO lote.
        $this->assertSame(0, TaxonomyReviewedProposal::query()->whereIn('id', $scenario['ids'])
            ->where('status', '!=', TaxonomyReviewedProposal::STATUS_APPLIED)->count(),
            'Alguna propuesta del lote no quedó APPLIED: la obsolescencia intra-lote volvió a aparecer.');

        $after = $this->counts();
        $this->assertSame($before['concepts'] + 1, $after['concepts'], 'El lote tiene que crear UN solo concepto (el del grupo bilingüe).');
        $this->assertSame($before['term_concepts'] + 3, $after['term_concepts'], 'Un TERM→CONCEPT de la MAP_TO_EXISTING + dos del grupo.');
        $this->assertSame($before['term_cpv'], $after['term_cpv'], 'INVARIANTE: cero escrituras TÉRMINO→CPV.');
        $this->assertSame($before['candidates_published'] + 3, $after['candidates_published']);
        $this->assertSame($before['relations_approved'], $after['relations_approved'], 'Un REJECT no publica ninguna relación.');
        $this->assertSame($before['proposals_aborted'], $after['proposals_aborted'], 'Un lote exitoso no aborta nada.');

        // Y el estado final coincide con la proyección: cada decisión en su destino.
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_CONTEXT_REQUIRED, $scenario['candidates']['ctx_a']->fresh()->status);
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_CONTEXT_REQUIRED, $scenario['candidates']['ctx_b']->fresh()->status);
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PUBLISHED, $scenario['candidates']['map']->fresh()->status);
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PUBLISHED, $scenario['candidates']['group_en']->fresh()->status);
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PUBLISHED, $scenario['candidates']['group_es']->fresh()->status);
        $this->assertSame(TaxonomyConceptRelation::STATUS_REJECTED, $scenario['relation']->fresh()->status);
    }

    #[Test]
    public function the_batch_preflight_predicts_exactly_what_the_batch_then_writes(): void
    {
        $scenario = $this->representativeBatch();

        $writes = [];
        $report = $this->captureWrites(
            fn () => (new ReviewedProposalService())->previewBatch($scenario['ids']),
            $writes,
        );

        $this->assertSame([], $writes, 'El preflight de lote emitió escrituras.');
        $this->assertSame(0, $report['write_statements_observed']);
        $this->assertNull($report['blocker']);
        $this->assertSame(5, $report['execution_unit_count']);
        $this->assertSame($scenario['ids'], $report['accepted_proposal_ids']);

        $projected = $report['projected_protected_counts']['counts'];

        $result = (new ReviewedProposalService())->applyBatch($scenario['ids'], self::AUTH_REFERENCE);
        $this->assertSame(ReviewedProposalService::BATCH_RESULT_APPLIED, $result['result']);

        // La proyección no es una estimación amable: tiene que coincidir con el estado real posterior.
        $actual = ReviewedProposalService::protectedQueueShape();
        foreach (['canonical_concepts', 'term_concepts', 'term_cpv_relations'] as $key) {
            $this->assertSame($projected[$key], $actual[$key], "La proyección de {$key} no coincidió con el resultado real del lote.");
        }
    }

    #[Test]
    public function the_batch_validates_every_unit_against_the_single_baseline_it_computed_once(): void
    {
        $scenario = $this->representativeBatch();
        $baseline = CanonicalConceptBuilderService::dryRunInputFingerprint();

        $report = (new ReviewedProposalService())->previewBatch($scenario['ids']);

        $this->assertSame($baseline, $report['baseline_taxonomy_fingerprint']);
        $this->assertSame($baseline, $report['current_taxonomy_fingerprint']);

        foreach ($report['execution_units'] as $unit) {
            $this->assertSame($baseline, $unit['taxonomy_state_fingerprint_current'],
                'Una unidad se validó contra un fingerprint distinto del baseline del lote.');
            $this->assertFalse($unit['stale']);
        }
    }

    // =========================================================================================
    // B. POR QUÉ EL LOOP DE apply() SUELTOS NO ES UNA ESTRATEGIA VÁLIDA
    // =========================================================================================

    #[Test]
    public function sequential_single_applies_stale_the_later_proposal_after_the_first_graph_change(): void
    {
        // ESTE es el test que documenta la razón de ser de TASK-0007. Dos propuestas congeladas contra
        // el MISMO snapshot: una que cambia el grafo (MAP_TO_EXISTING inserta un TERM→CONCEPT) y una
        // que no. Aplicadas una por una, la segunda queda ABORTADA de forma TERMINAL - y re-congelar
        // está bloqueado por el índice único parcial, así que la decisión humana se perdería.
        $user = $this->authorizedUser();
        $concept = $this->concept();
        $mapCandidate = $this->pendingCandidate('en', $concept->id);
        $laterCandidate = $this->pendingCandidate('es');

        $map = $this->freezeMapToExisting($mapCandidate, $concept->id, $user);
        $later = $this->freezeContextRequired($laterCandidate, $user);

        $this->assertSame($map->taxonomy_state_fingerprint, $later->taxonomy_state_fingerprint,
            'Precondición: las dos se revisaron contra el mismo estado.');

        $service = new ReviewedProposalService();

        $first = $service->apply($map->id, self::AUTH_REFERENCE);
        $this->assertSame(ReviewedProposalService::RESULT_APPLIED, $first['result']);

        $second = $service->apply($later->id, self::AUTH_REFERENCE);

        $this->assertSame(ReviewedProposalService::RESULT_ABORTED, $second['result'],
            'Si esto dejara de abortar, el hueco semántico que TASK-0007 vino a cerrar habría cambiado y este diseño necesitaría revisarse.');
        $this->assertSame(ReviewedProposalService::ABORT_STALE_TAXONOMY_STATE, $second['abort_reason']);
        $this->assertSame(TaxonomyReviewedProposal::STATUS_ABORTED, $later->fresh()->status);

        // Y el candidato de la decisión quemada quedó sin resolver: la decisión humana se perdió.
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $laterCandidate->fresh()->status);
    }

    #[Test]
    public function the_same_pair_commits_with_no_abort_when_executed_as_one_batch(): void
    {
        // La contraparte exacta del test anterior, con el mismo escenario: lo que el loop quemaba, el
        // lote lo ejecuta completo.
        $user = $this->authorizedUser();
        $concept = $this->concept();
        $mapCandidate = $this->pendingCandidate('en', $concept->id);
        $laterCandidate = $this->pendingCandidate('es');

        $map = $this->freezeMapToExisting($mapCandidate, $concept->id, $user);
        $later = $this->freezeContextRequired($laterCandidate, $user);

        $result = (new ReviewedProposalService())->applyBatch([$later->id, $map->id], self::AUTH_REFERENCE);

        $this->assertSame(ReviewedProposalService::BATCH_RESULT_APPLIED, $result['result']);
        $this->assertSame(TaxonomyReviewedProposal::STATUS_APPLIED, $map->fresh()->status);
        $this->assertSame(TaxonomyReviewedProposal::STATUS_APPLIED, $later->fresh()->status);
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_CONTEXT_REQUIRED, $laterCandidate->fresh()->status);
    }

    // =========================================================================================
    // C. BLOQUEOS CON CERO ESCRITURAS
    // =========================================================================================

    #[Test]
    public function a_tampered_payload_blocks_the_whole_batch_with_zero_writes(): void
    {
        $scenario = $this->representativeBatch();
        $victim = $scenario['proposals']['ctx_a'];

        $payload = $victim->decision_payload;
        $payload['context_reason'] = 'inyectado por fuera del servicio '.uniqid();
        DB::connection('pgsql')->table('taxonomy_reviewed_proposals')
            ->where('id', $victim->id)
            ->update(['decision_payload' => json_encode($payload)]);

        $result = $this->assertBatchBlockedWithoutAnyWrite($scenario['ids'], ReviewedProposalService::BATCH_TAMPER_DETECTED);

        $this->assertSame((int) $victim->id, $result['detail']['blocking_proposal_id'],
            'El bloqueo tiene que nombrar la propuesta exacta que lo causa.');
        $this->assertSame(ReviewedProposalService::PREFLIGHT_TAMPER_DETECTED, $result['detail']['proposal_blocker']);
    }

    #[Test]
    public function a_stale_baseline_blocks_the_whole_batch_with_zero_writes(): void
    {
        $scenario = $this->representativeBatch();

        // Alguien publica un concepto DESPUÉS de congelar: el baseline del lote ya no describe la
        // realidad, y ninguna propuesta del conjunto es ejecutable contra el estado actual.
        $this->concept('zzz_batch_stale_trigger_'.uniqid());

        $this->assertBatchBlockedWithoutAnyWrite($scenario['ids'], ReviewedProposalService::BATCH_BASELINE_STALE);
    }

    #[Test]
    public function source_drift_blocks_the_whole_batch_with_zero_writes(): void
    {
        $scenario = $this->representativeBatch();

        // El candidato cambia de término después de congelar: el payload ya no describe lo que un
        // humano revisó. Se re-alinean los fingerprints para AISLAR esta compuerta de la de
        // obsolescencia (crear el término nuevo cambia el fingerprint global).
        $newTerm = $this->term('es');
        DB::connection('pgsql')->table('taxonomy_candidate_concept_links')
            ->where('id', $scenario['candidates']['ctx_b']->id)
            ->update(['suggested_term_id' => $newTerm->id]);
        $this->realignFingerprints($scenario['ids']);

        $result = $this->assertBatchBlockedWithoutAnyWrite($scenario['ids'], ReviewedProposalService::BATCH_SOURCE_DRIFT);

        $this->assertSame((int) $scenario['proposals']['ctx_b']->id, $result['detail']['blocking_proposal_id']);
    }

    #[Test]
    public function a_missing_human_confirmation_blocks_the_whole_batch_with_zero_writes(): void
    {
        $user = $this->authorizedUser();
        $candidateA = $this->pendingCandidate('es');
        $candidateB = $this->pendingCandidate('en');

        $healthy = $this->freezeContextRequired($candidateA, $user);
        // Preparada por el AGENTE: exige confirmación humana explícita antes de poder aplicarse.
        $needsConfirmation = $this->freezeContextRequired($candidateB, $user, TaxonomyReviewedProposal::ACTOR_AGENT);

        $this->assertTrue($needsConfirmation->fresh()->requires_human_confirmation);

        $result = $this->assertBatchBlockedWithoutAnyWrite(
            [(int) $healthy->id, (int) $needsConfirmation->id],
            ReviewedProposalService::BATCH_CONFIRMATION_REQUIRED,
        );

        $this->assertSame([(int) $needsConfirmation->id], $result['detail']['unconfirmed_proposal_ids']);
        // Y NO es un abort: la propuesta sigue aplicable una vez confirmada.
        $this->assertSame(TaxonomyReviewedProposal::STATUS_PENDING_APPLY, $needsConfirmation->fresh()->status);
    }

    #[Test]
    public function an_invalid_relation_blocks_the_whole_batch_with_zero_writes(): void
    {
        $user = $this->authorizedUser();
        $relation = $this->candidateRelation();
        $proposal = $this->freezeRelationDecision($relation, TaxonomyReviewedProposal::DECISION_PUBLISH_RELATION, $user);

        // Mientras esta esperaba, alguien aprobó la relación SIMÉTRICA inversa: publicar ahora sería
        // un duplicado por simetría. Se inserta por query builder para esquivar el guard de modelo
        // (que es justamente lo que la re-validación server-side tiene que detectar igual).
        DB::connection('pgsql')->table('taxonomy_concept_relations')->insert([
            'source_concept_id' => $relation->target_concept_id,
            'target_concept_id' => $relation->source_concept_id,
            'relation_type' => 'RELATED_TO',
            'weight' => 0.5, 'confidence' => 0.5,
            'status' => TaxonomyConceptRelation::STATUS_APPROVED,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->realignFingerprints([(int) $proposal->id]);

        $result = $this->assertBatchBlockedWithoutAnyWrite([(int) $proposal->id], ReviewedProposalService::BATCH_RELATION_INVALID);

        $this->assertSame((int) $proposal->id, $result['detail']['blocking_proposal_id']);
        $this->assertFalse($result['detail']['relation_validation']['valid']);
    }

    #[Test]
    public function queue_drift_blocks_the_batch_with_zero_writes(): void
    {
        $user = $this->authorizedUser();
        $proposal = $this->freezeContextRequired($this->pendingCandidate(), $user);

        // Un id que no existe: la cola pedida no es la cola real.
        $result = $this->assertBatchBlockedWithoutAnyWrite([(int) $proposal->id, 999999999], ReviewedProposalService::BATCH_QUEUE_DRIFT);
        $this->assertSame([999999999], $result['detail']['missing_proposal_ids']);

        // Y una propuesta que ya salió de PENDING_APPLY tampoco es una entrada ejecutable.
        $applied = (new ReviewedProposalService())->apply($proposal->id, self::AUTH_REFERENCE);
        $this->assertSame(ReviewedProposalService::RESULT_APPLIED, $applied['result']);

        $other = $this->freezeContextRequired($this->pendingCandidate(), $user);
        $this->realignFingerprints([(int) $other->id]);

        $mixed = $this->assertBatchBlockedWithoutAnyWrite([(int) $proposal->id, (int) $other->id], ReviewedProposalService::BATCH_QUEUE_DRIFT);
        $this->assertSame(
            [['proposal_id' => (int) $proposal->id, 'status' => TaxonomyReviewedProposal::STATUS_APPLIED]],
            $mixed['detail']['non_executable_proposals'],
        );
    }

    #[Test]
    public function an_empty_request_is_a_blocker_and_not_a_successful_batch(): void
    {
        $result = (new ReviewedProposalService())->applyBatch([], self::AUTH_REFERENCE);

        $this->assertSame(ReviewedProposalService::BATCH_RESULT_BLOCKED, $result['result']);
        $this->assertSame(ReviewedProposalService::BATCH_EMPTY_REQUEST, $result['blocker']);
    }

    // =========================================================================================
    // D. GRUPO BILINGÜE
    // =========================================================================================

    #[Test]
    public function the_group_executes_once_creating_exactly_one_concept_for_both_members(): void
    {
        $scenario = $this->representativeBatch();
        $conceptsBefore = DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count();

        $result = (new ReviewedProposalService())->applyBatch($scenario['ids'], self::AUTH_REFERENCE);
        $this->assertSame(ReviewedProposalService::BATCH_RESULT_APPLIED, $result['result']);

        $groupUnits = array_values(array_filter($result['units'], fn (array $unit) => $unit['kind'] === 'BILINGUAL_GROUP'));
        $this->assertCount(1, $groupUnits, 'El grupo tiene que aparecer como UNA sola unidad de ejecución, nunca dos.');
        $this->assertCount(2, $groupUnits[0]['proposal_ids']);

        $this->assertSame($conceptsBefore + 1, DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count());

        // Los dos miembros APPLIED, con el MISMO concepto, y dos TERM→CONCEPT contra él.
        $conceptId = $groupUnits[0]['application_result']['concept_id'];
        foreach ($scenario['proposals']['group'] as $member) {
            $fresh = $member->fresh();
            $this->assertSame(TaxonomyReviewedProposal::STATUS_APPLIED, $fresh->status);
            $this->assertSame($conceptId, $fresh->application_result['concept_id']);
        }
        $this->assertSame(2, DB::connection('pgsql')->table('taxonomy_term_concepts')->where('concept_id', $conceptId)->count());
    }

    #[Test]
    public function a_partial_group_request_is_rejected_read_only(): void
    {
        $scenario = $this->representativeBatch();
        $entry = $scenario['proposals']['group'][0];
        $sibling = $scenario['proposals']['group'][1];

        $result = $this->assertBatchBlockedWithoutAnyWrite([(int) $entry->id], ReviewedProposalService::BATCH_GROUP_INCOMPLETE);

        $this->assertSame($scenario['group_id'], $result['detail']['proposal_group_id']);
        $this->assertSame([(int) $sibling->id], $result['detail']['absent_from_batch_proposal_ids']);
    }

    #[Test]
    public function a_tampered_sibling_blocks_the_entire_batch_with_zero_writes(): void
    {
        $scenario = $this->representativeBatch();
        $sibling = $scenario['proposals']['group'][1];

        $payload = $sibling->decision_payload;
        $payload['canonical_name_es'] = 'inyectado en el hermano '.uniqid();
        DB::connection('pgsql')->table('taxonomy_reviewed_proposals')
            ->where('id', $sibling->id)
            ->update(['decision_payload' => json_encode($payload)]);

        $result = $this->assertBatchBlockedWithoutAnyWrite($scenario['ids'], ReviewedProposalService::BATCH_TAMPER_DETECTED);

        // El hermano manipulado, no la entrada del grupo: la atribución tiene que ser exacta.
        $this->assertSame((int) $sibling->id, $result['detail']['blocking_proposal_id']);
        $this->assertSame((int) $scenario['proposals']['group'][0]->id, $result['detail']['blocking_unit_entry_proposal_id']);
    }

    // =========================================================================================
    // E. CONCURRENCIA E IDEMPOTENCIA
    // =========================================================================================

    #[Test]
    public function batch_and_single_apply_serialise_on_the_same_execution_lock_key(): void
    {
        $key = ReviewedProposalService::executionAdvisoryLockKey();

        $this->assertSame($key, ReviewedProposalService::executionAdvisoryLockKey(), 'La clave tiene que ser estable entre llamadas.');
        $this->assertGreaterThan(0, $key);
        $this->assertLessThan(PHP_INT_MAX, $key);
        $this->assertNotSame($key, ReviewedProposalService::groupAdvisoryLockKey((string) \Illuminate\Support\Str::uuid()),
            'El lock de ejecución no puede colisionar con el de un grupo: el orden ejecución->grupo exige dos locks distintos.');
    }

    #[Test]
    public function the_execution_lock_is_genuinely_mutually_exclusive_across_connections(): void
    {
        $key = ReviewedProposalService::executionAdvisoryLockKey();
        $otherKey = ReviewedProposalService::groupAdvisoryLockKey((string) \Illuminate\Support\Str::uuid());

        DB::connection('pgsql')->statement('SELECT pg_advisory_xact_lock(?)', [$key]);

        config(['database.connections.pgsql_batch_lock_probe' => config('database.connections.pgsql')]);
        $probe = DB::connection('pgsql_batch_lock_probe');

        $busy = $probe->selectOne('SELECT pg_try_advisory_lock(?) AS got', [$key]);
        $this->assertFalse($this->toBool($busy->got),
            'Mientras una transacción tiene el lock de ejecución, ninguna otra conexión puede tomarlo: es lo que impide que un apply suelto se intercale en un lote.');

        $free = $probe->selectOne('SELECT pg_try_advisory_lock(?) AS got', [$otherKey]);
        $this->assertTrue($this->toBool($free->got), 'Si otra clave tampoco estuviera libre, la sonda no mediría lo que se cree.');
        $probe->statement('SELECT pg_advisory_unlock(?)', [$otherKey]);
    }

    #[Test]
    public function both_execution_paths_really_hold_the_common_lock_while_they_write(): void
    {
        $this->assertFalse(ReviewedProposalService::holdsExecutionAdvisoryLock(), 'Precondición: el lock no está tomado.');

        $user = $this->authorizedUser();
        $single = $this->freezeContextRequired($this->pendingCandidate(), $user);

        // El advisory lock es de TRANSACCIÓN y la de `apply()` es un SAVEPOINT dentro de la del test,
        // así que sigue tomado acá: se puede consultar en `pg_locks` en vez de confiar en el código.
        (new ReviewedProposalService())->apply($single->id, self::AUTH_REFERENCE);
        $this->assertTrue(ReviewedProposalService::holdsExecutionAdvisoryLock(), 'apply() no tomó el lock común de ejecución.');
    }

    #[Test]
    public function the_batch_holds_the_common_execution_lock_too(): void
    {
        $this->assertFalse(ReviewedProposalService::holdsExecutionAdvisoryLock());

        $user = $this->authorizedUser();
        $proposal = $this->freezeContextRequired($this->pendingCandidate(), $user);

        (new ReviewedProposalService())->applyBatch([(int) $proposal->id], self::AUTH_REFERENCE);

        $this->assertTrue(ReviewedProposalService::holdsExecutionAdvisoryLock(), 'applyBatch() no tomó el lock común de ejecución.');
    }

    #[Test]
    public function the_lock_order_is_execution_then_group_then_rows_in_both_paths(): void
    {
        // Prueba ESTRUCTURAL del orden, que es lo que elimina la inversión: si alguien reordenara los
        // locks, este test falla antes de que aparezca un deadlock en producción.
        $reflection = new \ReflectionClass(ReviewedProposalService::class);
        $source = file($reflection->getFileName());

        foreach (['apply', 'applyBatch'] as $methodName) {
            $method = $reflection->getMethod($methodName);
            $body = implode('', array_slice($source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

            $execution = strpos($body, 'acquireExecutionAdvisoryLock()');
            $group = strpos($body, 'acquireGroupAdvisoryLock(');
            $rowLock = strpos($body, 'lockForUpdate(');

            $this->assertNotFalse($execution, "{$methodName}() no toma el lock común de ejecución.");
            $this->assertNotFalse($group, "{$methodName}() no toma el advisory lock de grupo.");

            $this->assertLessThan($group, $execution, "{$methodName}() toma el lock de grupo ANTES que el de ejecución: el orden quedó invertido.");
            if ($rowLock !== false) {
                $this->assertLessThan($rowLock, $execution, "{$methodName}() bloquea filas antes de tomar el lock de ejecución.");
            }
        }
    }

    #[Test]
    public function replaying_a_fully_executed_batch_reports_already_executed_and_writes_nothing(): void
    {
        $scenario = $this->representativeBatch();

        $first = (new ReviewedProposalService())->applyBatch($scenario['ids'], self::AUTH_REFERENCE);
        $this->assertSame(ReviewedProposalService::BATCH_RESULT_APPLIED, $first['result']);

        $before = $this->counts();
        $writes = [];
        $second = $this->captureWrites(
            fn () => (new ReviewedProposalService())->applyBatch($scenario['ids'], self::AUTH_REFERENCE),
            $writes,
        );

        $this->assertSame([], $writes, 'El replay del lote escribió algo: la idempotencia no es real.');
        $this->assertSame(ReviewedProposalService::BATCH_RESULT_ALREADY_EXECUTED, $second['result']);
        $this->assertSame(ReviewedProposalService::BATCH_ALREADY_EXECUTED, $second['blocker']);
        $this->assertSame($before, $this->counts(), 'El replay duplicó conceptos, links o filas de auditoría.');
    }

    // =========================================================================================
    // F. INVARIANTES
    // =========================================================================================

    #[Test]
    public function no_statement_of_a_successful_batch_touches_term_cpv_relations(): void
    {
        $scenario = $this->representativeBatch();

        $writes = [];
        $this->captureWrites(
            fn () => (new ReviewedProposalService())->applyBatch($scenario['ids'], self::AUTH_REFERENCE),
            $writes,
        );

        $this->assertNotSame([], $writes, 'Un lote exitoso SÍ escribe: si no hubiera statements, este test no estaría midiendo nada.');
        foreach ($writes as $sql) {
            $this->assertStringNotContainsString('taxonomy_term_cpv_relations', $sql,
                'INVARIANTE: ninguna escritura del lote puede tocar las relaciones TÉRMINO→CPV.');
        }
    }

    #[Test]
    public function a_superseded_proposal_is_not_an_executable_batch_input_and_stays_byte_identical(): void
    {
        $user = $this->authorizedUser();
        $candidate = $this->pendingCandidate();
        $proposal = $this->freezeContextRequired($candidate, $user);

        // Se la vuelve obsoleta y se la supersede: el equivalente en fixture de #420/#421/#422.
        $this->concept('zzz_batch_supersede_trigger_'.uniqid());
        $superseded = (new ReviewedProposalService())->supersedeStaleProposal(
            (int) $proposal->id,
            'TASK-0007 fixture 5997693379',
            'zzz_batch fixture: retirada por obsolescencia para probar que no es una entrada ejecutable.',
        );
        $this->assertSame(ReviewedProposalService::RESULT_SUPERSEDED, $superseded['result']);

        $rowBefore = (array) DB::connection('pgsql')->table('taxonomy_reviewed_proposals')->where('id', $proposal->id)->first();

        $result = $this->assertBatchBlockedWithoutAnyWrite([(int) $proposal->id], ReviewedProposalService::BATCH_QUEUE_DRIFT);

        $this->assertSame(
            [['proposal_id' => (int) $proposal->id, 'status' => TaxonomyReviewedProposal::STATUS_SUPERSEDED]],
            $result['detail']['non_executable_proposals'],
        );
        $this->assertSame($rowBefore, (array) DB::connection('pgsql')->table('taxonomy_reviewed_proposals')->where('id', $proposal->id)->first(),
            'El lote modificó una fila SUPERSEDED: el registro histórico tiene que quedar intacto.');
    }

    #[Test]
    public function the_partial_unique_pending_index_still_blocks_a_second_freeze_of_the_same_candidate(): void
    {
        $user = $this->authorizedUser();
        $candidate = $this->pendingCandidate();
        $this->freezeContextRequired($candidate, $user);

        $second = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED,
            $user,
            ['context_reason' => 'segundo intento'],
        );

        $this->assertSame(ReviewedProposalService::RESULT_ALREADY_HAS_PENDING_PROPOSAL, $second['result'],
            'TASK-0007 no debe haber debilitado el índice único parcial WHERE status = PENDING_APPLY.');
    }

    #[Test]
    public function the_batch_refuses_an_environment_that_is_not_authorised_to_execute(): void
    {
        $user = $this->authorizedUser();
        $proposal = $this->freezeContextRequired($this->pendingCandidate(), $user);

        app()->detectEnvironment(fn () => 'production');

        try {
            $result = $this->assertBatchBlockedWithoutAnyWrite([(int) $proposal->id], ReviewedProposalService::BATCH_ENVIRONMENT_NOT_AUTHORIZED);
            $this->assertSame('production', $result['detail']['target_environment']);
            $this->assertNotContains('production', ReviewedProposalService::BATCH_EXECUTABLE_ENVIRONMENTS);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    // =========================================================================================
    // RE-AUDIT `6011317053` — BLOQUEO 2: `local` lee datos compartidos REALES, así que no puede
    // ejecutar. Sólo `staging` es objetivo operativo; `testing` es la excepción de los tests.
    // =========================================================================================

    #[Test]
    public function local_can_preview_but_cannot_execute_a_batch(): void
    {
        // EL DEFECTO QUE CIERRA: la lista anterior era ['local','testing','staging'], y los propios
        // artefactos de la ronda 1 prueban que el APP_ENV=local de esta estación está conectado al
        // dataset compartido REAL (se generaron con generated_in_environment=local leyendo la cola real
        // de 12 filas). Con esa lista, --execute --expect-environment=local habría podido ejecutar
        // datos reales desde una máquina de desarrollo.
        $user = $this->authorizedUser();
        $proposal = $this->freezeContextRequired($this->pendingCandidate(), $user);

        app()->detectEnvironment(fn () => 'local');

        try {
            $this->assertFalse(ReviewedProposalService::environmentCanExecuteBatch('local'));

            $result = $this->assertBatchBlockedWithoutAnyWrite([(int) $proposal->id], ReviewedProposalService::BATCH_ENVIRONMENT_NOT_AUTHORIZED);
            $this->assertSame('local', $result['detail']['target_environment']);
            $this->assertSame(['staging'], $result['detail']['executable_environments']);

            // Pero el PREVIEW sigue siendo plenamente posible en `local`: es de solo lectura y es
            // donde tiene sentido correrlo. Que el preflight quede inutilizable no sería una
            // corrección, sería otro defecto.
            $writes = [];
            $report = $this->captureWrites(fn () => (new ReviewedProposalService())->previewBatch([(int) $proposal->id]), $writes);

            $this->assertSame([], $writes);
            $this->assertNull($report['blocker'], 'El preview tiene que seguir funcionando en local.');
            $this->assertFalse($report['environment_authorized_for_execution'], 'Y tiene que DECIR que este entorno no puede ejecutar.');
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    #[Test]
    public function staging_passes_the_environment_gate_and_testing_is_only_the_fixture_exception(): void
    {
        $this->assertTrue(ReviewedProposalService::environmentCanExecuteBatch('staging'),
            'staging es el único entorno OPERATIVO autorizado para ejecutar.');
        $this->assertTrue(ReviewedProposalService::environmentCanExecuteBatch('testing'),
            'testing pasa SOLO como excepción para tests automatizados, no como objetivo operativo.');
        $this->assertFalse(ReviewedProposalService::environmentCanExecuteBatch('local'));
        $this->assertFalse(ReviewedProposalService::environmentCanExecuteBatch('production'));

        // Y la separación es estructural, no un comentario: la lista operativa tiene UN elemento y la
        // excepción de tests vive en otra constante. Si alguien volviera a meter `local` o `testing`
        // en la lista operativa, este test falla.
        $this->assertSame(['staging'], ReviewedProposalService::BATCH_EXECUTABLE_ENVIRONMENTS);
        $this->assertSame('testing', ReviewedProposalService::BATCH_FIXTURE_TEST_ENVIRONMENT);

        // La compuerta pasa de verdad con el entorno en `staging`: fixture aislado dentro de
        // DatabaseTransactions, nada real y nada commiteado.
        $user = $this->authorizedUser();
        $proposal = $this->freezeContextRequired($this->pendingCandidate(), $user);

        app()->detectEnvironment(fn () => 'staging');

        try {
            $result = (new ReviewedProposalService())->applyBatch([(int) $proposal->id], self::AUTH_REFERENCE);

            $this->assertSame(ReviewedProposalService::BATCH_RESULT_APPLIED, $result['result'],
                'Con el entorno en staging la compuerta de entorno no debe bloquear.');
            $this->assertSame('staging', $result['target_environment']);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    // =========================================================================================
    // RE-AUDIT `6011317053` — BLOQUEO 1: un manifiesto no se puede ejecutar por partes
    // =========================================================================================

    #[Test]
    public function a_strict_subset_of_a_full_manifest_is_refused_with_zero_writes(): void
    {
        // EL DEFECTO QUE CIERRA, aceptado sin reservas: el manifiesto verificaba que todas SUS
        // propuestas siguieran vivas, pero nadie comprobaba que el conjunto a EJECUTAR fuera ese mismo.
        // Así, `--manifest=<FULL aprobado> --id=491` verificaba con éxito y ejecutaba sólo #491.
        $scenario = $this->representativeBatch();
        $manifest = ReviewedProposalBatchManifest::generate($scenario['ids']);

        $subset = [$scenario['ids'][0]];

        $result = $this->assertBatchBlockedWithoutAnyWrite($subset, ReviewedProposalService::BATCH_MANIFEST_REQUEST_MISMATCH, $manifest, $manifest['manifest_fingerprint']);

        $this->assertSame($scenario['ids'], $result['detail']['manifest_bound_proposal_ids']);
        $this->assertSame($subset, $result['detail']['requested_proposal_ids']);
        $this->assertSame(array_values(array_diff($scenario['ids'], $subset)), $result['detail']['bound_but_not_requested']);
        $this->assertSame([], $result['detail']['requested_but_not_bound']);
    }

    #[Test]
    public function a_further_subset_of_a_subset_manifest_is_refused_too(): void
    {
        // El alcance EXPLICIT_IDS significa que el MANIFIESTO ata un subconjunto a propósito; nunca
        // habilita tomar un subconjunto arbitrario de un manifiesto ya atado.
        $scenario = $this->representativeBatch();
        $boundSubset = array_slice($scenario['ids'], 0, 3);
        $manifest = ReviewedProposalBatchManifest::generate($boundSubset);

        $this->assertSame(ReviewedProposalBatchManifest::SCOPE_EXPLICIT_IDS, $manifest['scope']);

        $result = $this->assertBatchBlockedWithoutAnyWrite(array_slice($boundSubset, 0, 2), ReviewedProposalService::BATCH_MANIFEST_REQUEST_MISMATCH, $manifest, $manifest['manifest_fingerprint']);

        $this->assertSame(ReviewedProposalBatchManifest::SCOPE_EXPLICIT_IDS, $result['detail']['scope']);
    }

    #[Test]
    public function extra_ids_beyond_the_manifest_are_refused_as_well(): void
    {
        // La igualdad es de CONJUNTOS, así que también bloquea el caso inverso: pedir MÁS de lo que el
        // manifiesto ata. Autorizar 6 no autoriza ejecutar 7.
        $scenario = $this->representativeBatch();
        $boundSubset = array_slice($scenario['ids'], 0, 5);
        $manifest = ReviewedProposalBatchManifest::generate($boundSubset);

        $result = $this->assertBatchBlockedWithoutAnyWrite($scenario['ids'], ReviewedProposalService::BATCH_MANIFEST_REQUEST_MISMATCH, $manifest, $manifest['manifest_fingerprint']);

        $this->assertSame(array_values(array_diff($scenario['ids'], $boundSubset)), $result['detail']['requested_but_not_bound']);
    }

    #[Test]
    public function the_same_set_in_a_different_order_or_with_duplicates_is_accepted(): void
    {
        // La compuerta compara conjuntos NORMALIZADOS: el orden de llegada y los duplicados no son
        // una diferencia de autorización, y tratarlos como tal rompería invocaciones legítimas.
        $scenario = $this->representativeBatch();
        $manifest = ReviewedProposalBatchManifest::generate($scenario['ids']);

        $shuffled = array_reverse($scenario['ids']);
        $withDuplicates = array_merge($shuffled, [$scenario['ids'][0], $scenario['ids'][0]]);

        $verification = ReviewedProposalBatchManifest::verify(
            $manifest,
            CanonicalConceptBuilderService::dryRunInputFingerprint(),
            $withDuplicates,
        );

        $this->assertTrue($verification['ok']);
        $this->assertTrue($verification['findings']['requested_set_equals_bound_set']);
        $this->assertSame($scenario['ids'], $verification['findings']['requested_proposal_ids']);

        // Y el lote entero se ejecuta con los ids desordenados y duplicados.
        $result = (new ReviewedProposalService())->applyBatch(
            $withDuplicates,
            self::AUTH_REFERENCE,
            $manifest,
            $manifest['manifest_fingerprint'],
        );

        $this->assertSame(ReviewedProposalService::BATCH_RESULT_APPLIED, $result['result']);
        $this->assertSame($scenario['ids'], $result['applied_proposal_ids']);
    }

    #[Test]
    public function an_edited_manifest_is_reported_as_tamper_and_not_as_a_request_mismatch(): void
    {
        // El ORDEN de las compuertas importa: a un manifiesto al que le editaron la lista de
        // propuestas se le tiene que reportar la integridad del archivo (hallazgo de seguridad), no un
        // desajuste de pedido - aunque las dos cosas sean ciertas a la vez.
        $scenario = $this->representativeBatch();
        $manifest = ReviewedProposalBatchManifest::generate($scenario['ids']);
        array_pop($manifest['proposals']);

        $verification = ReviewedProposalBatchManifest::verify(
            $manifest,
            CanonicalConceptBuilderService::dryRunInputFingerprint(),
            array_map(fn (array $row) => (int) $row['proposal_id'], $manifest['proposals']),
        );

        $this->assertSame(ReviewedProposalService::BATCH_TAMPER_DETECTED, $verification['blocker']);
    }

    // =========================================================================================
    // RE-AUDIT `6011317053` — BLOQUEO 3: la autorización atada al hash del manifiesto
    // =========================================================================================

    #[Test]
    public function the_batch_refuses_a_manifest_without_the_authorised_fingerprint(): void
    {
        // Un manifiesto auto-hasheado sólo prueba «este archivo no se editó». Para probar «este es el
        // hash que el dueño autorizó» hace falta un SEGUNDO insumo, citado aparte.
        $scenario = $this->representativeBatch();
        $manifest = ReviewedProposalBatchManifest::generate($scenario['ids']);

        $result = $this->assertBatchBlockedWithoutAnyWrite(
            $scenario['ids'],
            ReviewedProposalService::BATCH_AUTHORIZATION_FINGERPRINT_MISSING,
            $manifest,
        );

        $this->assertSame($manifest['manifest_fingerprint'], $result['detail']['manifest_fingerprint_in_file']);
    }

    #[Test]
    public function the_batch_refuses_a_malformed_authorised_fingerprint(): void
    {
        $scenario = $this->representativeBatch();
        $manifest = ReviewedProposalBatchManifest::generate($scenario['ids']);

        foreach (['no-es-un-hash', 'eb7d1467', str_repeat('z', 64)] as $malformed) {
            $before = $this->counts();
            $writes = [];

            $result = $this->captureWrites(
                fn () => (new ReviewedProposalService())->applyBatch($scenario['ids'], self::AUTH_REFERENCE, $manifest, $malformed),
                $writes,
            );

            $this->assertSame([], $writes);
            $this->assertSame(ReviewedProposalService::BATCH_AUTHORIZATION_FINGERPRINT_MALFORMED, $result['blocker'], "No rechazó el hash malformado `{$malformed}`.");
            $this->assertSame($before, $this->counts());
        }
    }

    #[Test]
    public function the_batch_refuses_a_valid_manifest_that_is_not_the_authorised_one(): void
    {
        // EL ESCENARIO DE FALLO EXACTO que el re-audit describe: el dueño autoriza el hash A; después
        // se genera un manifiesto B internamente válido; el operador corre B citando la autorización
        // de A. Sin esta compuerta, si B coincide con el estado vivo nada lo detecta.
        $scenario = $this->representativeBatch();
        $manifestB = ReviewedProposalBatchManifest::generate($scenario['ids']);
        $authorizedHashA = hash('sha256', 'un manifiesto distinto que el dueño autorizó antes');

        $result = $this->assertBatchBlockedWithoutAnyWrite(
            $scenario['ids'],
            ReviewedProposalService::BATCH_AUTHORIZATION_FINGERPRINT_MISMATCH,
            $manifestB,
            $authorizedHashA,
        );

        $this->assertSame($authorizedHashA, $result['detail']['authorized_manifest_fingerprint']);
        $this->assertSame($manifestB['manifest_fingerprint'], $result['detail']['loaded_manifest_fingerprint']);
    }

    #[Test]
    public function the_batch_executes_when_the_authorised_fingerprint_matches_the_loaded_manifest(): void
    {
        $scenario = $this->representativeBatch();
        $manifest = ReviewedProposalBatchManifest::generate($scenario['ids']);

        // El hash se pasa como lo citaría una autorización humana: en mayúsculas y con espacios
        // alrededor, porque un copiado/pegado desde un comentario no debería romper una ejecución
        // legítima. Lo que NO se acepta es un hash distinto.
        $result = (new ReviewedProposalService())->applyBatch(
            $scenario['ids'],
            self::AUTH_REFERENCE,
            $manifest,
            '  '.strtoupper($manifest['manifest_fingerprint']).'  ',
        );

        $this->assertSame(ReviewedProposalService::BATCH_RESULT_APPLIED, $result['result']);
        $this->assertSame($scenario['ids'], $result['applied_proposal_ids']);
        $this->assertTrue($result['manifest_verification']['ok']);
    }

    #[Test]
    public function the_authorisation_fingerprint_is_never_inferred_from_the_manifest_file(): void
    {
        // Prueba ESTRUCTURAL de la regla «do NOT infer the expected hash from the same manifest file:
        // that would collapse the two trust inputs back into one». Si alguien "arreglara" la compuerta
        // rellenando el hash esperado desde el manifiesto, los dos insumos volverían a ser uno y este
        // test falla.
        $reflection = new \ReflectionClass(ReviewedProposalService::class);
        $source = file($reflection->getFileName());
        $method = $reflection->getMethod('applyBatch');
        $body = implode('', array_slice($source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        $this->assertStringNotContainsString("\$expectedManifestFingerprint ??", $body,
            'El hash autorizado no puede tener un fallback que lo deduzca del manifiesto.');
        $this->assertStringNotContainsString("\$manifest['manifest_fingerprint']", $body,
            'applyBatch() no debe leer el hash del archivo para construir la expectativa: ahí es donde los dos insumos de confianza se colapsarían en uno.');

        // Y los dos insumos llegan como PARÁMETROS separados, no como un campo del mismo arreglo.
        $parameters = array_map(fn (\ReflectionParameter $p) => $p->getName(), $method->getParameters());
        $this->assertSame(['proposalIds', 'authorizationReference', 'manifest', 'expectedManifestFingerprint'], $parameters);

        // Y la compuerta se evalúa ANTES de la transacción: un hash que no es el autorizado no debe
        // ni abrir una transacción, mucho menos tomar locks.
        $gatePosition = strpos($body, 'authorizationFingerprintBlocker(');
        $transactionPosition = strpos($body, '->transaction(');

        $this->assertNotFalse($gatePosition);
        $this->assertNotFalse($transactionPosition);
        $this->assertLessThan($transactionPosition, $gatePosition,
            'La compuerta de autorización tiene que correr antes de abrir la transacción.');
    }

    // =========================================================================================
    // RE-AUDIT `6011317053` — nivel COMANDO: `--id` no puede debilitar un manifiesto
    // =========================================================================================

    /**
     * Escribe un manifiesto de FIXTURE a un archivo temporal y devuelve su ruta.
     *
     * Los tests de comando NO usan el manifiesto real de `audit/` a propósito: ese ata las 12
     * propuestas reales, y si alguna compuerta del comando se debilitara, un test podría llegar a
     * ejecutar la cola real (aunque `DatabaseTransactions` la revirtiera). Con un manifiesto de
     * fixture, ningún camino de estos tests puede siquiera nombrar una propuesta real.
     */
    private function fixtureManifestFile(array $ids): array
    {
        $manifest = ReviewedProposalBatchManifest::generate($ids);
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'zzz_task0007_fixture_manifest_'.uniqid('', true).'.json';
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->temporaryManifestFiles[] = $path;

        return [$path, $manifest];
    }

    /** @var string[] */
    private array $temporaryManifestFiles = [];

    #[Test]
    public function the_command_refuses_id_together_with_execute(): void
    {
        // La opción PREFERIDA del orquestador: con `--execute`, `--id` no puede redefinir el
        // manifiesto en absoluto. Se comprueba al nivel del COMANDO porque es ahí donde el operador
        // tipea las dos opciones.
        $scenario = $this->representativeBatch();
        [$path, $manifest] = $this->fixtureManifestFile($scenario['ids']);
        $before = $this->counts();

        $this->artisan('taxonomy:apply-reviewed-proposal-batch', [
            '--manifest' => $path,
            '--id' => [(string) $scenario['ids'][0]],
            '--execute' => true,
            '--authorized-by' => 'Issue #2 test 6011317053',
            '--expect-manifest-fingerprint' => $manifest['manifest_fingerprint'],
            '--expect-environment' => 'testing',
            '--allow-subset-manifest' => true,
            '--force' => true,
        ])->assertFailed();

        $this->assertSame($before, $this->counts(), 'El rechazo de --id con --execute no puede escribir nada.');
    }

    #[Test]
    public function the_command_refuses_execute_without_the_authorised_fingerprint(): void
    {
        $scenario = $this->representativeBatch();
        [$path] = $this->fixtureManifestFile($scenario['ids']);
        $before = $this->counts();

        $this->artisan('taxonomy:apply-reviewed-proposal-batch', [
            '--manifest' => $path,
            '--execute' => true,
            '--authorized-by' => 'Issue #2 test 6011317053',
            '--expect-environment' => 'testing',
            '--allow-subset-manifest' => true,
            '--force' => true,
        ])->assertFailed();

        $this->assertSame($before, $this->counts());
    }

    #[Test]
    public function the_command_refuses_execute_outside_staging(): void
    {
        // El CLI acepta SÓLO entornos operativos, sin la excepción de `testing`: una persona corriendo
        // este comando en `testing` no es un test de fixture. Y `local` queda fuera porque lee datos
        // compartidos reales.
        $scenario = $this->representativeBatch();
        [$path, $manifest] = $this->fixtureManifestFile($scenario['ids']);
        $before = $this->counts();

        foreach (['testing', 'local'] as $environment) {
            app()->detectEnvironment(fn () => $environment);

            try {
                $this->artisan('taxonomy:apply-reviewed-proposal-batch', [
                    '--manifest' => $path,
                    '--execute' => true,
                    '--authorized-by' => 'Issue #2 test 6011317053',
                    '--expect-manifest-fingerprint' => $manifest['manifest_fingerprint'],
                    '--expect-environment' => $environment,
                    '--allow-subset-manifest' => true,
                    '--force' => true,
                ])->assertFailed();
            } finally {
                app()->detectEnvironment(fn () => 'testing');
            }
        }

        $this->assertSame($before, $this->counts(), 'Ningún rechazo de entorno puede escribir nada.');
    }

    // =========================================================================================
    // PARIDAD Y MANIFIESTO
    // =========================================================================================

    #[Test]
    public function every_proposal_blocker_declares_the_batch_blocker_it_maps_to(): void
    {
        $expected = [
            ReviewedProposalService::PREFLIGHT_TAMPER_DETECTED => ReviewedProposalService::BATCH_TAMPER_DETECTED,
            ReviewedProposalService::PREFLIGHT_STALE_TAXONOMY_STATE => ReviewedProposalService::BATCH_BASELINE_STALE,
            ReviewedProposalService::PREFLIGHT_ENTITY_MISSING => ReviewedProposalService::BATCH_ENTITY_MISSING,
            ReviewedProposalService::PREFLIGHT_SOURCE_DRIFT => ReviewedProposalService::BATCH_SOURCE_DRIFT,
            ReviewedProposalService::PREFLIGHT_SOURCE_ALREADY_RESOLVED => ReviewedProposalService::BATCH_SOURCE_ALREADY_RESOLVED,
            ReviewedProposalService::PREFLIGHT_HUMAN_CONFIRMATION_REQUIRED => ReviewedProposalService::BATCH_CONFIRMATION_REQUIRED,
            ReviewedProposalService::PREFLIGHT_GROUP_INCOMPLETE_OR_INCONSISTENT => ReviewedProposalService::BATCH_GROUP_INCOMPLETE,
            ReviewedProposalService::PREFLIGHT_RELATION_VALIDATION_FAILED => ReviewedProposalService::BATCH_RELATION_INVALID,
            ReviewedProposalService::PREFLIGHT_ALREADY_APPLIED => ReviewedProposalService::BATCH_QUEUE_DRIFT,
            ReviewedProposalService::PREFLIGHT_ALREADY_ABORTED => ReviewedProposalService::BATCH_QUEUE_DRIFT,
            ReviewedProposalService::PREFLIGHT_ALREADY_SUPERSEDED => ReviewedProposalService::BATCH_QUEUE_DRIFT,
            ReviewedProposalService::PREFLIGHT_NOT_FOUND => ReviewedProposalService::BATCH_QUEUE_DRIFT,
        ];

        foreach ($expected as $proposalBlocker => $batchBlocker) {
            $this->assertSame($batchBlocker, ReviewedProposalService::batchBlockerForProposalBlocker($proposalBlocker));
        }

        // Y la cobertura es COMPLETA: cada constante PREFLIGHT_* del servicio, salvo READY_TO_APPLY,
        // tiene traducción declarada. Así un bloqueo nuevo no puede quedarse sin mapear en silencio.
        $reflection = new \ReflectionClass(ReviewedProposalService::class);
        foreach ($reflection->getConstants() as $name => $value) {
            if (! str_starts_with($name, 'PREFLIGHT_') || $value === ReviewedProposalService::PREFLIGHT_READY_TO_APPLY) {
                continue;
            }

            $this->assertArrayHasKey($value, $expected, "El bloqueo {$name} no está cubierto por el test de paridad de lote.");
        }
    }

    #[Test]
    public function an_unmapped_blocker_throws_instead_of_inventing_a_batch_code(): void
    {
        $this->expectException(\LogicException::class);

        ReviewedProposalService::batchBlockerForProposalBlocker('BLOQUEO_INVENTADO');
    }

    #[Test]
    public function the_batch_never_aborts_a_proposal_by_any_path(): void
    {
        // Paridad estructural: `apply()` quema la propuesta cuando la validación falla; el lote NO
        // puede hacerlo, porque un lote bloqueado debe informar sin costo. Si alguien agregara un
        // `abort()` al camino del lote, este test falla.
        $reflection = new \ReflectionClass(ReviewedProposalService::class);
        $source = file($reflection->getFileName());

        foreach (['applyBatch', 'evaluateBatch', 'previewBatch'] as $methodName) {
            $method = $reflection->getMethod($methodName);
            $body = implode('', array_slice($source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

            $this->assertStringNotContainsString('$this->abort(', $body, "{$methodName}() aborta propuestas: un lote bloqueado no debe quemar nada.");
            $this->assertStringNotContainsString('abortWholeGroup(', $body, "{$methodName}() aborta un grupo: un lote bloqueado no debe quemar nada.");
        }

        // Y la validación del lote es la cadena COMPARTIDA, no un segundo motor de reglas.
        $batchBody = implode('', array_slice($source, $reflection->getMethod('evaluateBatch')->getStartLine() - 1, $reflection->getMethod('evaluateBatch')->getEndLine() - $reflection->getMethod('evaluateBatch')->getStartLine() + 1));
        $this->assertStringContainsString('$this->evaluateApplicability($entry, $lockRows, $baseline)', $batchBody);
        $this->assertStringNotContainsString('->apply(', $batchBody, 'El lote no puede implementarse como un loop alrededor de apply().');

        $applyBatchBody = implode('', array_slice($source, $reflection->getMethod('applyBatch')->getStartLine() - 1, $reflection->getMethod('applyBatch')->getEndLine() - $reflection->getMethod('applyBatch')->getStartLine() + 1));
        $this->assertStringNotContainsString('$this->apply(', $applyBatchBody, 'applyBatch() no puede ser un loop alrededor del apply() de una sola propuesta.');
    }

    #[Test]
    public function the_manifest_fingerprint_is_deterministic_and_binds_the_authorised_queue(): void
    {
        $scenario = $this->representativeBatch();

        $first = ReviewedProposalBatchManifest::generate($scenario['ids']);
        $second = ReviewedProposalBatchManifest::generate($scenario['ids']);

        $this->assertSame($first['manifest_fingerprint'], $second['manifest_fingerprint'],
            'Un fingerprint que cambia entre generaciones no se puede citar en una autorización.');
        $this->assertSame(6, count($first['proposals']));
        $this->assertSame(5, count($first['execution_units']));
        $this->assertSame($scenario['ids'], array_map(fn (array $row) => $row['proposal_id'], $first['proposals']));

        // El orden en que llegan los ids no cambia lo que se ata.
        $reversed = ReviewedProposalBatchManifest::generate(array_reverse($scenario['ids']));
        $this->assertSame($first['manifest_fingerprint'], $reversed['manifest_fingerprint']);

        $verification = ReviewedProposalBatchManifest::verify($first, CanonicalConceptBuilderService::dryRunInputFingerprint(), $scenario['ids']);
        $this->assertTrue($verification['ok']);
    }

    #[Test]
    public function an_edited_manifest_is_rejected_as_tampered(): void
    {
        $scenario = $this->representativeBatch();
        $manifest = ReviewedProposalBatchManifest::generate($scenario['ids']);

        // Se le quita una propuesta sin recomputar el hash: exactamente el ataque de "autoricé 12,
        // ejecuto 11".
        array_pop($manifest['proposals']);

        $verification = ReviewedProposalBatchManifest::verify($manifest, CanonicalConceptBuilderService::dryRunInputFingerprint(), $scenario['ids']);
        $this->assertFalse($verification['ok']);
        $this->assertSame(ReviewedProposalService::BATCH_TAMPER_DETECTED, $verification['blocker']);

        // El `manifest_fingerprint` declarado sigue siendo el original (se editó el contenido, no el
        // hash), así que la compuerta de autorización pasa y el hallazgo que queda es el correcto: la
        // integridad del archivo.
        $this->assertBatchBlockedWithoutAnyWrite($scenario['ids'], ReviewedProposalService::BATCH_TAMPER_DETECTED, $manifest, $manifest['manifest_fingerprint']);
    }

    #[Test]
    public function a_pending_proposal_outside_a_full_queue_manifest_is_rejected_as_drift(): void
    {
        // SOLO LECTURA, a propósito: este test necesita un manifiesto de alcance FULL_PENDING_QUEUE,
        // que por definición ata TODA la cola `PENDING_APPLY` viva - incluidas las propuestas reales.
        // Generar y verificar son dos operaciones de lectura, así que es seguro; lo que NO se hace es
        // pasarle este manifiesto a `applyBatch()`, porque ejecutar la cola real no está autorizado.
        $scenario = $this->representativeBatch();

        // El candidato se crea ANTES del manifiesto (crear cambia el fingerprint de baseline) y la
        // propuesta se congela DESPUÉS (congelar no lo cambia). Así el único hallazgo posible es el
        // que este test quiere medir: una PENDING_APPLY que el manifiesto no ata.
        $intruderCandidate = $this->pendingCandidate();
        $manifest = ReviewedProposalBatchManifest::generate();

        $this->assertSame(ReviewedProposalBatchManifest::SCOPE_FULL_PENDING_QUEUE, $manifest['scope']);

        $intruder = $this->freezeContextRequired($intruderCandidate, $scenario['user']);

        // Se verifica contra el conjunto que el propio manifiesto ata, así que la compuerta de
        // igualdad pasa y lo que este test mide es la que le interesa: la PENDING_APPLY de más.
        $verification = ReviewedProposalBatchManifest::verify(
            $manifest,
            CanonicalConceptBuilderService::dryRunInputFingerprint(),
            array_map(fn (array $row) => (int) $row['proposal_id'], $manifest['proposals']),
        );

        $this->assertFalse($verification['ok']);
        $this->assertSame(ReviewedProposalService::BATCH_QUEUE_DRIFT, $verification['blocker']);
        $this->assertContains((int) $intruder->id, $verification['detail']['unauthorised_pending_proposal_ids']);
    }

    #[Test]
    public function a_subset_manifest_does_not_treat_other_pending_proposals_as_drift(): void
    {
        // La contraparte del test anterior, y la razón por la que el alcance existe: un manifiesto de
        // subconjunto deja otras `PENDING_APPLY` sin ejecutar POR DEFINICIÓN, así que su existencia no
        // puede ser un hallazgo. El alcance va dentro del fingerprint, así que no se puede
        // re-etiquetar un subconjunto como cola completa.
        $scenario = $this->representativeBatch();
        $manifest = ReviewedProposalBatchManifest::generate($scenario['ids']);

        $this->assertSame(ReviewedProposalBatchManifest::SCOPE_EXPLICIT_IDS, $manifest['scope']);

        $verification = ReviewedProposalBatchManifest::verify($manifest, CanonicalConceptBuilderService::dryRunInputFingerprint(), $scenario['ids']);

        $this->assertTrue($verification['ok'], 'Un manifiesto de subconjunto no debe fallar por las PENDING_APPLY que deliberadamente no ata.');
        $this->assertNull($verification['findings']['no_unauthorised_pending_proposals'],
            'La compuerta no aplica a un subconjunto: null significa «no se evaluó», nunca «pasó».');

        $relabelled = array_merge($manifest, ['scope' => ReviewedProposalBatchManifest::SCOPE_FULL_PENDING_QUEUE]);
        $this->assertSame(
            ReviewedProposalService::BATCH_TAMPER_DETECTED,
            ReviewedProposalBatchManifest::verify($relabelled, CanonicalConceptBuilderService::dryRunInputFingerprint(), $scenario['ids'])['blocker'],
        );
    }

    #[Test]
    public function a_manifest_whose_baseline_moved_is_rejected_as_stale(): void
    {
        $scenario = $this->representativeBatch();
        $manifest = ReviewedProposalBatchManifest::generate($scenario['ids']);

        $this->concept('zzz_batch_manifest_stale_'.uniqid());

        $verification = ReviewedProposalBatchManifest::verify($manifest, CanonicalConceptBuilderService::dryRunInputFingerprint(), $scenario['ids']);
        $this->assertFalse($verification['ok']);
        $this->assertSame(ReviewedProposalService::BATCH_BASELINE_STALE, $verification['blocker']);
    }

    #[Test]
    public function the_batch_executes_the_exact_manifest_when_nothing_drifted(): void
    {
        $scenario = $this->representativeBatch();
        $manifest = ReviewedProposalBatchManifest::generate($scenario['ids']);

        $result = (new ReviewedProposalService())->applyBatch(
            $scenario['ids'],
            self::AUTH_REFERENCE,
            $manifest,
            $manifest['manifest_fingerprint'],
        );

        $this->assertSame(ReviewedProposalService::BATCH_RESULT_APPLIED, $result['result']);
        $this->assertSame($manifest['manifest_fingerprint'], $result['manifest_verification']['manifest_fingerprint']);
        $this->assertSame($manifest['manifest_fingerprint'], $result['expected_manifest_fingerprint']);
        $this->assertTrue($result['manifest_verification']['ok']);
        $this->assertTrue($result['manifest_verification']['findings']['requested_set_equals_bound_set']);
        $this->assertSame($scenario['ids'], $result['applied_proposal_ids']);
    }

    #[Test]
    public function the_unit_order_does_not_depend_on_the_order_the_ids_arrive(): void
    {
        $scenario = $this->representativeBatch();
        $service = new ReviewedProposalService();

        $forward = $service->previewBatch($scenario['ids']);
        $reversed = $service->previewBatch(array_reverse($scenario['ids']));

        $this->assertSame(
            array_column($forward['execution_units'], 'entry_proposal_id'),
            array_column($reversed['execution_units'], 'entry_proposal_id'),
            'El orden de las unidades tiene que ser determinístico: de él depende el orden de los locks.',
        );
        $this->assertSame($forward['accepted_proposal_ids'], $reversed['accepted_proposal_ids']);
    }

    /**
     * Re-alinea `taxonomy_state_fingerprint` y recomputa `payload_fingerprint` de las propuestas
     * dadas, para AISLAR una compuerta posterior a la de obsolescencia.
     *
     * Varios escenarios (drift de fuente, relación inválida) exigen cambiar el grafo o una fila
     * fuente, y ese cambio dispara la obsolescencia, que corre ANTES. Sin esto no se podría probar que
     * las compuertas posteriores existen de verdad. Mismo helper -y misma justificación- que
     * `ReviewedProposalPreflightTest`: no es un camino de producción, `freeze()` sigue siendo el único
     * que escribe esos dos campos.
     *
     * @param  int[]  $ids
     */
    private function realignFingerprints(array $ids): void
    {
        $current = CanonicalConceptBuilderService::dryRunInputFingerprint();

        foreach (TaxonomyReviewedProposal::query()->whereIn('id', $ids)->get() as $proposal) {
            DB::connection('pgsql')->table('taxonomy_reviewed_proposals')->where('id', $proposal->id)->update([
                'taxonomy_state_fingerprint' => $current,
                'payload_fingerprint' => ReviewedProposalService::computePayloadFingerprint([
                    'proposal_type' => $proposal->proposal_type,
                    'candidate_link_id' => $proposal->candidate_link_id,
                    'concept_relation_id' => $proposal->concept_relation_id,
                    'decision' => $proposal->decision,
                    'decision_payload' => $proposal->decision_payload ?? [],
                    'payload_version' => $proposal->payload_version,
                    'taxonomy_state_fingerprint' => $current,
                    'reviewer_id' => $proposal->reviewer_id,
                    'reviewed_at' => $proposal->reviewed_at->format('Y-m-d H:i:s'),
                ]),
            ]);
        }
    }

    /** PDO/pgsql puede devolver booleanos como `true`/`false` o como `'t'`/`'f'`. */
    private function toBool(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 1 || $value === '1';
    }
}
