<?php

namespace Tests\Unit\Taxonomy;

use App\Models\TaxonomyCandidateConceptLink;
use App\Models\TaxonomyCanonicalConcept;
use App\Models\TaxonomyTerm;
use App\Models\User;
use App\Services\Taxonomy\CandidateConceptApprovalService;
use App\Services\Taxonomy\CanonicalConceptBuilderService;
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

    /**
     * TASK-0004, re-audit HIGH-2 (Issue #2 comentario `5890113782`): este test se llamaba
     * `approve_publishes_the_link_and_marks_the_candidate_published` y probaba justo lo que ese
     * hallazgo pidió cerrar - `approve()` ya NO puede publicar, en ningún caso, fuera del `apply()`
     * autorizado de Phase C2 (`ReviewedProposalService`). El guard vive en
     * `TaxonomyCandidateConceptLink::booted()`; la transacción de `approve()` revierte por completo
     * (incluido el INSERT en `taxonomy_term_concepts` que ya había corrido antes en la misma
     * transacción).
     */
    #[Test]
    public function approve_no_longer_publishes_anything_it_is_blocked_by_the_c2_bypass_guard(): void
    {
        $candidate = $this->pendingCandidate();
        $countBefore = DB::connection('pgsql')->table('taxonomy_term_concepts')->count();

        try {
            (new CandidateConceptApprovalService())->approve($candidate->id, $this->authorizedUser());
            $this->fail('Se esperaba que approve() lanzara RuntimeException - la publicación directa debe estar bloqueada (TASK-0004 hallazgo HIGH-2).');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Phase C2', $e->getMessage());
        }

        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $candidate->fresh()->status, 'El candidato debe seguir pending - la transacción completa debe revertirse.');
        $this->assertSame($countBefore, DB::connection('pgsql')->table('taxonomy_term_concepts')->count(), 'El INSERT en taxonomy_term_concepts (que corrió antes en la misma transacción) también debe revertirse.');
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

        // TASK-0004, re-audit HIGH-2: incluso con un link ya existente para reusar (el escenario que
        // este test originalmente probaba como éxito), approve() sigue bloqueado - el guard corre
        // sobre la transición de STATUS del candidato, no sobre si el link se creó o se reusó.
        try {
            (new CandidateConceptApprovalService())->approve($candidate->id, $this->authorizedUser());
            $this->fail('Se esperaba RuntimeException - approve() sigue bloqueado aunque el link ya exista (TASK-0004 hallazgo HIGH-2).');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Phase C2', $e->getMessage());
        }
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $candidate->fresh()->status);
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

    /**
     * TASK-0004, re-audit HIGH-2: como approve() ya no llega a publicar (lanza antes de terminar su
     * transacción), tampoco llega a escribir su propia fila de auditoría de "aprobado" - se
     * verifica explícitamente que NO quede un registro fantasma de una publicación que en realidad
     * nunca sucedió.
     */
    #[Test]
    public function approve_writes_no_audit_log_entry_since_it_no_longer_publishes(): void
    {
        $candidate = $this->pendingCandidate();
        $user = $this->authorizedUser();
        $this->actingAs($user);

        try {
            (new CandidateConceptApprovalService())->approve($candidate->id, $user);
            $this->fail('Se esperaba RuntimeException.');
        } catch (\RuntimeException) {
            // esperado
        }

        $logRow = DB::connection('pgsql')->table('taxonomy_audit_log')
            ->where('entity_type', TaxonomyCandidateConceptLink::class)
            ->where('entity_id', (string) $candidate->id)
            ->where('field', 'status')
            ->first();

        $this->assertNull($logRow, 'No debe quedar auditoría de una publicación que en realidad se revirtió.');
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

    /**
     * TASK-0004, re-audit HIGH-2: `resolveNewConceptProposal(..., DECISION_CREATE_NEW)` intentaba
     * publicar directamente (crear el concepto + el link + marcar el candidato published) - ahora
     * el guard de modelo bloquea la última de esas escrituras y revierte toda la transacción,
     * incluido el concepto recién creado.
     */
    #[Test]
    public function resolve_new_concept_proposal_create_new_no_longer_publishes_blocked_by_c2_guard(): void
    {
        $candidate = $this->newConceptCandidate('Concepto Genuinamente Nuevo '.uniqid());
        $conceptCountBefore = DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count();

        try {
            (new CandidateConceptApprovalService())->resolveNewConceptProposal(
                $candidate->id, $this->authorizedUser(), CandidateConceptApprovalService::DECISION_CREATE_NEW
            );
            $this->fail('Se esperaba RuntimeException (TASK-0004 hallazgo HIGH-2).');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Phase C2', $e->getMessage());
        }

        $this->assertSame($conceptCountBefore, DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count(), 'El concepto nuevo creado antes en la misma transacción también debe revertirse.');
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $candidate->fresh()->status);
    }

    #[Test]
    public function resolve_new_concept_proposal_map_to_existing_no_longer_publishes_blocked_by_c2_guard(): void
    {
        $candidate = $this->newConceptCandidate();
        $existingConcept = TaxonomyCanonicalConcept::create([
            'canonical_name_es' => 'zzz_phaseb_existing_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE,
        ]);

        try {
            (new CandidateConceptApprovalService())->resolveNewConceptProposal(
                $candidate->id, $this->authorizedUser(), CandidateConceptApprovalService::DECISION_MAP_TO_EXISTING, targetConceptId: $existingConcept->id
            );
            $this->fail('Se esperaba RuntimeException (TASK-0004 hallazgo HIGH-2).');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Phase C2', $e->getMessage());
        }

        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $candidate->fresh()->status);
        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_term_concepts')
            ->where('term_id', $candidate->suggested_term_id)->where('concept_id', $existingConcept->id)->count());
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
    public function resolve_new_concept_proposal_is_blocked_the_same_way_on_every_repeated_call(): void
    {
        $candidate = $this->newConceptCandidate();
        $user = $this->authorizedUser();
        $service = new CandidateConceptApprovalService();

        foreach ([1, 2] as $attempt) {
            try {
                $service->resolveNewConceptProposal($candidate->id, $user, CandidateConceptApprovalService::DECISION_CREATE_NEW);
                $this->fail("Intento {$attempt}: se esperaba RuntimeException.");
            } catch (\RuntimeException) {
                // esperado en ambos intentos - nunca llega a ALREADY_PROCESSED porque nunca llega a publicar.
            }
        }

        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $candidate->fresh()->status);
    }

    #[Test]
    public function resolve_new_concept_proposal_writes_no_audit_log_entry_since_it_no_longer_publishes(): void
    {
        $candidate = $this->newConceptCandidate();
        $user = $this->authorizedUser();
        $this->actingAs($user);

        try {
            (new CandidateConceptApprovalService())->resolveNewConceptProposal($candidate->id, $user, CandidateConceptApprovalService::DECISION_CREATE_NEW);
            $this->fail('Se esperaba RuntimeException.');
        } catch (\RuntimeException) {
            // esperado
        }

        $logRow = DB::connection('pgsql')->table('taxonomy_audit_log')
            ->where('entity_type', TaxonomyCandidateConceptLink::class)
            ->where('entity_id', (string) $candidate->id)
            ->where('field', 'status')
            ->first();

        $this->assertNull($logRow, 'No debe quedar auditoría de una publicación que en realidad se revirtió.');
    }

    // =========================================================================================
    // Phase B.1 (sección 7 del pedido): composeReviewReason() - función pura, sin DB.
    // =========================================================================================

    #[Test]
    public function compose_review_reason_uses_the_category_label_when_no_note_is_given(): void
    {
        $reason = CandidateConceptApprovalService::composeReviewReason(CandidateConceptApprovalService::REJECT_REASON_DUPLICATE, null);

        $this->assertSame('[DUPLICATE] Duplicado de un concepto/relación ya existente', $reason);
    }

    #[Test]
    public function compose_review_reason_prefers_the_free_text_note_when_given(): void
    {
        $reason = CandidateConceptApprovalService::composeReviewReason(CandidateConceptApprovalService::REJECT_REASON_OTHER, 'no corresponde a este dominio');

        $this->assertSame('[OTHER] no corresponde a este dominio', $reason);
    }

    #[Test]
    public function compose_review_reason_treats_a_blank_note_as_no_note(): void
    {
        $reason = CandidateConceptApprovalService::composeReviewReason(CandidateConceptApprovalService::REJECT_REASON_AMBIGUOUS, '   ');

        $this->assertSame('[AMBIGUOUS] Ambiguo - no se puede decidir con la evidencia disponible', $reason);
    }

    // =========================================================================================
    // Phase B.1 (sección 9 del pedido): proposalStaleness() - protección de stale dry-run.
    // =========================================================================================

    #[Test]
    public function concept_graph_staleness_is_not_tracked_for_a_candidate_without_a_stamped_fingerprint(): void
    {
        $candidate = $this->newConceptCandidate();
        $this->assertNull($candidate->taxonomy_state_fingerprint, 'Ningún proceso puebla esta columna todavía (Phase C no existe).');

        $staleness = (new CandidateConceptApprovalService())->proposalStaleness($candidate);

        $this->assertFalse($staleness['tracked']);
        $this->assertFalse($staleness['stale'], 'No rastreado nunca debe reportarse como obsoleto (falsa alarma).');
        $this->assertNull($staleness['stored_fingerprint']);
        $this->assertNotEmpty($staleness['current_fingerprint']);
    }

    #[Test]
    public function concept_graph_staleness_is_not_stale_when_the_stamped_fingerprint_still_matches(): void
    {
        $candidate = $this->newConceptCandidate();
        // TASK-0003, hallazgo 4: proposalStaleness() compara contra dryRunInputFingerprint() (el
        // amplio), no contra conceptGraphFingerprint() (el angosto) - hay que estampar el mismo que
        // se va a comparar.
        $currentFingerprint = CanonicalConceptBuilderService::dryRunInputFingerprint();
        $candidate->update(['taxonomy_state_fingerprint' => $currentFingerprint]);

        $staleness = (new CandidateConceptApprovalService())->proposalStaleness($candidate->fresh());

        $this->assertTrue($staleness['tracked']);
        $this->assertFalse($staleness['stale']);
    }

    #[Test]
    public function concept_graph_staleness_is_stale_when_the_concept_graph_changed_after_the_stamp(): void
    {
        $candidate = $this->newConceptCandidate();
        // Estampa un fingerprint deliberadamente distinto del real (simula que el grafo cambió
        // después de que este candidato se generó - ej. otro concepto se creó o fusionó mientras
        // tanto).
        $candidate->update(['taxonomy_state_fingerprint' => hash('sha256', 'deliberately-stale-fingerprint')]);

        $staleness = (new CandidateConceptApprovalService())->proposalStaleness($candidate->fresh());

        $this->assertTrue($staleness['tracked']);
        $this->assertTrue($staleness['stale']);
    }

    #[Test]
    public function concept_graph_fingerprint_changes_when_a_new_canonical_concept_is_created(): void
    {
        $before = CanonicalConceptBuilderService::conceptGraphFingerprint();

        TaxonomyCanonicalConcept::create([
            'canonical_name_es' => 'zzz_fingerprint_test_'.uniqid('', true),
            'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE,
        ]);

        $after = CanonicalConceptBuilderService::conceptGraphFingerprint();

        $this->assertNotSame($before, $after, 'Crear un concepto nuevo debe cambiar el fingerprint del grafo.');
    }
}
