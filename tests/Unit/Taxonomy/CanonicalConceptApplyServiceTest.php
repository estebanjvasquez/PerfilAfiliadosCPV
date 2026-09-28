<?php

namespace Tests\Unit\Taxonomy;

use App\Models\TaxonomyCandidateConceptLink;
use App\Models\TaxonomyCanonicalConcept;
use App\Models\TaxonomyConceptRelation;
use App\Models\TaxonomyTerm;
use App\Services\Taxonomy\CanonicalConceptApplyService;
use App\Services\Taxonomy\CanonicalConceptBuilderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase C: cada una de las 6 propiedades de seguridad de `--apply` tiene su propio test, más la
 * propiedad negativa que define la fase ("nunca publica en taxonomy_term_concepts").
 *
 * Igual que el resto de la suite de taxonomía, corre dentro de `DatabaseTransactions` sobre `pgsql`:
 * ningún candidato ni relación queda persistido de verdad al terminar.
 */
class CanonicalConceptApplyServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private function service(): CanonicalConceptApplyService
    {
        return app(CanonicalConceptApplyService::class);
    }

    private function term(): TaxonomyTerm
    {
        return TaxonomyTerm::create([
            'external_id' => 'apply-test-'.uniqid('', true),
            'term' => 'zzz_apply_test_'.uniqid('', true),
            'language' => 'es',
            'canonical_term' => 'zzz_apply_canonical_'.uniqid('', true),
            'term_type' => TaxonomyTerm::TERM_TYPE_TECHNICAL,
            'region' => [], 'negative_context' => [], 'positive_context' => [],
            'mapping_review_status' => TaxonomyTerm::MAPPING_UNMAPPED,
        ]);
    }

    private function concept(): TaxonomyCanonicalConcept
    {
        return TaxonomyCanonicalConcept::create([
            'canonical_name_es' => 'zzz_apply_concept_'.uniqid('', true),
            'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE,
        ]);
    }

    /** Un resultado de dryRun() mínimo pero con la forma real que produce el Builder. */
    private function fakeDryRun(array $overrides = []): array
    {
        return array_merge([
            'mode' => 'dry_run',
            'all_results' => [],
            'propose_new_concept_details' => [],
            'concept_relation_proposals' => ['proposals' => []],
            'concept_graph_fingerprint' => CanonicalConceptBuilderService::conceptGraphFingerprint(),
        ], $overrides);
    }

    private function scoredPair(int $termId, int $conceptId, string $tier, float $score = 0.8): array
    {
        return [
            'term_id' => $termId,
            'term' => 'zzz',
            'concept_id' => $conceptId,
            'concept_name' => 'zzz',
            'signals' => ['lexical_similarity' => 0.9],
            'score' => $score,
            'corroborating_signal_count' => 2,
            'tier' => $tier,
            'predicted_impact' => [],
        ];
    }

    // =================================================================================
    // planFrom() - función pura, sin escritura
    // =================================================================================

    #[Test]
    public function plan_excludes_reject_tier_candidates(): void
    {
        $term = $this->term();
        $keep = $this->concept();
        $drop = $this->concept();

        $plan = $this->service()->planFrom($this->fakeDryRun([
            'all_results' => [
                $this->scoredPair($term->id, $keep->id, TaxonomyCandidateConceptLink::TIER_REVIEW),
                $this->scoredPair($term->id, $drop->id, TaxonomyCandidateConceptLink::TIER_REJECT),
            ],
        ]));

        $this->assertCount(1, $plan['term_concept_candidates']);
        $this->assertSame($keep->id, $plan['term_concept_candidates'][0]['suggested_concept_id']);
    }

    #[Test]
    public function plan_dedupes_repeated_term_concept_pairs(): void
    {
        $term = $this->term();
        $concept = $this->concept();

        $plan = $this->service()->planFrom($this->fakeDryRun([
            'all_results' => [
                $this->scoredPair($term->id, $concept->id, TaxonomyCandidateConceptLink::TIER_REVIEW),
                $this->scoredPair($term->id, $concept->id, TaxonomyCandidateConceptLink::TIER_AUTO_ACCEPT),
            ],
        ]));

        $this->assertCount(1, $plan['term_concept_candidates']);
        $this->assertSame(1, $plan['total_writes']);
    }

    #[Test]
    public function plan_maps_new_concept_proposals_and_relations(): void
    {
        $term = $this->term();
        $source = $this->concept();
        $target = $this->concept();

        $plan = $this->service()->planFrom($this->fakeDryRun([
            'propose_new_concept_details' => [[
                'term_id' => $term->id,
                'term' => 'zzz',
                'suggested_canonical_name' => 'Concepto Nuevo',
                'possible_existing_concepts' => [],
                'reason' => 'motivo',
            ]],
            'concept_relation_proposals' => ['proposals' => [[
                'source_concept_id' => $source->id,
                'target_concept_id' => $target->id,
                'relation_type' => 'RELATED_TO',
                'confidence' => 0.7,
                'provenance' => ['generated_by' => 'test'],
                'evidence' => [],
            ]]],
        ]));

        $this->assertCount(1, $plan['new_concept_candidates']);
        $this->assertSame('Concepto Nuevo', $plan['new_concept_candidates'][0]['suggested_new_concept_name']);
        $this->assertCount(1, $plan['concept_relations']);
        $this->assertSame(2, $plan['total_writes']);
    }

    // =================================================================================
    // apply() - camino feliz
    // =================================================================================

    #[Test]
    public function apply_enqueues_candidates_as_pending_with_the_fingerprint_stamped(): void
    {
        $term = $this->term();
        $concept = $this->concept();
        $dryRun = $this->fakeDryRun([
            'all_results' => [$this->scoredPair($term->id, $concept->id, TaxonomyCandidateConceptLink::TIER_AUTO_ACCEPT, 0.95)],
        ]);

        $outcome = $this->service()->apply($dryRun);

        $this->assertSame(CanonicalConceptApplyService::RESULT_APPLIED, $outcome['result']);
        $this->assertSame(1, $outcome['created']['term_concept_candidates']);

        $row = TaxonomyCandidateConceptLink::where('suggested_term_id', $term->id)->firstOrFail();
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $row->status);
        $this->assertSame(TaxonomyCandidateConceptLink::TIER_AUTO_ACCEPT, $row->tier);
        $this->assertSame(0.95, $row->confidence);
        $this->assertSame($dryRun['concept_graph_fingerprint'], $row->taxonomy_state_fingerprint);
    }

    #[Test]
    public function auto_accept_tier_is_still_only_enqueued_never_published(): void
    {
        $term = $this->term();
        $concept = $this->concept();
        $before = DB::connection('pgsql')->table('taxonomy_term_concepts')->count();

        $this->service()->apply($this->fakeDryRun([
            'all_results' => [$this->scoredPair($term->id, $concept->id, TaxonomyCandidateConceptLink::TIER_AUTO_ACCEPT, 0.99)],
        ]));

        // La propiedad que define Phase C: ni el tier más alto publica solo.
        $this->assertSame($before, DB::connection('pgsql')->table('taxonomy_term_concepts')->count());
        $this->assertSame(
            TaxonomyCandidateConceptLink::STATUS_PENDING,
            TaxonomyCandidateConceptLink::where('suggested_term_id', $term->id)->firstOrFail()->status
        );
    }

    #[Test]
    public function apply_creates_relations_as_candidate_never_approved(): void
    {
        $source = $this->concept();
        $target = $this->concept();

        $outcome = $this->service()->apply($this->fakeDryRun([
            'concept_relation_proposals' => ['proposals' => [[
                'source_concept_id' => $source->id,
                'target_concept_id' => $target->id,
                'relation_type' => 'RELATED_TO',
                'confidence' => 0.7,
                'provenance' => ['generated_by' => 'test'],
                'evidence' => [],
            ]]],
        ]));

        $this->assertSame(1, $outcome['created']['concept_relations']);
        $relation = TaxonomyConceptRelation::where('source_concept_id', $source->id)->firstOrFail();
        $this->assertSame(TaxonomyConceptRelation::STATUS_CANDIDATE, $relation->status);
        $this->assertSame(CanonicalConceptApplyService::ALGORITHM_VERSION, $relation->provenance['algorithm_version']);
    }

    // =================================================================================
    // Propiedades de seguridad
    // =================================================================================

    #[Test]
    public function apply_is_idempotent_a_second_run_creates_nothing(): void
    {
        $term = $this->term();
        $concept = $this->concept();
        $dryRun = $this->fakeDryRun([
            'all_results' => [$this->scoredPair($term->id, $concept->id, TaxonomyCandidateConceptLink::TIER_REVIEW)],
        ]);

        $first = $this->service()->apply($dryRun);
        $second = $this->service()->apply($dryRun);

        $this->assertSame(1, $first['created']['term_concept_candidates']);
        $this->assertSame(0, $second['created']['term_concept_candidates']);
        $this->assertSame(1, $second['skipped']['term_concept_candidates']);
        $this->assertSame(1, TaxonomyCandidateConceptLink::where('suggested_term_id', $term->id)->count());
    }

    #[Test]
    public function apply_does_not_requeue_a_pair_a_human_already_rejected(): void
    {
        $term = $this->term();
        $concept = $this->concept();

        TaxonomyCandidateConceptLink::create([
            'suggested_term_id' => $term->id,
            'suggested_concept_id' => $concept->id,
            'signals' => [], 'confidence' => 0.4,
            'tier' => TaxonomyCandidateConceptLink::TIER_REVIEW,
            'status' => TaxonomyCandidateConceptLink::STATUS_REJECTED,
        ]);

        $outcome = $this->service()->apply($this->fakeDryRun([
            'all_results' => [$this->scoredPair($term->id, $concept->id, TaxonomyCandidateConceptLink::TIER_AUTO_ACCEPT)],
        ]));

        $this->assertSame(0, $outcome['created']['term_concept_candidates']);
        $this->assertSame(1, $outcome['skipped']['term_concept_candidates']);
        $this->assertSame(1, TaxonomyCandidateConceptLink::where('suggested_term_id', $term->id)->count());
    }

    #[Test]
    public function apply_aborts_without_writing_when_the_graph_changed_since_the_dry_run(): void
    {
        $term = $this->term();
        $concept = $this->concept();
        $dryRun = $this->fakeDryRun([
            'all_results' => [$this->scoredPair($term->id, $concept->id, TaxonomyCandidateConceptLink::TIER_REVIEW)],
            'concept_graph_fingerprint' => str_repeat('0', 64), // fingerprint de otro estado
        ]);

        $before = TaxonomyCandidateConceptLink::count();
        $outcome = $this->service()->apply($dryRun);

        $this->assertSame(CanonicalConceptApplyService::RESULT_ABORTED_STALE_FINGERPRINT, $outcome['result']);
        $this->assertSame($before, TaxonomyCandidateConceptLink::count());
    }

    #[Test]
    public function apply_aborts_without_writing_when_the_plan_exceeds_the_write_cap(): void
    {
        $term = $this->term();
        $dryRun = $this->fakeDryRun([
            'all_results' => [
                $this->scoredPair($term->id, $this->concept()->id, TaxonomyCandidateConceptLink::TIER_REVIEW),
                $this->scoredPair($term->id, $this->concept()->id, TaxonomyCandidateConceptLink::TIER_REVIEW),
                $this->scoredPair($term->id, $this->concept()->id, TaxonomyCandidateConceptLink::TIER_REVIEW),
            ],
        ]);

        $before = TaxonomyCandidateConceptLink::count();
        $outcome = $this->service()->apply($dryRun, maxWrites: 2);

        $this->assertSame(CanonicalConceptApplyService::RESULT_ABORTED_WRITE_CAP, $outcome['result']);
        $this->assertSame($before, TaxonomyCandidateConceptLink::count());
    }

    #[Test]
    public function apply_on_an_empty_plan_reports_nothing_to_apply(): void
    {
        $outcome = $this->service()->apply($this->fakeDryRun());

        $this->assertSame(CanonicalConceptApplyService::RESULT_NOTHING_TO_APPLY, $outcome['result']);
    }

    #[Test]
    public function apply_writes_an_audit_row_per_created_candidate_as_system_actor(): void
    {
        $term = $this->term();
        $concept = $this->concept();

        $this->service()->apply($this->fakeDryRun([
            'all_results' => [$this->scoredPair($term->id, $concept->id, TaxonomyCandidateConceptLink::TIER_REVIEW)],
        ]));

        $candidate = TaxonomyCandidateConceptLink::where('suggested_term_id', $term->id)->firstOrFail();
        $audit = DB::connection('pgsql')->table('taxonomy_audit_log')
            ->where('entity_type', TaxonomyCandidateConceptLink::class)
            ->where('entity_id', $candidate->id)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('system', $audit->actor_type);
        $this->assertSame(CanonicalConceptApplyService::ALGORITHM_VERSION, $audit->algorithm_version);
    }

    #[Test]
    public function apply_never_touches_the_protected_tables(): void
    {
        $term = $this->term();
        $concept = $this->concept();
        $service = $this->service();
        $before = $service->counts();

        $outcome = $service->apply($this->fakeDryRun([
            'all_results' => [$this->scoredPair($term->id, $concept->id, TaxonomyCandidateConceptLink::TIER_REVIEW)],
            'propose_new_concept_details' => [[
                'term_id' => $this->term()->id,
                'term' => 'zzz',
                'suggested_canonical_name' => 'Otro Concepto',
                'possible_existing_concepts' => [],
                'reason' => 'motivo',
            ]],
        ]));

        $this->assertSame(CanonicalConceptApplyService::RESULT_APPLIED, $outcome['result']);
        // Las 9.749 relaciones CPV y el grafo publicado quedan exactamente igual.
        $this->assertSame($before['taxonomy_term_cpv_relations'], $outcome['after']['taxonomy_term_cpv_relations']);
        $this->assertSame($before['taxonomy_term_concepts'], $outcome['after']['taxonomy_term_concepts']);
        // La cola SÍ creció - no es que no escribió nada.
        $this->assertSame($before['taxonomy_candidate_concept_links'] + 2, $outcome['after']['taxonomy_candidate_concept_links']);
    }

    #[Test]
    public function apply_skips_a_relation_that_became_invalid_after_the_dry_run(): void
    {
        $source = $this->concept();
        $target = $this->concept();

        // Alguien ya creó esa misma relación entre el dry-run y la escritura.
        TaxonomyConceptRelation::create([
            'source_concept_id' => $source->id,
            'target_concept_id' => $target->id,
            'relation_type' => 'RELATED_TO',
            'status' => TaxonomyConceptRelation::STATUS_APPROVED,
        ]);

        $outcome = $this->service()->apply($this->fakeDryRun([
            'concept_relation_proposals' => ['proposals' => [[
                'source_concept_id' => $source->id,
                'target_concept_id' => $target->id,
                'relation_type' => 'RELATED_TO',
                'confidence' => 0.7,
                'provenance' => [],
                'evidence' => [],
            ]]],
        ]));

        $this->assertSame(0, $outcome['created']['concept_relations']);
        $this->assertSame(1, $outcome['skipped']['concept_relations']);
        $this->assertSame(1, TaxonomyConceptRelation::where('source_concept_id', $source->id)->count());
    }
}
