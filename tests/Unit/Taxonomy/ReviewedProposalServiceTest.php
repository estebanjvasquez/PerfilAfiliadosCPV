<?php

namespace Tests\Unit\Taxonomy;

use App\Models\TaxonomyCandidateConceptLink;
use App\Models\TaxonomyCanonicalConcept;
use App\Models\TaxonomyConceptRelation;
use App\Models\TaxonomyReviewedProposal;
use App\Models\TaxonomyTerm;
use App\Models\User;
use App\Services\Taxonomy\CanonicalConceptBuilderService;
use App\Services\Taxonomy\ReviewedProposalService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-0004 (Phase C2, Issue #2 comentario `5886148283`): cobertura del contrato
 * REVIEWED_PROPOSAL -> payload inmutable y con fingerprint -> APPLY(payload) -> VALIDATE
 * server-side -> COMMIT/ROLLBACK -> AUDIT, implementado en `ReviewedProposalService`. Ningún
 * candidato/relación real de TASK-0001 se usa acá - todo se crea de cero por test, y
 * `DatabaseTransactions` revierte todo al final (mismo criterio que el resto de la suite de
 * taxonomía).
 */
class ReviewedProposalServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private function authorizedUser(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            'update_taxonomy::candidate::concept::link',
            'update_taxonomy::concept::relation',
        ]);

        return $user;
    }

    private function unauthorizedUser(): User
    {
        return User::factory()->create();
    }

    private function term(): TaxonomyTerm
    {
        return TaxonomyTerm::create([
            'external_id' => 'c2-test-'.uniqid('', true),
            'term' => 'zzz_c2_test_'.uniqid('', true),
            'language' => 'es',
            'canonical_term' => 'zzz_c2_test_'.uniqid('', true),
            'term_type' => TaxonomyTerm::TERM_TYPE_TECHNICAL,
            'region' => [], 'negative_context' => [], 'positive_context' => [],
            'mapping_review_status' => TaxonomyTerm::MAPPING_UNMAPPED,
        ]);
    }

    private function concept(string $name): TaxonomyCanonicalConcept
    {
        return TaxonomyCanonicalConcept::create([
            'canonical_name_es' => $name,
            'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE,
        ]);
    }

    private function mapCandidate(): array
    {
        $term = $this->term();
        $concept = $this->concept('zzz_c2_target_'.uniqid('', true));
        $candidate = TaxonomyCandidateConceptLink::create([
            'suggested_term_id' => $term->id,
            'suggested_concept_id' => $concept->id,
            'signals' => [], 'confidence' => 0.5, 'tier' => TaxonomyCandidateConceptLink::TIER_REVIEW,
            'status' => TaxonomyCandidateConceptLink::STATUS_PENDING,
        ]);

        return [$candidate, $concept];
    }

    private function newConceptCandidate(): TaxonomyCandidateConceptLink
    {
        $term = $this->term();

        return TaxonomyCandidateConceptLink::create([
            'suggested_term_id' => $term->id,
            'suggested_concept_id' => null,
            'suggested_new_concept_name' => 'zzz_c2_new_concept_'.uniqid('', true),
            'signals' => [], 'confidence' => 0.5, 'tier' => TaxonomyCandidateConceptLink::TIER_REVIEW,
            'status' => TaxonomyCandidateConceptLink::STATUS_PENDING,
        ]);
    }

    private function candidateRelation(): array
    {
        $a = $this->concept('zzz_c2_rel_a_'.uniqid());
        $b = $this->concept('zzz_c2_rel_b_'.uniqid());
        $relation = TaxonomyConceptRelation::create([
            'source_concept_id' => $a->id, 'target_concept_id' => $b->id, 'relation_type' => 'RELATED_TO',
            'weight' => 0.5, 'confidence' => 0.5, 'status' => TaxonomyConceptRelation::STATUS_CANDIDATE,
        ]);

        return [$relation, $a, $b];
    }

    // =========================================================================================
    // FREEZE - congelar el payload inmutable. Nunca publica nada.
    // =========================================================================================

    #[Test]
    public function freeze_is_refused_without_authorization_and_creates_no_proposal(): void
    {
        [$candidate] = $this->mapCandidate();

        $outcome = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING,
            $this->unauthorizedUser(),
            ['target_concept_id' => $candidate->suggested_concept_id],
        );

        $this->assertSame(ReviewedProposalService::RESULT_UNAUTHORIZED, $outcome['result']);
        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_reviewed_proposals')->where('candidate_link_id', $candidate->id)->count());
    }

    #[Test]
    public function freeze_stamps_a_payload_fingerprint_and_the_current_taxonomy_state_fingerprint(): void
    {
        [$candidate] = $this->mapCandidate();
        $expectedTaxonomyFingerprint = CanonicalConceptBuilderService::dryRunInputFingerprint();

        $outcome = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING,
            $this->authorizedUser(),
            ['target_concept_id' => $candidate->suggested_concept_id],
        );

        $this->assertSame(ReviewedProposalService::RESULT_FROZEN, $outcome['result']);
        $proposal = $outcome['proposal'];
        $this->assertNotNull($proposal->payload_fingerprint);
        $this->assertSame($expectedTaxonomyFingerprint, $proposal->taxonomy_state_fingerprint);
        $this->assertSame(TaxonomyReviewedProposal::STATUS_PENDING_APPLY, $proposal->status);
        $this->assertSame(ReviewedProposalService::PAYLOAD_VERSION, $proposal->payload_version);
        $this->assertNull($proposal->applied_at, 'freeze() nunca aplica nada.');
        $this->assertNull($proposal->authorization_reference, 'La referencia de autorización solo existe tras apply().');
        // Congelar NUNCA publica ni cambia el status del candidato original.
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $candidate->fresh()->status);
    }

    #[Test]
    public function freeze_refuses_a_candidate_that_is_no_longer_pending(): void
    {
        [$candidate] = $this->mapCandidate();
        $candidate->update(['status' => TaxonomyCandidateConceptLink::STATUS_REJECTED]);

        $outcome = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING,
            $this->authorizedUser(),
            ['target_concept_id' => $candidate->suggested_concept_id],
        );

        $this->assertSame(ReviewedProposalService::RESULT_ALREADY_PROCESSED, $outcome['result']);
    }

    #[Test]
    public function freeze_create_new_rejects_a_candidate_that_already_targets_an_existing_concept(): void
    {
        [$candidate] = $this->mapCandidate(); // suggested_concept_id NOT null

        $this->expectException(\InvalidArgumentException::class);

        (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_CREATE_NEW,
            $this->authorizedUser(),
        );
    }

    #[Test]
    public function freeze_map_to_existing_with_a_nonexistent_target_concept_is_not_found(): void
    {
        $candidate = $this->newConceptCandidate();

        $outcome = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING,
            $this->authorizedUser(),
            ['target_concept_id' => 999999999],
        );

        $this->assertSame(ReviewedProposalService::RESULT_NOT_FOUND, $outcome['result']);
    }

    #[Test]
    public function freeze_does_not_invent_a_target_concept_when_the_payload_omits_it(): void
    {
        // Hallazgo 5 de TASK-0004: "Do not invent capabilities or mappings beyond the reviewed
        // payload" - aunque el candidato YA tiene suggested_concept_id, freeze() no debe asumirlo
        // implícitamente si el payload explícito de la decisión no lo trae.
        [$candidate] = $this->mapCandidate();

        $outcome = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING,
            $this->authorizedUser(),
            [], // sin target_concept_id explícito
        );

        $this->assertSame(ReviewedProposalService::RESULT_NOT_FOUND, $outcome['result']);
    }

    #[Test]
    public function freeze_blocks_a_second_pending_proposal_for_the_same_candidate_db_enforced(): void
    {
        [$candidate] = $this->mapCandidate();
        $user = $this->authorizedUser();
        $payload = ['target_concept_id' => $candidate->suggested_concept_id];

        $first = (new ReviewedProposalService())->freeze(TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id, TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING, $user, $payload);
        $second = (new ReviewedProposalService())->freeze(TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id, TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING, $user, $payload);

        $this->assertSame(ReviewedProposalService::RESULT_FROZEN, $first['result']);
        $this->assertSame(ReviewedProposalService::RESULT_ALREADY_HAS_PENDING_PROPOSAL, $second['result']);
        $this->assertSame(1, DB::connection('pgsql')->table('taxonomy_reviewed_proposals')
            ->where('candidate_link_id', $candidate->id)->where('status', 'PENDING_APPLY')->count());
    }

    #[Test]
    public function freeze_writes_a_review_audit_entry_without_an_authorization_reference(): void
    {
        [$candidate] = $this->mapCandidate();
        $user = $this->authorizedUser();
        $this->actingAs($user);

        $outcome = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id,
            TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING, $user,
            ['target_concept_id' => $candidate->suggested_concept_id],
        );

        $logRow = DB::connection('pgsql')->table('taxonomy_audit_log')
            ->where('entity_type', TaxonomyReviewedProposal::class)
            ->where('entity_id', (string) $outcome['proposal']->id)
            ->first();

        $this->assertNotNull($logRow);
        $this->assertNull($logRow->authorization_reference, 'La auditoría de REVISIÓN no debe llevar referencia de autorización (esa es de EJECUCIÓN, solo tras apply()) - hallazgo 7 de TASK-0004.');
    }

    // =========================================================================================
    // APPLY - MAP_TO_EXISTING / CREATE_NEW / REJECT (TERM_CONCEPT_LINK)
    // =========================================================================================

    #[Test]
    public function apply_requires_a_non_empty_authorization_reference_with_a_digit(): void
    {
        [$candidate] = $this->mapCandidate();
        $user = $this->authorizedUser();
        $frozen = (new ReviewedProposalService())->freeze(TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id, TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING, $user, ['target_concept_id' => $candidate->suggested_concept_id]);

        $this->expectException(\InvalidArgumentException::class);
        (new ReviewedProposalService())->apply($frozen['proposal']->id, 'sin-digitos');
    }

    #[Test]
    public function apply_map_to_existing_publishes_the_link_and_marks_the_candidate_published(): void
    {
        [$candidate, $concept] = $this->mapCandidate();
        $user = $this->authorizedUser();
        $frozen = (new ReviewedProposalService())->freeze(TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id, TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING, $user, ['target_concept_id' => $concept->id]);

        $outcome = (new ReviewedProposalService())->apply($frozen['proposal']->id, 'TASK-0004 test-suite');

        $this->assertSame(ReviewedProposalService::RESULT_APPLIED, $outcome['result']);
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PUBLISHED, $candidate->fresh()->status);
        $this->assertNotNull($candidate->fresh()->published_term_concept_id);
        $this->assertSame(1, DB::connection('pgsql')->table('taxonomy_term_concepts')
            ->where('term_id', $candidate->suggested_term_id)->where('concept_id', $concept->id)->count());
        $this->assertSame('TASK-0004 test-suite', $outcome['proposal']->authorization_reference);
        $this->assertSame(TaxonomyReviewedProposal::STATUS_APPLIED, $outcome['proposal']->status);
    }

    #[Test]
    public function apply_create_new_creates_a_concept_and_publishes_the_link(): void
    {
        $candidate = $this->newConceptCandidate();
        $user = $this->authorizedUser();
        $conceptCountBefore = DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count();
        // TASK-0004, re-audit correction A: `new_concept_name` ya no se completa implícitamente
        // desde `suggested_new_concept_name` - freeze() exige que el llamador lo pase explícito.
        $frozen = (new ReviewedProposalService())->freeze(TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id, TaxonomyReviewedProposal::DECISION_CREATE_NEW, $user, ['new_concept_name' => $candidate->suggested_new_concept_name]);

        $outcome = (new ReviewedProposalService())->apply($frozen['proposal']->id, 'TASK-0004 test-suite');

        $this->assertSame(ReviewedProposalService::RESULT_APPLIED, $outcome['result']);
        $this->assertSame($conceptCountBefore + 1, DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count());
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PUBLISHED, $candidate->fresh()->status);
        $this->assertSame('CREATED_NEW_CONCEPT', $outcome['application_result']['outcome']);
    }

    #[Test]
    public function apply_reject_marks_the_candidate_rejected_and_writes_nothing_to_term_concepts(): void
    {
        [$candidate] = $this->mapCandidate();
        $user = $this->authorizedUser();
        $frozen = (new ReviewedProposalService())->freeze(TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id, TaxonomyReviewedProposal::DECISION_REJECT, $user, ['notes' => 'no aplica']);

        $outcome = (new ReviewedProposalService())->apply($frozen['proposal']->id, 'TASK-0004 test-suite');

        $this->assertSame(ReviewedProposalService::RESULT_APPLIED, $outcome['result']);
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_REJECTED, $candidate->fresh()->status);
        $this->assertSame('no aplica', $candidate->fresh()->review_notes);
        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_term_concepts')->where('term_id', $candidate->suggested_term_id)->count());
    }

    #[Test]
    public function apply_writes_an_execution_audit_entry_with_authorization_reference_and_target_environment(): void
    {
        [$candidate, $concept] = $this->mapCandidate();
        $user = $this->authorizedUser();
        $frozen = (new ReviewedProposalService())->freeze(TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id, TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING, $user, ['target_concept_id' => $concept->id]);

        $outcome = (new ReviewedProposalService())->apply($frozen['proposal']->id, 'Issue #2 comment 5886148283');

        $logRow = DB::connection('pgsql')->table('taxonomy_audit_log')
            ->where('entity_type', TaxonomyReviewedProposal::class)
            ->where('entity_id', (string) $outcome['proposal']->id)
            ->where('new_value', 'APPLIED')
            ->first();

        $this->assertNotNull($logRow);
        $this->assertSame('Issue #2 comment 5886148283', $logRow->authorization_reference);
        $this->assertNotNull($logRow->target_environment);
    }

    // =========================================================================================
    // APPLY - idempotencia / concurrencia (hallazgo 4 de TASK-0004)
    // =========================================================================================

    #[Test]
    public function apply_is_idempotent_a_second_call_does_not_duplicate_anything(): void
    {
        [$candidate, $concept] = $this->mapCandidate();
        $user = $this->authorizedUser();
        $frozen = (new ReviewedProposalService())->freeze(TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id, TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING, $user, ['target_concept_id' => $concept->id]);
        $service = new ReviewedProposalService();

        $first = $service->apply($frozen['proposal']->id, 'TASK-0004 test-suite');
        $auditCountAfterFirst = DB::connection('pgsql')->table('taxonomy_audit_log')->where('entity_type', TaxonomyReviewedProposal::class)->count();
        $second = $service->apply($frozen['proposal']->id, 'TASK-0004 test-suite retry');

        $this->assertSame(ReviewedProposalService::RESULT_APPLIED, $first['result']);
        $this->assertSame(ReviewedProposalService::RESULT_ALREADY_APPLIED, $second['result']);
        $this->assertSame(1, DB::connection('pgsql')->table('taxonomy_term_concepts')
            ->where('term_id', $candidate->suggested_term_id)->where('concept_id', $concept->id)->count(), 'Un replay no debe duplicar el link.');
        $this->assertSame($auditCountAfterFirst, DB::connection('pgsql')->table('taxonomy_audit_log')->where('entity_type', TaxonomyReviewedProposal::class)->count(), 'Un replay no debe agregar filas de auditoría nuevas.');
        // La referencia de autorización queda la de la aplicación REAL, no la del replay ignorado.
        $this->assertSame('TASK-0004 test-suite', $second['proposal']->authorization_reference);
    }

    #[Test]
    public function apply_detects_a_candidate_resolved_by_another_path_between_freeze_and_apply(): void
    {
        // Concurrencia real (hallazgo 3/4 de TASK-0004): el candidato se resolvió por OTRA vía
        // (ej. el camino inmediato ya aprobado de CandidateConceptApprovalService, o tinker) entre
        // freeze() y apply() - DB-enforced vía lockForUpdate() + re-chequeo de status, no vía el
        // fingerprint (que no cubre esto).
        [$candidate, $concept] = $this->mapCandidate();
        $user = $this->authorizedUser();
        $frozen = (new ReviewedProposalService())->freeze(TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id, TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING, $user, ['target_concept_id' => $concept->id]);

        $candidate->update(['status' => TaxonomyCandidateConceptLink::STATUS_REJECTED, 'reviewed_at' => now()]);

        $outcome = (new ReviewedProposalService())->apply($frozen['proposal']->id, 'TASK-0004 test-suite');

        $this->assertSame(ReviewedProposalService::RESULT_ABORTED, $outcome['result']);
        $this->assertSame(ReviewedProposalService::ABORT_CANDIDATE_ALREADY_RESOLVED, $outcome['abort_reason']);
        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_term_concepts')
            ->where('term_id', $candidate->suggested_term_id)->count(), 'No debe publicarse nada si el candidato ya fue resuelto por otra vía.');
        $this->assertSame(TaxonomyReviewedProposal::STATUS_ABORTED, $outcome['proposal']->status);
    }

    #[Test]
    public function apply_detects_the_target_concept_deleted_between_freeze_and_apply(): void
    {
        [$candidate, $concept] = $this->mapCandidate();
        $user = $this->authorizedUser();
        $frozen = (new ReviewedProposalService())->freeze(TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id, TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING, $user, ['target_concept_id' => $concept->id]);

        // Crea Y BORRA un concepto distinto (no el suggested_concept_id original, que tiene ON
        // DELETE CASCADE hacia el candidato) - el fingerprint de `taxonomy_canonical_concepts` es
        // por CONTENIDO (SELECT * ordenado por id), así que tras un create+delete vuelve exactamente
        // al mismo valor que tenía antes de crearlo (el id nuevo no queda en ninguna fila viva).
        // Esto aísla la re-verificación de EXISTENCIA de entidad como algo distinto del chequeo de
        // obsolescencia (el fingerprint de taxonomía sigue "fresco" - lo que falta es la entidad).
        $otherConcept = $this->concept('zzz_c2_will_be_deleted_'.uniqid());
        $otherConceptId = $otherConcept->id;
        $otherConcept->delete();

        // El payload nuevo debe seguir trayendo `term_id` correcto (freeze() lo auto-snapshotea
        // desde TASK-0004 HIGH-1) - si no, el chequeo de drift de term_id abortaría PRIMERO y esto
        // dejaría de aislar específicamente la re-verificación de existencia del concepto destino.
        $newPayload = ['term_id' => $candidate->suggested_term_id, 'target_concept_id' => $otherConceptId];
        DB::connection('pgsql')->table('taxonomy_reviewed_proposals')->where('id', $frozen['proposal']->id)
            ->update(['decision_payload' => json_encode($newPayload)]);
        $refreshed = TaxonomyReviewedProposal::find($frozen['proposal']->id);
        $recomputed = ReviewedProposalService::computePayloadFingerprint([
            'proposal_type' => $refreshed->proposal_type, 'candidate_link_id' => $refreshed->candidate_link_id,
            'concept_relation_id' => $refreshed->concept_relation_id, 'decision' => $refreshed->decision,
            'decision_payload' => $newPayload, 'payload_version' => $refreshed->payload_version,
            'taxonomy_state_fingerprint' => $refreshed->taxonomy_state_fingerprint, 'reviewer_id' => $refreshed->reviewer_id,
            'reviewed_at' => $refreshed->reviewed_at->format('Y-m-d H:i:s'),
        ]);
        DB::connection('pgsql')->table('taxonomy_reviewed_proposals')->where('id', $frozen['proposal']->id)->update(['payload_fingerprint' => $recomputed]);

        $outcome = (new ReviewedProposalService())->apply($frozen['proposal']->id, 'TASK-0004 test-suite');

        $this->assertSame(ReviewedProposalService::RESULT_ABORTED, $outcome['result']);
        $this->assertSame(ReviewedProposalService::ABORT_ENTITY_MISSING, $outcome['abort_reason']);
    }

    // =========================================================================================
    // APPLY - detección de manipulación (tamper) y de obsolescencia (stale state)
    // =========================================================================================

    #[Test]
    public function apply_detects_a_decision_payload_edited_directly_in_the_database(): void
    {
        [$candidate, $concept] = $this->mapCandidate();
        $otherConcept = $this->concept('zzz_c2_tamper_target_'.uniqid());
        $user = $this->authorizedUser();
        $frozen = (new ReviewedProposalService())->freeze(TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id, TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING, $user, ['target_concept_id' => $concept->id]);

        // Manipulación directa vía SQL crudo, saltándose freeze() por completo - el fingerprint NO
        // se recalcula, así que queda desincronizado de los campos reales.
        DB::connection('pgsql')->table('taxonomy_reviewed_proposals')->where('id', $frozen['proposal']->id)
            ->update(['decision_payload' => json_encode(['target_concept_id' => $otherConcept->id])]);

        $outcome = (new ReviewedProposalService())->apply($frozen['proposal']->id, 'TASK-0004 test-suite');

        $this->assertSame(ReviewedProposalService::RESULT_ABORTED, $outcome['result']);
        $this->assertSame(ReviewedProposalService::ABORT_TAMPER_DETECTED, $outcome['abort_reason']);
        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_term_concepts')->where('term_id', $candidate->suggested_term_id)->count());
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $candidate->fresh()->status, 'Un payload manipulado no debe poder publicar nada, ni con el concepto original ni con el manipulado.');
    }

    #[Test]
    public function apply_detects_taxonomy_state_that_changed_between_freeze_and_apply(): void
    {
        [$candidate, $concept] = $this->mapCandidate();
        $user = $this->authorizedUser();
        $frozen = (new ReviewedProposalService())->freeze(TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id, TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING, $user, ['target_concept_id' => $concept->id]);

        // Cambia el estado de la taxonomía (grafo publicado) DESPUÉS de congelar - un concepto
        // nuevo cambia `conceptGraphFingerprint()` y por lo tanto `dryRunInputFingerprint()`.
        $this->concept('zzz_c2_drift_'.uniqid());

        $outcome = (new ReviewedProposalService())->apply($frozen['proposal']->id, 'TASK-0004 test-suite');

        $this->assertSame(ReviewedProposalService::RESULT_ABORTED, $outcome['result']);
        $this->assertSame(ReviewedProposalService::ABORT_STALE_TAXONOMY_STATE, $outcome['abort_reason']);
        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_term_concepts')->where('term_id', $candidate->suggested_term_id)->count());
    }

    #[Test]
    public function apply_on_an_already_aborted_proposal_reports_already_aborted_without_writing(): void
    {
        [$candidate, $concept] = $this->mapCandidate();
        $user = $this->authorizedUser();
        $frozen = (new ReviewedProposalService())->freeze(TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id, TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING, $user, ['target_concept_id' => $concept->id]);
        $this->concept('zzz_c2_drift_'.uniqid()); // fuerza staleness
        $service = new ReviewedProposalService();
        $service->apply($frozen['proposal']->id, 'TASK-0004 test-suite'); // aborta y queda terminal

        $second = $service->apply($frozen['proposal']->id, 'TASK-0004 test-suite retry');

        $this->assertSame(ReviewedProposalService::RESULT_ALREADY_ABORTED, $second['result']);
        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_term_concepts')->where('term_id', $candidate->suggested_term_id)->count());
    }

    // =========================================================================================
    // APPLY - detección de drift de campos fuente (TASK-0004, re-audit HIGH-1, Issue #2 comentario
    // `5890113782`): el payload congela term_id/new_concept_name/source-target-relation_type al
    // congelar - si la fila VIVA referenciada cambia después, apply() debe abortar sin escribir
    // nada, nunca publicar en silencio con el valor viejo congelado.
    // =========================================================================================

    #[Test]
    public function apply_aborts_with_zero_writes_when_the_candidates_term_id_drifted_after_freeze(): void
    {
        [$candidate, $concept] = $this->mapCandidate();
        $otherTerm = $this->term();
        $user = $this->authorizedUser();
        $frozen = (new ReviewedProposalService())->freeze(TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id, TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING, $user, ['target_concept_id' => $concept->id]);

        // Edición directa del campo fuente DESPUÉS de freeze() - no hay UI para esto hoy (el
        // candidato no es editable una vez creado), pero el guard no depende de que exista una UI.
        $candidate->update(['suggested_term_id' => $otherTerm->id]);

        $outcome = (new ReviewedProposalService())->apply($frozen['proposal']->id, 'TASK-0004 test-suite');

        $this->assertSame(ReviewedProposalService::RESULT_ABORTED, $outcome['result']);
        $this->assertSame(ReviewedProposalService::ABORT_SOURCE_DRIFT, $outcome['abort_reason']);
        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_term_concepts')
            ->where('concept_id', $concept->id)->count(), 'No debe publicarse nada - ni con el term_id viejo (congelado) ni con el nuevo (drifteado).');
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $candidate->fresh()->status);
    }

    #[Test]
    public function apply_aborts_with_zero_writes_when_the_candidates_new_concept_name_drifted_after_freeze(): void
    {
        $candidate = $this->newConceptCandidate();
        $user = $this->authorizedUser();
        $conceptCountBefore = DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count();
        $frozen = (new ReviewedProposalService())->freeze(TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id, TaxonomyReviewedProposal::DECISION_CREATE_NEW, $user, ['new_concept_name' => $candidate->suggested_new_concept_name]);

        $candidate->update(['suggested_new_concept_name' => 'nombre editado después de freeze '.uniqid()]);

        $outcome = (new ReviewedProposalService())->apply($frozen['proposal']->id, 'TASK-0004 test-suite');

        $this->assertSame(ReviewedProposalService::RESULT_ABORTED, $outcome['result']);
        $this->assertSame(ReviewedProposalService::ABORT_SOURCE_DRIFT, $outcome['abort_reason']);
        $this->assertSame($conceptCountBefore, DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count(), 'No debe crearse ningún concepto - ni con el nombre viejo (congelado) ni con el nuevo (drifteado).');
    }

    #[Test]
    public function freeze_snapshots_the_new_concept_name_so_apply_never_reads_the_live_candidate_field(): void
    {
        // TASK-0004, re-audit HIGH-1: "CREATE_NEW must require the reviewed new concept name
        // explicitly in the frozen payload; do not fall back at apply time to a mutable candidate
        // field" - se prueba explícitamente que el nombre PUBLICADO es el que estaba congelado en
        // el momento de freeze(), no el que la fila tiene ahora (aunque acá ambos casos
        // deliberadamente NO haya drift, para separar esta prueba de la de arriba).
        $candidate = $this->newConceptCandidate();
        $originalName = $candidate->suggested_new_concept_name;
        $user = $this->authorizedUser();

        $frozen = (new ReviewedProposalService())->freeze(TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id, TaxonomyReviewedProposal::DECISION_CREATE_NEW, $user, ['new_concept_name' => $originalName]);

        $this->assertSame($originalName, $frozen['proposal']->decision_payload['new_concept_name'], 'freeze() debe congelar el nombre YA en ese instante.');
        $this->assertSame($originalName, $frozen['proposal']->decision_payload['source_suggested_new_concept_name'], 'freeze() también debe congelar la sugerencia del Builder por separado (ronda 4, defecto 1), aunque acá ambos valores coincidan.');
    }

    #[Test]
    public function apply_create_new_publishes_the_reviewers_chosen_name_even_when_it_differs_from_the_builders_suggestion(): void
    {
        // TASK-0004, re-audit ronda 4 (Issue #2 comentario `5909267134`, defecto 1): el Builder
        // sugiere "X", el humano aprueba explícitamente "Y" en freeze() (una corrección/normalización
        // legítima del nombre) - la fila fuente (`suggested_new_concept_name`) NUNCA cambia después
        // de freeze(), así que esto NO debe abortar por drift. Antes de esta corrección, comparar el
        // nombre revisado contra la sugerencia viva hacía esto imposible.
        $candidate = $this->newConceptCandidate();
        $suggestedName = $candidate->suggested_new_concept_name;
        $reviewerChosenName = 'nombre normalizado por el revisor '.uniqid('', true);
        $user = $this->authorizedUser();
        $conceptCountBefore = DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count();

        $frozen = (new ReviewedProposalService())->freeze(TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id, TaxonomyReviewedProposal::DECISION_CREATE_NEW, $user, ['new_concept_name' => $reviewerChosenName]);
        $this->assertSame(ReviewedProposalService::RESULT_FROZEN, $frozen['result']);
        $this->assertSame($suggestedName, $frozen['proposal']->decision_payload['source_suggested_new_concept_name']);
        $this->assertSame($reviewerChosenName, $frozen['proposal']->decision_payload['new_concept_name']);

        $outcome = (new ReviewedProposalService())->apply($frozen['proposal']->id, 'TASK-0004 test-suite');

        $this->assertSame(ReviewedProposalService::RESULT_APPLIED, $outcome['result'], 'Una corrección legítima del revisor (nombre distinto al sugerido, sin que la fuente cambie) no debe abortar por drift.');
        $this->assertSame($conceptCountBefore + 1, DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count());
        $newConcept = DB::connection('pgsql')->table('taxonomy_canonical_concepts')->latest('id')->first();
        $this->assertTrue(
            $newConcept->canonical_name_es === $reviewerChosenName || $newConcept->canonical_name_en === $reviewerChosenName,
            'El concepto creado debe usar el nombre REVISADO por el humano, no el sugerido por el Builder.'
        );
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PUBLISHED, $candidate->fresh()->status);
    }

    #[Test]
    public function freeze_create_new_rejects_when_new_concept_name_is_missing_from_the_payload(): void
    {
        // TASK-0004, re-audit correction A (Issue #2 comentario `5892711739`): la ronda anterior
        // movió el fallback mutable de apply() a freeze() (`?? $candidate->suggested_new_concept_name`),
        // pero seguía siendo un fallback implícito - el hallazgo pide que freeze() RECHACE en vez de
        // completar el nombre por su cuenta.
        $candidate = $this->newConceptCandidate();
        $user = $this->authorizedUser();

        $outcome = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id,
            TaxonomyReviewedProposal::DECISION_CREATE_NEW, $user,
            [], // sin new_concept_name explícito
        );

        $this->assertSame(ReviewedProposalService::RESULT_VALIDATION_FAILED, $outcome['result']);
        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_reviewed_proposals')->where('candidate_link_id', $candidate->id)->count());
    }

    #[Test]
    public function freeze_create_new_rejects_a_blank_new_concept_name(): void
    {
        $candidate = $this->newConceptCandidate();
        $user = $this->authorizedUser();

        $outcome = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id,
            TaxonomyReviewedProposal::DECISION_CREATE_NEW, $user,
            ['new_concept_name' => '   '],
        );

        $this->assertSame(ReviewedProposalService::RESULT_VALIDATION_FAILED, $outcome['result']);
        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_reviewed_proposals')->where('candidate_link_id', $candidate->id)->count());
    }

    // =========================================================================================
    // APPLY - CONTEXT_REQUIRED (TASK-0004, re-audit correction C, Issue #2 comentario
    // `5892711739`, defecto 2 cerrado en la ronda 4, comentario `5909267134`): cuarto desenlace de
    // revisión - término/candidato válido pero insuficientemente específico para un mapeo directo
    // producto/servicio/CPV. Distinto de REJECT: el candidato NO se descarta, el motivo queda
    // preservado para un posible uso futuro como evidencia contextual (sin que hoy exista ningún
    // consumidor que lo lea). Nunca escribe taxonomy_term_concepts ni crea un concepto, y SÍ
    // revalida el term_id congelado contra la fila viva antes de resolverse. Fixtures propios
    // exclusivamente - ningún candidato/relación real de TASK-0001 se toca acá.
    // =========================================================================================

    #[Test]
    public function freeze_context_required_rejects_when_the_reason_is_missing_from_the_payload(): void
    {
        [$candidate] = $this->mapCandidate();
        $user = $this->authorizedUser();

        $outcome = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id,
            TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED, $user,
            [], // sin context_reason explícito
        );

        $this->assertSame(ReviewedProposalService::RESULT_VALIDATION_FAILED, $outcome['result']);
        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_reviewed_proposals')->where('candidate_link_id', $candidate->id)->count());
    }

    #[Test]
    public function freeze_context_required_rejects_a_blank_reason(): void
    {
        [$candidate] = $this->mapCandidate();
        $user = $this->authorizedUser();

        $outcome = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id,
            TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED, $user,
            ['context_reason' => '   '],
        );

        $this->assertSame(ReviewedProposalService::RESULT_VALIDATION_FAILED, $outcome['result']);
    }

    #[Test]
    public function freeze_context_required_works_for_a_candidate_that_already_suggests_an_existing_concept(): void
    {
        // CONTEXT_REQUIRED no está restringido a candidatos PROPOSE_NEW_CONCEPT (a diferencia de
        // CREATE_NEW) - un término puede ser demasiado genérico sin importar si el Builder ya sugirió
        // un concepto destino o no.
        [$candidate] = $this->mapCandidate();
        $user = $this->authorizedUser();

        $outcome = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id,
            TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED, $user,
            ['context_reason' => 'Término genérico del dominio - no sostiene un mapeo directo por sí solo.'],
        );

        $this->assertSame(ReviewedProposalService::RESULT_FROZEN, $outcome['result']);
    }

    #[Test]
    public function apply_context_required_marks_the_candidate_and_writes_zero_taxonomy_mappings(): void
    {
        [$candidate, $concept] = $this->mapCandidate();
        $user = $this->authorizedUser();
        $conceptCountBefore = DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count();
        $reason = 'Término genérico del dominio - no sostiene un mapeo directo por sí solo.';
        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id,
            TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED, $user,
            ['context_reason' => $reason],
        );

        $outcome = (new ReviewedProposalService())->apply($frozen['proposal']->id, 'TASK-0004 test-suite');

        $this->assertSame(ReviewedProposalService::RESULT_APPLIED, $outcome['result']);
        $this->assertSame('CONTEXT_REQUIRED', $outcome['application_result']['outcome']);
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_CONTEXT_REQUIRED, $candidate->fresh()->status);
        $this->assertNotSame(TaxonomyCandidateConceptLink::STATUS_REJECTED, $candidate->fresh()->status, 'CONTEXT_REQUIRED es distinto de REJECTED - el candidato no se descarta.');
        $this->assertSame($reason, $candidate->fresh()->review_notes, 'El motivo del revisor debe preservarse.');
        $this->assertNull($candidate->fresh()->published_term_concept_id, 'No debe quedar ningún link publicado.');
        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_term_concepts')
            ->where('term_id', $candidate->suggested_term_id)->count(), 'CONTEXT_REQUIRED nunca escribe taxonomy_term_concepts.');
        $this->assertSame($conceptCountBefore, DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count(), 'CONTEXT_REQUIRED nunca inventa un concepto nuevo.');
    }

    #[Test]
    public function apply_refuses_a_context_required_proposal_tampered_into_map_to_existing(): void
    {
        // TASK-0004, re-audit correction C: "source drift/tamper must not be able to turn it into
        // MAP_TO_EXISTING/CREATE_NEW" - el fingerprint de tamper-detection YA cubre esto genéricamente
        // (incluye el campo `decision`), este test lo prueba explícitamente para este escenario.
        [$candidate, $concept] = $this->mapCandidate();
        $user = $this->authorizedUser();
        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id,
            TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED, $user,
            ['context_reason' => 'Término genérico del dominio.'],
        );

        DB::connection('pgsql')->table('taxonomy_reviewed_proposals')->where('id', $frozen['proposal']->id)
            ->update([
                'decision' => TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING,
                'decision_payload' => json_encode(['term_id' => $candidate->suggested_term_id, 'target_concept_id' => $concept->id]),
            ]);

        $outcome = (new ReviewedProposalService())->apply($frozen['proposal']->id, 'TASK-0004 test-suite');

        $this->assertSame(ReviewedProposalService::RESULT_ABORTED, $outcome['result']);
        $this->assertSame(ReviewedProposalService::ABORT_TAMPER_DETECTED, $outcome['abort_reason']);
        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_term_concepts')->where('term_id', $candidate->suggested_term_id)->count());
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $candidate->fresh()->status);
    }

    #[Test]
    public function apply_context_required_aborts_with_zero_writes_when_the_candidates_term_id_drifted_after_freeze(): void
    {
        // TASK-0004, re-audit ronda 4 (Issue #2 comentario `5909267134`, defecto 2): CONTEXT_REQUIRED
        // es una decisión semántica SOBRE un término particular - si `suggested_term_id` cambió desde
        // freeze(), aplicar la decisión "necesita contexto" al candidato mutado resolvería un término
        // DISTINTO del que el humano revisó. Antes de esta corrección, la rama CONTEXT_REQUIRED
        // corría ANTES del chequeo de drift de term_id y lo saltaba explícitamente.
        [$candidate] = $this->mapCandidate();
        $otherTerm = $this->term();
        $user = $this->authorizedUser();
        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id,
            TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED, $user,
            ['context_reason' => 'Término genérico del dominio - no sostiene un mapeo directo por sí solo.'],
        );

        $candidate->update(['suggested_term_id' => $otherTerm->id]);

        $outcome = (new ReviewedProposalService())->apply($frozen['proposal']->id, 'TASK-0004 test-suite');

        $this->assertSame(ReviewedProposalService::RESULT_ABORTED, $outcome['result']);
        $this->assertSame(ReviewedProposalService::ABORT_SOURCE_DRIFT, $outcome['abort_reason']);
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $candidate->fresh()->status, 'El candidato debe permanecer pending - CONTEXT_REQUIRED no debe resolverse sobre un término distinto al revisado.');
        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_term_concepts')
            ->whereIn('term_id', [$candidate->suggested_term_id, $otherTerm->id])->count(), 'No debe escribirse ningún mapeo de taxonomía.');
    }

    #[Test]
    public function apply_aborts_with_zero_writes_when_the_relations_source_concept_id_drifted_after_freeze(): void
    {
        [$relation] = $this->candidateRelation();
        $otherConcept = $this->concept('zzz_c2_drift_source_'.uniqid());
        $user = $this->authorizedUser();
        $frozen = (new ReviewedProposalService())->freeze(TaxonomyReviewedProposal::TYPE_CONCEPT_RELATION, $relation->id, TaxonomyReviewedProposal::DECISION_PUBLISH_RELATION, $user);

        // Edición directa (no hay UI que reasigne source/target de una relación ya creada, pero el
        // guard no depende de que exista una).
        DB::connection('pgsql')->table('taxonomy_concept_relations')->where('id', $relation->id)->update(['source_concept_id' => $otherConcept->id]);

        $outcome = (new ReviewedProposalService())->apply($frozen['proposal']->id, 'TASK-0004 test-suite');

        $this->assertSame(ReviewedProposalService::RESULT_ABORTED, $outcome['result']);
        $this->assertSame(ReviewedProposalService::ABORT_SOURCE_DRIFT, $outcome['abort_reason']);
        $this->assertSame(TaxonomyConceptRelation::STATUS_CANDIDATE, DB::connection('pgsql')->table('taxonomy_concept_relations')->where('id', $relation->id)->value('status'));
    }

    #[Test]
    public function apply_aborts_with_zero_writes_when_the_relations_target_concept_id_drifted_after_freeze(): void
    {
        // TASK-0004, re-audit correction B (Issue #2 comentario `5892711739`): la matriz de tests de
        // drift estaba incompleta - solo `source_concept_id` tenía mutation test. El hallazgo pide
        // explícitamente mutar CADA campo fuente decision-relevante por separado y probar abort con
        // cero escrituras para cada uno.
        [$relation] = $this->candidateRelation();
        $otherConcept = $this->concept('zzz_c2_drift_target_'.uniqid());
        $user = $this->authorizedUser();
        $frozen = (new ReviewedProposalService())->freeze(TaxonomyReviewedProposal::TYPE_CONCEPT_RELATION, $relation->id, TaxonomyReviewedProposal::DECISION_PUBLISH_RELATION, $user);

        DB::connection('pgsql')->table('taxonomy_concept_relations')->where('id', $relation->id)->update(['target_concept_id' => $otherConcept->id]);

        $outcome = (new ReviewedProposalService())->apply($frozen['proposal']->id, 'TASK-0004 test-suite');

        $this->assertSame(ReviewedProposalService::RESULT_ABORTED, $outcome['result']);
        $this->assertSame(ReviewedProposalService::ABORT_SOURCE_DRIFT, $outcome['abort_reason']);
        $this->assertSame(TaxonomyConceptRelation::STATUS_CANDIDATE, DB::connection('pgsql')->table('taxonomy_concept_relations')->where('id', $relation->id)->value('status'));
    }

    #[Test]
    public function apply_aborts_with_zero_writes_when_the_relations_relation_type_drifted_after_freeze(): void
    {
        // TASK-0004, re-audit correction B: mismo criterio, para relation_type.
        [$relation] = $this->candidateRelation();
        $user = $this->authorizedUser();
        $frozen = (new ReviewedProposalService())->freeze(TaxonomyReviewedProposal::TYPE_CONCEPT_RELATION, $relation->id, TaxonomyReviewedProposal::DECISION_PUBLISH_RELATION, $user);

        DB::connection('pgsql')->table('taxonomy_concept_relations')->where('id', $relation->id)->update(['relation_type' => 'PART_OF']);

        $outcome = (new ReviewedProposalService())->apply($frozen['proposal']->id, 'TASK-0004 test-suite');

        $this->assertSame(ReviewedProposalService::RESULT_ABORTED, $outcome['result']);
        $this->assertSame(ReviewedProposalService::ABORT_SOURCE_DRIFT, $outcome['abort_reason']);
        $this->assertSame(TaxonomyConceptRelation::STATUS_CANDIDATE, DB::connection('pgsql')->table('taxonomy_concept_relations')->where('id', $relation->id)->value('status'));
    }

    #[Test]
    public function freeze_snapshots_relation_endpoints_and_type_so_apply_never_rediscovers_them_live(): void
    {
        [$relation, $a, $b] = $this->candidateRelation();
        $user = $this->authorizedUser();

        $frozen = (new ReviewedProposalService())->freeze(TaxonomyReviewedProposal::TYPE_CONCEPT_RELATION, $relation->id, TaxonomyReviewedProposal::DECISION_PUBLISH_RELATION, $user);

        $payload = $frozen['proposal']->decision_payload;
        $this->assertSame($a->id, $payload['source_concept_id']);
        $this->assertSame($b->id, $payload['target_concept_id']);
        $this->assertSame('RELATED_TO', $payload['relation_type']);
    }

    // =========================================================================================
    // APPLY - CONCEPT_RELATION (PUBLISH_RELATION / REJECT)
    // =========================================================================================

    #[Test]
    public function apply_publish_relation_approves_the_relation_after_server_side_revalidation(): void
    {
        [$relation] = $this->candidateRelation();
        $user = $this->authorizedUser();
        $frozen = (new ReviewedProposalService())->freeze(TaxonomyReviewedProposal::TYPE_CONCEPT_RELATION, $relation->id, TaxonomyReviewedProposal::DECISION_PUBLISH_RELATION, $user);

        $outcome = (new ReviewedProposalService())->apply($frozen['proposal']->id, 'TASK-0004 test-suite');

        $this->assertSame(ReviewedProposalService::RESULT_APPLIED, $outcome['result']);
        $this->assertSame(TaxonomyConceptRelation::STATUS_APPROVED, $relation->fresh()->status);
    }

    #[Test]
    public function apply_publish_relation_aborts_when_a_symmetric_duplicate_was_approved_while_it_waited(): void
    {
        // Orden deliberado (mismo criterio que TaxonomyConceptRelationValidationTest - ver su
        // comentario): la simétrica inversa (B,A,approved) se crea PRIMERO, cuando (A,B,candidate)
        // todavía no existe - si se creara al revés, el guard del propio modelo
        // (`TaxonomyConceptRelation::booted()`) ya bloquearía ESTA creación como duplicado (su
        // chequeo de "reverse" no filtra por status), lo cual probaría otra cosa (creación), no la
        // revalidación en `apply()`, que es lo que este test necesita aislar.
        $a = $this->concept('zzz_c2_rel_a_'.uniqid());
        $b = $this->concept('zzz_c2_rel_b_'.uniqid());
        // INSERT crudo (no ::create()) - TASK-0004 hallazgo HIGH-2 bloquea crear vía Eloquent con
        // status=approved fuera del apply() autorizado de Phase C2; esto es solo fixture de "ya
        // existe aprobada", no la acción bajo prueba.
        DB::connection('pgsql')->table('taxonomy_concept_relations')->insert([
            'source_concept_id' => $b->id, 'target_concept_id' => $a->id, 'relation_type' => 'RELATED_TO',
            'weight' => 0.5, 'confidence' => 0.9, 'status' => TaxonomyConceptRelation::STATUS_APPROVED,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // $relation ("esperando revisión") se crea DESPUÉS, directo por Eloquent con
        // status=candidate - no dispara el guard (que solo revisa transiciones hacia `approved`).
        $relation = TaxonomyConceptRelation::create([
            'source_concept_id' => $a->id, 'target_concept_id' => $b->id, 'relation_type' => 'RELATED_TO',
            'weight' => 0.5, 'confidence' => 0.5, 'status' => TaxonomyConceptRelation::STATUS_CANDIDATE,
        ]);
        $user = $this->authorizedUser();
        // freeze() NUNCA llama a validateConceptRelationProposal() (eso es deliberadamente parte
        // de "VALIDATE", el paso de apply() - ver el docblock del servicio) - congela la decisión
        // de "publicar esta relación" sin importar si ya es semánticamente inválida en ese momento.
        // Por eso alcanza con crear la simétrica ANTES de freeze(): el estado de la taxonomía no
        // vuelve a cambiar entre freeze() y apply(), así que esto aísla específicamente la
        // revalidación de `apply()`, no el chequeo de obsolescencia.
        $frozen = (new ReviewedProposalService())->freeze(TaxonomyReviewedProposal::TYPE_CONCEPT_RELATION, $relation->id, TaxonomyReviewedProposal::DECISION_PUBLISH_RELATION, $user);

        $outcome = (new ReviewedProposalService())->apply($frozen['proposal']->id, 'TASK-0004 test-suite');

        $this->assertSame(ReviewedProposalService::RESULT_ABORTED, $outcome['result']);
        $this->assertSame(ReviewedProposalService::ABORT_RELATION_INVALID_AT_APPLY_TIME, $outcome['abort_reason']);
        $this->assertSame(TaxonomyConceptRelation::STATUS_CANDIDATE, $relation->fresh()->status);
    }

    #[Test]
    public function apply_reject_relation_marks_it_rejected(): void
    {
        [$relation] = $this->candidateRelation();
        $user = $this->authorizedUser();
        $frozen = (new ReviewedProposalService())->freeze(TaxonomyReviewedProposal::TYPE_CONCEPT_RELATION, $relation->id, TaxonomyReviewedProposal::DECISION_REJECT, $user);

        $outcome = (new ReviewedProposalService())->apply($frozen['proposal']->id, 'TASK-0004 test-suite');

        $this->assertSame(ReviewedProposalService::RESULT_APPLIED, $outcome['result']);
        $this->assertSame(TaxonomyConceptRelation::STATUS_REJECTED, $relation->fresh()->status);
    }

    // =========================================================================================
    // Rollback transaccional real (misma técnica que
    // CandidateConceptApprovalServiceTest::approve_rolls_back...): prueba que Postgres/Laravel
    // revierte TODO si cualquier escritura de la transacción de apply() falla, incluida la propia
    // transición de estado del payload - nunca queda un estado a medias.
    // =========================================================================================

    #[Test]
    public function a_failure_mid_transaction_rolls_back_both_the_taxonomy_write_and_the_proposal_status(): void
    {
        [$candidate, $concept] = $this->mapCandidate();
        $user = $this->authorizedUser();
        $frozen = (new ReviewedProposalService())->freeze(TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK, $candidate->id, TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING, $user, ['target_concept_id' => $concept->id]);
        $proposalId = $frozen['proposal']->id;

        try {
            DB::connection('pgsql')->transaction(function () use ($candidate, $concept, $proposalId) {
                DB::connection('pgsql')->table('taxonomy_term_concepts')->insert([
                    'term_id' => $candidate->suggested_term_id, 'concept_id' => $concept->id, 'created_at' => now(),
                ]);
                DB::connection('pgsql')->table('taxonomy_reviewed_proposals')->where('id', $proposalId)
                    ->update(['status' => TaxonomyReviewedProposal::STATUS_APPLIED, 'applied_at' => now()]);

                // status VARCHAR(30) en taxonomy_candidate_concept_links - viola la longitud de
                // columna y debe abortar TODA la transacción, incluidos los dos writes de arriba.
                DB::connection('pgsql')->table('taxonomy_candidate_concept_links')
                    ->where('id', $candidate->id)
                    ->update(['status' => str_repeat('x', 100)]);
            });
            $this->fail('Se esperaba que la actualización violara la longitud de columna.');
        } catch (\Throwable $e) {
            // esperado
        }

        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_term_concepts')
            ->where('term_id', $candidate->suggested_term_id)->where('concept_id', $concept->id)->count(),
            'El INSERT del link debe revertirse cuando otro write de la misma transacción falla.');
        $this->assertSame(TaxonomyReviewedProposal::STATUS_PENDING_APPLY, TaxonomyReviewedProposal::find($proposalId)->status,
            'La transición de status del payload debe revertirse junto con el resto - nunca queda "aplicado" a medias.');
    }

    // =========================================================================================
    // Fingerprint determinístico (función pura, sin DB)
    // =========================================================================================

    #[Test]
    public function payload_fingerprint_is_stable_regardless_of_array_key_order(): void
    {
        $a = ReviewedProposalService::computePayloadFingerprint([
            'decision' => 'MAP_TO_EXISTING',
            'decision_payload' => ['target_concept_id' => 5, 'note' => 'x'],
            'candidate_link_id' => 1,
        ]);
        $b = ReviewedProposalService::computePayloadFingerprint([
            'candidate_link_id' => 1,
            'decision_payload' => ['note' => 'x', 'target_concept_id' => 5],
            'decision' => 'MAP_TO_EXISTING',
        ]);

        $this->assertSame($a, $b);
    }

    #[Test]
    public function payload_fingerprint_changes_when_a_decision_relevant_field_changes(): void
    {
        $a = ReviewedProposalService::computePayloadFingerprint(['decision' => 'MAP_TO_EXISTING', 'decision_payload' => ['target_concept_id' => 5]]);
        $b = ReviewedProposalService::computePayloadFingerprint(['decision' => 'MAP_TO_EXISTING', 'decision_payload' => ['target_concept_id' => 6]]);

        $this->assertNotSame($a, $b);
    }
}
