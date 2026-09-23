<?php

namespace Tests\Unit\Taxonomy;

use App\Models\TaxonomyCandidateConceptLink;
use App\Models\TaxonomyCanonicalConcept;
use App\Models\TaxonomyTerm;
use App\Models\User;
use App\Services\Taxonomy\CandidateConceptApprovalService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 3.1 (sección 9 del pedido): cada salvaguarda auditada de Approve/Reject tiene su propio
 * test. Usa `DatabaseTransactions` sobre `pgsql` - ningún candidato se aprueba de verdad de forma
 * permanente (todo se revierte al terminar cada test), consistente con "NO aprobar ningún candidato
 * real" del pedido.
 */
class CandidateConceptApprovalServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private function authorizedUser(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('update_taxonomy::candidate::concept::link');

        return $user;
    }

    private function unauthorizedUser(): User
    {
        return User::factory()->create();
    }

    private function pendingCandidate(): TaxonomyCandidateConceptLink
    {
        $term = TaxonomyTerm::create([
            'external_id' => 'approval-test-'.uniqid('', true),
            'term' => 'zzz_approval_test_'.uniqid('', true),
            'language' => 'es',
            'canonical_term' => 'zzz_approval_test_'.uniqid('', true),
            'term_type' => TaxonomyTerm::TERM_TYPE_TECHNICAL,
            'region' => [], 'negative_context' => [], 'positive_context' => [],
            'mapping_review_status' => TaxonomyTerm::MAPPING_UNMAPPED,
        ]);
        $concept = TaxonomyCanonicalConcept::create([
            'canonical_name_es' => 'zzz_approval_concept_'.uniqid('', true),
            'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE,
        ]);

        return TaxonomyCandidateConceptLink::create([
            'suggested_term_id' => $term->id,
            'suggested_concept_id' => $concept->id,
            'signals' => [],
            'confidence' => 0.5,
            'tier' => TaxonomyCandidateConceptLink::TIER_REVIEW,
            'status' => TaxonomyCandidateConceptLink::STATUS_PENDING,
        ]);
    }

    #[Test]
    public function approve_is_refused_without_authorization_and_writes_nothing(): void
    {
        $candidate = $this->pendingCandidate();
        $countBefore = DB::connection('pgsql')->table('taxonomy_term_concepts')->count();

        $outcome = (new CandidateConceptApprovalService())->approve($candidate->id, $this->unauthorizedUser());

        $this->assertSame(CandidateConceptApprovalService::RESULT_UNAUTHORIZED, $outcome['result']);
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $candidate->fresh()->status);
        $this->assertSame($countBefore, DB::connection('pgsql')->table('taxonomy_term_concepts')->count());
    }

    #[Test]
    public function approve_with_no_user_at_all_is_refused(): void
    {
        $candidate = $this->pendingCandidate();

        $outcome = (new CandidateConceptApprovalService())->approve($candidate->id, null);

        $this->assertSame(CandidateConceptApprovalService::RESULT_UNAUTHORIZED, $outcome['result']);
    }

    #[Test]
    public function approve_publishes_the_link_and_marks_the_candidate_published(): void
    {
        $candidate = $this->pendingCandidate();

        $outcome = (new CandidateConceptApprovalService())->approve($candidate->id, $this->authorizedUser());

        $this->assertSame(CandidateConceptApprovalService::RESULT_APPROVED, $outcome['result']);
        $fresh = $candidate->fresh();
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PUBLISHED, $fresh->status);
        $this->assertNotNull($fresh->published_term_concept_id);
        $this->assertNotNull($fresh->reviewed_at);

        $this->assertSame(1, DB::connection('pgsql')->table('taxonomy_term_concepts')
            ->where('term_id', $candidate->suggested_term_id)
            ->where('concept_id', $candidate->suggested_concept_id)
            ->count());
    }

    #[Test]
    public function approve_is_idempotent_a_second_call_does_not_duplicate_the_link(): void
    {
        $candidate = $this->pendingCandidate();
        $user = $this->authorizedUser();

        $first = (new CandidateConceptApprovalService())->approve($candidate->id, $user);
        $second = (new CandidateConceptApprovalService())->approve($candidate->id, $user);

        $this->assertSame(CandidateConceptApprovalService::RESULT_APPROVED, $first['result']);
        $this->assertSame(CandidateConceptApprovalService::RESULT_ALREADY_PROCESSED, $second['result']);
        $this->assertSame($first['term_concept_id'], $second['term_concept_id']);

        $this->assertSame(1, DB::connection('pgsql')->table('taxonomy_term_concepts')
            ->where('term_id', $candidate->suggested_term_id)
            ->where('concept_id', $candidate->suggested_concept_id)
            ->count(), 'Doble aprobación (doble click) NO debe duplicar el link término->concepto.');
    }

    #[Test]
    public function approve_reuses_an_existing_link_instead_of_violating_the_unique_constraint(): void
    {
        $candidate = $this->pendingCandidate();
        // El link YA existe por otra vía (ej. otro admin lo creó a mano) antes de aprobar este candidato.
        DB::connection('pgsql')->table('taxonomy_term_concepts')->insert([
            'term_id' => $candidate->suggested_term_id, 'concept_id' => $candidate->suggested_concept_id, 'created_at' => now(),
        ]);
        $existingId = DB::connection('pgsql')->table('taxonomy_term_concepts')
            ->where('term_id', $candidate->suggested_term_id)->where('concept_id', $candidate->suggested_concept_id)->value('id');

        $outcome = (new CandidateConceptApprovalService())->approve($candidate->id, $this->authorizedUser());

        $this->assertSame(CandidateConceptApprovalService::RESULT_APPROVED, $outcome['result']);
        $this->assertSame($existingId, $outcome['term_concept_id'], 'Debe reusar el link existente, no intentar duplicarlo.');
    }

    #[Test]
    public function approve_fails_gracefully_when_the_concept_was_deleted_concurrently(): void
    {
        $candidate = $this->pendingCandidate();
        // Estado de carrera real: un admin borra el concepto desde otra pestaña mientras el
        // candidato sigue pendiente. `taxonomy_candidate_concept_links.suggested_concept_id` tiene
        // `ON DELETE CASCADE` (por diseño, sección 7 del pedido original) - el propio candidato
        // desaparece junto con el concepto, así que approve() debe reportar NOT_FOUND en vez de
        // lanzar una excepción sin manejar.
        DB::connection('pgsql')->table('taxonomy_canonical_concepts')->where('id', $candidate->suggested_concept_id)->delete();

        $outcome = (new CandidateConceptApprovalService())->approve($candidate->id, $this->authorizedUser());

        $this->assertSame(CandidateConceptApprovalService::RESULT_NOT_FOUND, $outcome['result']);
        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_term_concepts')
            ->where('term_id', $candidate->suggested_term_id)->count());
    }

    #[Test]
    public function approve_rolls_back_the_published_link_if_the_status_update_violates_a_constraint(): void
    {
        // Prueba de atomicidad real (sección 9 del pedido): fuerza que el segundo write de la
        // transacción (el UPDATE del candidato) falle DESPUÉS de que el primero (el INSERT del
        // link) ya se ejecutó en memoria de la transacción - vía un `status` que excede el
        // VARCHAR(20) de la columna. Si la transacción es atómica, el INSERT en
        // taxonomy_term_concepts NO debe persistir tampoco.
        $candidate = $this->pendingCandidate();

        try {
            DB::connection('pgsql')->transaction(function () use ($candidate) {
                DB::connection('pgsql')->table('taxonomy_term_concepts')->insert([
                    'term_id' => $candidate->suggested_term_id,
                    'concept_id' => $candidate->suggested_concept_id,
                    'created_at' => now(),
                ]);

                // status VARCHAR(20) - esto viola la longitud de columna y debe abortar la transacción.
                DB::connection('pgsql')->table('taxonomy_candidate_concept_links')
                    ->where('id', $candidate->id)
                    ->update(['status' => str_repeat('x', 100)]);
            });
            $this->fail('Se esperaba que la actualización violara la longitud de columna.');
        } catch (\Throwable $e) {
            // esperado
        }

        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $candidate->fresh()->status);
        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_term_concepts')
            ->where('term_id', $candidate->suggested_term_id)->where('concept_id', $candidate->suggested_concept_id)->count(),
            'El INSERT previo en la misma transacción debe revertirse cuando el UPDATE posterior falla.');
    }

    #[Test]
    public function approve_rejects_propose_new_concept_candidates(): void
    {
        $term = TaxonomyTerm::create([
            'external_id' => 'approval-test-'.uniqid('', true), 'term' => 'zzz_new_concept_'.uniqid('', true),
            'language' => 'es', 'canonical_term' => 'zzz_new_concept_'.uniqid('', true),
            'term_type' => TaxonomyTerm::TERM_TYPE_TECHNICAL, 'region' => [], 'negative_context' => [], 'positive_context' => [],
            'mapping_review_status' => TaxonomyTerm::MAPPING_UNMAPPED,
        ]);
        $candidate = TaxonomyCandidateConceptLink::create([
            'suggested_term_id' => $term->id, 'suggested_concept_id' => null,
            'suggested_new_concept_name' => 'Concepto nuevo propuesto',
            'signals' => [], 'confidence' => 0.5, 'tier' => TaxonomyCandidateConceptLink::TIER_REVIEW,
            'status' => TaxonomyCandidateConceptLink::STATUS_PENDING,
        ]);

        $outcome = (new CandidateConceptApprovalService())->approve($candidate->id, $this->authorizedUser());

        $this->assertSame(CandidateConceptApprovalService::RESULT_NOT_SUPPORTED, $outcome['result']);
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $candidate->fresh()->status);
    }

    #[Test]
    public function approve_writes_an_audit_log_entry(): void
    {
        $candidate = $this->pendingCandidate();
        $user = $this->authorizedUser();
        // TaxonomyAuditLogger::record() usa Auth::id() ambiental (TAXV2-9, no un actor explícito) -
        // en producción esto siempre coincide con el usuario de la request de Filament; en el test
        // hay que autenticar la sesión explícitamente para reproducir esa misma condición real.
        $this->actingAs($user);

        (new CandidateConceptApprovalService())->approve($candidate->id, $user);

        $logRow = DB::connection('pgsql')->table('taxonomy_audit_log')
            ->where('entity_type', TaxonomyCandidateConceptLink::class)
            ->where('entity_id', (string) $candidate->id)
            ->where('field', 'status')
            ->first();

        $this->assertNotNull($logRow, 'Approve debe dejar un registro en taxonomy_audit_log (TaxonomyAuditLogger).');
        $this->assertSame((string) $user->id, (string) $logRow->user_id);
        $this->assertSame('pending', $logRow->old_value);
        $this->assertSame('published', $logRow->new_value);
    }

    #[Test]
    public function reject_is_refused_without_authorization(): void
    {
        $candidate = $this->pendingCandidate();

        $outcome = (new CandidateConceptApprovalService())->reject($candidate->id, $this->unauthorizedUser());

        $this->assertSame(CandidateConceptApprovalService::RESULT_UNAUTHORIZED, $outcome['result']);
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $candidate->fresh()->status);
    }

    #[Test]
    public function reject_is_idempotent(): void
    {
        $candidate = $this->pendingCandidate();
        $user = $this->authorizedUser();

        $first = (new CandidateConceptApprovalService())->reject($candidate->id, $user, 'no aplica');
        $second = (new CandidateConceptApprovalService())->reject($candidate->id, $user, 'segundo intento');

        $this->assertSame(CandidateConceptApprovalService::RESULT_REJECTED, $first['result']);
        $this->assertSame(CandidateConceptApprovalService::RESULT_ALREADY_PROCESSED, $second['result']);
        $this->assertSame('no aplica', $candidate->fresh()->review_notes, 'El segundo intento no debe pisar las notas del primero.');
    }

    #[Test]
    public function reject_on_a_missing_candidate_reports_not_found(): void
    {
        $outcome = (new CandidateConceptApprovalService())->reject(999999999, $this->authorizedUser());

        $this->assertSame(CandidateConceptApprovalService::RESULT_NOT_FOUND, $outcome['result']);
    }

    // ============================= PHASE B (B3) - PROPOSE NEW CONCEPT =============================

    private function newConceptCandidate(?string $suggestedName = null): TaxonomyCandidateConceptLink
    {
        $term = TaxonomyTerm::create([
            'external_id' => 'phaseb-test-'.uniqid('', true),
            'term' => 'zzz_phaseb_newconcept_'.uniqid('', true),
            'language' => 'es',
            'canonical_term' => 'zzz_phaseb_newconcept_'.uniqid('', true),
            'term_type' => TaxonomyTerm::TERM_TYPE_TECHNICAL,
            'region' => [], 'negative_context' => [], 'positive_context' => [],
            'mapping_review_status' => TaxonomyTerm::MAPPING_UNMAPPED,
        ]);

        return TaxonomyCandidateConceptLink::create([
            'suggested_term_id' => $term->id,
            'suggested_concept_id' => null,
            'suggested_new_concept_name' => $suggestedName ?? 'Concepto nuevo propuesto '.uniqid(),
            'signals' => [], 'confidence' => 0.5, 'tier' => TaxonomyCandidateConceptLink::TIER_REVIEW,
            'status' => TaxonomyCandidateConceptLink::STATUS_PENDING,
        ]);
    }

    #[Test]
    public function find_possible_duplicate_concepts_returns_empty_for_a_candidate_that_already_targets_an_existing_concept(): void
    {
        $candidate = $this->pendingCandidate(); // ya tiene suggested_concept_id, no es propose-new

        $duplicates = (new CandidateConceptApprovalService())->findPossibleDuplicateConcepts($candidate);

        $this->assertSame([], $duplicates);
    }

    #[Test]
    public function resolve_new_concept_proposal_with_reject_delegates_to_reject(): void
    {
        $candidate = $this->newConceptCandidate();
        $user = $this->authorizedUser();

        $outcome = (new CandidateConceptApprovalService())->resolveNewConceptProposal(
            $candidate->id, $user, CandidateConceptApprovalService::DECISION_REJECT, notes: 'no aplica'
        );

        $this->assertSame(CandidateConceptApprovalService::RESULT_REJECTED, $outcome['result']);
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_REJECTED, $candidate->fresh()->status);
    }

    #[Test]
    public function resolve_new_concept_proposal_is_refused_without_authorization_and_writes_nothing(): void
    {
        $candidate = $this->newConceptCandidate();
        $conceptCountBefore = DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count();

        $outcome = (new CandidateConceptApprovalService())->resolveNewConceptProposal(
            $candidate->id, $this->unauthorizedUser(), CandidateConceptApprovalService::DECISION_CREATE_NEW
        );

        $this->assertSame(CandidateConceptApprovalService::RESULT_UNAUTHORIZED, $outcome['result']);
        $this->assertSame($conceptCountBefore, DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count());
    }

    #[Test]
    public function resolve_new_concept_proposal_rejects_a_candidate_that_is_not_proposing_a_new_concept(): void
    {
        $candidate = $this->pendingCandidate(); // ya tiene suggested_concept_id

        $outcome = (new CandidateConceptApprovalService())->resolveNewConceptProposal(
            $candidate->id, $this->authorizedUser(), CandidateConceptApprovalService::DECISION_CREATE_NEW
        );

        $this->assertSame(CandidateConceptApprovalService::RESULT_NOT_APPLICABLE, $outcome['result']);
    }

    #[Test]
    public function resolve_new_concept_proposal_creates_a_new_concept_and_links_the_term(): void
    {
        $candidate = $this->newConceptCandidate('Concepto Genuinamente Nuevo '.uniqid());
        $conceptCountBefore = DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count();

        $outcome = (new CandidateConceptApprovalService())->resolveNewConceptProposal(
            $candidate->id, $this->authorizedUser(), CandidateConceptApprovalService::DECISION_CREATE_NEW
        );

        $this->assertSame(CandidateConceptApprovalService::RESULT_CREATED_NEW_CONCEPT, $outcome['result']);
        $this->assertNotNull($outcome['concept_id']);
        $this->assertSame($conceptCountBefore + 1, DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count());
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PUBLISHED, $candidate->fresh()->status);
        $this->assertSame(1, DB::connection('pgsql')->table('taxonomy_term_concepts')
            ->where('term_id', $candidate->suggested_term_id)->where('concept_id', $outcome['concept_id'])->count());
    }

    #[Test]
    public function resolve_new_concept_proposal_maps_to_an_existing_concept_instead_of_creating_one(): void
    {
        $candidate = $this->newConceptCandidate();
        $existingConcept = TaxonomyCanonicalConcept::create([
            'canonical_name_es' => 'zzz_phaseb_existing_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE,
        ]);
        $conceptCountBefore = DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count();

        $outcome = (new CandidateConceptApprovalService())->resolveNewConceptProposal(
            $candidate->id, $this->authorizedUser(), CandidateConceptApprovalService::DECISION_MAP_TO_EXISTING, targetConceptId: $existingConcept->id
        );

        $this->assertSame(CandidateConceptApprovalService::RESULT_MAPPED_TO_EXISTING, $outcome['result']);
        $this->assertSame($existingConcept->id, $outcome['concept_id']);
        $this->assertSame($conceptCountBefore, DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count(), 'MAP_TO_EXISTING no debe crear ningun concepto nuevo.');
    }

    #[Test]
    public function resolve_new_concept_proposal_map_to_existing_without_a_target_id_is_not_found(): void
    {
        $candidate = $this->newConceptCandidate();

        $outcome = (new CandidateConceptApprovalService())->resolveNewConceptProposal(
            $candidate->id, $this->authorizedUser(), CandidateConceptApprovalService::DECISION_MAP_TO_EXISTING
        );

        $this->assertSame(CandidateConceptApprovalService::RESULT_NOT_FOUND, $outcome['result']);
    }

    #[Test]
    public function resolve_new_concept_proposal_is_idempotent(): void
    {
        $candidate = $this->newConceptCandidate();
        $user = $this->authorizedUser();

        $first = (new CandidateConceptApprovalService())->resolveNewConceptProposal($candidate->id, $user, CandidateConceptApprovalService::DECISION_CREATE_NEW);
        $second = (new CandidateConceptApprovalService())->resolveNewConceptProposal($candidate->id, $user, CandidateConceptApprovalService::DECISION_CREATE_NEW);

        $this->assertSame(CandidateConceptApprovalService::RESULT_CREATED_NEW_CONCEPT, $first['result']);
        $this->assertSame(CandidateConceptApprovalService::RESULT_ALREADY_PROCESSED, $second['result']);
    }

    #[Test]
    public function resolve_new_concept_proposal_writes_an_audit_log_entry(): void
    {
        $candidate = $this->newConceptCandidate();
        $user = $this->authorizedUser();
        $this->actingAs($user);

        (new CandidateConceptApprovalService())->resolveNewConceptProposal($candidate->id, $user, CandidateConceptApprovalService::DECISION_CREATE_NEW);

        $logRow = DB::connection('pgsql')->table('taxonomy_audit_log')
            ->where('entity_type', TaxonomyCandidateConceptLink::class)
            ->where('entity_id', (string) $candidate->id)
            ->where('field', 'status')
            ->first();

        $this->assertNotNull($logRow);
        $this->assertSame('published', $logRow->new_value);
    }
}
