<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\TaxonomyCandidateConceptLinkResource\Pages\ListTaxonomyCandidateConceptLinks;
use App\Models\TaxonomyCandidateConceptLink;
use App\Models\TaxonomyCanonicalConcept;
use App\Models\TaxonomyReviewedProposal;
use App\Models\TaxonomyTerm;
use App\Models\User;
use App\Services\Taxonomy\CandidateConceptApprovalService;
use App\Services\Taxonomy\ReviewedProposalService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase B.1 (sección 17 del pedido, historia original): probar el WIRING real del panel de
 * Filament, no reimplementar las pruebas de autorización/idempotencia/transacción del servicio
 * subyacente (esas ya existen en `ReviewedProposalServiceTest`).
 *
 * TASK-0005 (Issue #2 comentario `5914793857`), sección A: reescrito para probar la acción única
 * `freezeReview` (reemplaza `approve`/`reject`/`resolveNewConcept`, ver docblock de
 * `TaxonomyCandidateConceptLinkResource`) - las 4 decisiones C2 vía `ReviewedProposalService::freeze()`
 * real, nunca `CandidateConceptApprovalService` (que sigue existiendo pero ya no tiene ningún botón
 * que lo invoque).
 *
 * `DatabaseTransactions` sobre `pgsql` - nada persiste al terminar la clase.
 */
class TaxonomyCandidateConceptLinkReviewTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function authorizedReviewer(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            'view_any_taxonomy::candidate::concept::link',
            'view_taxonomy::candidate::concept::link',
            'update_taxonomy::candidate::concept::link',
        ]);

        return $user;
    }

    private function unauthorizedUser(): User
    {
        return User::factory()->create();
    }

    private function readOnlyReviewer(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            'view_any_taxonomy::candidate::concept::link',
            'view_taxonomy::candidate::concept::link',
        ]);

        return $user;
    }

    private function newConceptCandidate(): TaxonomyCandidateConceptLink
    {
        $term = TaxonomyTerm::create([
            'external_id' => 'task0005-ui-'.uniqid('', true),
            'term' => 'zzz_task0005_ui_'.uniqid('', true),
            'language' => 'es',
            'canonical_term' => 'zzz_task0005_ui_'.uniqid('', true),
            'term_type' => TaxonomyTerm::TERM_TYPE_TECHNICAL,
            'region' => [], 'negative_context' => [], 'positive_context' => [],
            'mapping_review_status' => TaxonomyTerm::MAPPING_UNMAPPED,
        ]);

        return TaxonomyCandidateConceptLink::create([
            'suggested_term_id' => $term->id,
            'suggested_concept_id' => null,
            'suggested_new_concept_name' => 'Concepto UI Test '.uniqid(),
            'signals' => [], 'confidence' => 0.5, 'tier' => TaxonomyCandidateConceptLink::TIER_REVIEW,
            'status' => TaxonomyCandidateConceptLink::STATUS_PENDING,
        ]);
    }

    private function mappableCandidate(): TaxonomyCandidateConceptLink
    {
        $term = TaxonomyTerm::create([
            'external_id' => 'task0005-ui-map-'.uniqid('', true),
            'term' => 'zzz_task0005_ui_map_'.uniqid('', true),
            'language' => 'es',
            'canonical_term' => 'zzz_task0005_ui_map_'.uniqid('', true),
            'term_type' => TaxonomyTerm::TERM_TYPE_TECHNICAL,
            'region' => [], 'negative_context' => [], 'positive_context' => [],
            'mapping_review_status' => TaxonomyTerm::MAPPING_UNMAPPED,
        ]);
        $concept = TaxonomyCanonicalConcept::create([
            'canonical_name_es' => 'zzz_task0005_ui_concept_'.uniqid('', true),
            'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE,
        ]);

        return TaxonomyCandidateConceptLink::create([
            'suggested_term_id' => $term->id,
            'suggested_concept_id' => $concept->id,
            'signals' => [], 'confidence' => 0.5, 'tier' => TaxonomyCandidateConceptLink::TIER_REVIEW,
            'status' => TaxonomyCandidateConceptLink::STATUS_PENDING,
        ]);
    }

    #[Test]
    public function an_authorized_reviewer_sees_the_freeze_review_action_for_any_pending_candidate(): void
    {
        $newConcept = $this->newConceptCandidate();
        $mappable = $this->mappableCandidate();

        Livewire::actingAs($this->authorizedReviewer())
            ->test(ListTaxonomyCandidateConceptLinks::class)
            ->assertTableActionVisible('freezeReview', $newConcept)
            ->assertTableActionVisible('freezeReview', $mappable);
    }

    #[Test]
    public function a_user_with_no_permission_at_all_cannot_even_open_the_review_queue(): void
    {
        $candidate = $this->newConceptCandidate();

        $this->actingAs($this->unauthorizedUser())
            ->get(\App\Filament\Resources\TaxonomyCandidateConceptLinkResource::getUrl('index'))
            ->assertForbidden();

        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $candidate->fresh()->status);
    }

    #[Test]
    public function a_read_only_reviewer_can_see_the_queue_but_not_the_freeze_review_action(): void
    {
        $candidate = $this->newConceptCandidate();

        Livewire::actingAs($this->readOnlyReviewer())
            ->test(ListTaxonomyCandidateConceptLinks::class)
            ->assertSuccessful()
            ->assertTableActionHidden('freezeReview', $candidate)
            ->assertTableActionVisible('view', $candidate);
    }

    #[Test]
    public function freeze_review_map_to_existing_freezes_without_publishing(): void
    {
        $candidate = $this->mappableCandidate();
        $target = TaxonomyCanonicalConcept::create(['canonical_name_es' => 'zzz_task0005_target_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);
        $reviewer = $this->authorizedReviewer();

        Livewire::actingAs($reviewer)
            ->test(ListTaxonomyCandidateConceptLinks::class)
            ->callTableAction('freezeReview', $candidate, data: [
                'decision' => TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING,
                'target_concept_id' => $target->id,
            ])
            ->assertHasNoTableActionErrors();

        $fresh = $candidate->fresh();
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $fresh->status, 'freeze() nunca publica - el candidato sigue pending.');
        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_term_concepts')
            ->where('term_id', $candidate->suggested_term_id)->where('concept_id', $target->id)->count());

        $proposal = TaxonomyReviewedProposal::where('candidate_link_id', $candidate->id)->sole();
        $this->assertSame(TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING, $proposal->decision);
        $this->assertSame(TaxonomyReviewedProposal::STATUS_PENDING_APPLY, $proposal->status);
        $this->assertSame($target->id, $proposal->decision_payload['target_concept_id']);
        // Procedencia del revisor estructuralmente persistida (sección C del comentario).
        $this->assertSame($reviewer->id, $proposal->reviewer_id);
        $this->assertNotNull($proposal->reviewed_at);
        $this->assertNull($proposal->authorization_reference, 'freeze() no aplica - no debe llevar referencia de autorización todavía.');
    }

    #[Test]
    public function freeze_review_create_new_freezes_without_publishing_and_uses_the_reviewers_explicit_name(): void
    {
        // TASK-0004 ronda 4 (defecto 1): el nombre REVISADO explícito puede diferir del sugerido
        // por el Builder sin que eso sea tratado como drift - el candidato sigue teniendo su
        // sugerencia original ("Concepto UI Test ...") pero el revisor elige otro nombre acá.
        $candidate = $this->newConceptCandidate();
        $reviewerChosenName = 'Nombre elegido por el revisor '.uniqid();
        $reviewer = $this->authorizedReviewer();

        Livewire::actingAs($reviewer)
            ->test(ListTaxonomyCandidateConceptLinks::class)
            ->callTableAction('freezeReview', $candidate, data: [
                'decision' => TaxonomyReviewedProposal::DECISION_CREATE_NEW,
                'new_concept_name' => $reviewerChosenName,
            ])
            ->assertHasNoTableActionErrors();

        $fresh = $candidate->fresh();
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $fresh->status);
        $this->assertSame($candidate->suggested_new_concept_name, $fresh->suggested_new_concept_name, 'freeze() no debe tocar el campo sugerido original.');

        $proposal = TaxonomyReviewedProposal::where('candidate_link_id', $candidate->id)->sole();
        $this->assertSame($reviewerChosenName, $proposal->decision_payload['new_concept_name'], 'Debe congelar el nombre elegido por el revisor, no el sugerido.');
        $this->assertSame($candidate->suggested_new_concept_name, $proposal->decision_payload['source_suggested_new_concept_name'], 'Debe congelar también el snapshot de la fuente para drift-detection.');
        $this->assertNotSame($reviewerChosenName, $proposal->decision_payload['source_suggested_new_concept_name']);
    }

    #[Test]
    public function freeze_review_create_new_requires_an_explicit_nonblank_name_form_validation(): void
    {
        $candidate = $this->newConceptCandidate();

        Livewire::actingAs($this->authorizedReviewer())
            ->test(ListTaxonomyCandidateConceptLinks::class)
            ->callTableAction('freezeReview', $candidate, data: [
                'decision' => TaxonomyReviewedProposal::DECISION_CREATE_NEW,
                'new_concept_name' => '',
            ])
            ->assertHasTableActionErrors(['new_concept_name' => 'required']);

        $this->assertSame(0, TaxonomyReviewedProposal::where('candidate_link_id', $candidate->id)->count(), 'No debe congelarse nada si falta el nombre explícito.');
    }

    #[Test]
    public function freeze_review_map_to_existing_requires_an_explicit_target_concept_form_validation(): void
    {
        $candidate = $this->mappableCandidate();

        Livewire::actingAs($this->authorizedReviewer())
            ->test(ListTaxonomyCandidateConceptLinks::class)
            ->callTableAction('freezeReview', $candidate, data: [
                'decision' => TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING,
                'target_concept_id' => null,
            ])
            ->assertHasTableActionErrors(['target_concept_id' => 'required']);

        $this->assertSame(0, TaxonomyReviewedProposal::where('candidate_link_id', $candidate->id)->count());
    }

    #[Test]
    public function freeze_review_context_required_freezes_with_zero_taxonomy_writes(): void
    {
        $candidate = $this->mappableCandidate();
        $conceptCountBefore = DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count();
        $reason = 'Término genérico del dominio - no sostiene un mapeo directo por sí solo.';

        Livewire::actingAs($this->authorizedReviewer())
            ->test(ListTaxonomyCandidateConceptLinks::class)
            ->callTableAction('freezeReview', $candidate, data: [
                'decision' => TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED,
                'context_reason' => $reason,
            ])
            ->assertHasNoTableActionErrors();

        $fresh = $candidate->fresh();
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $fresh->status, 'freeze() no cambia el status del candidato - solo apply() lo haría.');
        $this->assertSame($conceptCountBefore, DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count());
        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_term_concepts')->where('term_id', $candidate->suggested_term_id)->count());

        $proposal = TaxonomyReviewedProposal::where('candidate_link_id', $candidate->id)->sole();
        $this->assertSame(TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED, $proposal->decision);
        $this->assertSame($reason, $proposal->decision_payload['context_reason']);
    }

    #[Test]
    public function freeze_review_context_required_requires_an_explicit_nonblank_reason_form_validation(): void
    {
        $candidate = $this->mappableCandidate();

        Livewire::actingAs($this->authorizedReviewer())
            ->test(ListTaxonomyCandidateConceptLinks::class)
            ->callTableAction('freezeReview', $candidate, data: [
                'decision' => TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED,
                'context_reason' => '',
            ])
            ->assertHasTableActionErrors(['context_reason' => 'required']);

        $this->assertSame(0, TaxonomyReviewedProposal::where('candidate_link_id', $candidate->id)->count());
    }

    #[Test]
    public function freeze_review_reject_requires_a_reason_and_freezes_without_publishing(): void
    {
        $candidate = $this->mappableCandidate();

        Livewire::actingAs($this->authorizedReviewer())
            ->test(ListTaxonomyCandidateConceptLinks::class)
            ->callTableAction('freezeReview', $candidate, data: [
                'decision' => TaxonomyReviewedProposal::DECISION_REJECT,
                'reject_reason_category' => CandidateConceptApprovalService::REJECT_REASON_DUPLICATE,
                'notes' => null,
            ])
            ->assertHasNoTableActionErrors();

        $fresh = $candidate->fresh();
        // freeze() nunca toca el candidato (ni siquiera para REJECT) - la fila solo se resuelve
        // vía apply(), que esta UI deliberadamente no expone. Distinto del viejo
        // CandidateConceptApprovalService::reject() (que SÍ escribía status=rejected de inmediato).
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $fresh->status);

        $proposal = TaxonomyReviewedProposal::where('candidate_link_id', $candidate->id)->sole();
        $this->assertSame(TaxonomyReviewedProposal::DECISION_REJECT, $proposal->decision);
        $this->assertSame('[DUPLICATE] Duplicado de un concepto/relación ya existente', $proposal->decision_payload['notes']);
    }

    #[Test]
    public function freeze_review_double_submit_does_not_create_a_second_pending_proposal(): void
    {
        // TASK-0006D, PARTE 2: desde esta tarea el segundo intento ya no es ni alcanzable desde la
        // UI (el botón se esconde en cuanto existe una propuesta viva). Lo que este test protege es
        // la salvaguarda REAL, que no depende de la visibilidad: el índice único parcial + el
        // servicio rechazan un segundo congelamiento aunque alguien invoque el camino a mano.
        $candidate = $this->mappableCandidate();
        $target = TaxonomyCanonicalConcept::create(['canonical_name_es' => 'zzz_task0005_double_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);
        $reviewer = $this->authorizedReviewer();

        Livewire::actingAs($reviewer)
            ->test(ListTaxonomyCandidateConceptLinks::class)
            ->callTableAction('freezeReview', $candidate->fresh(), data: [
                'decision' => TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING,
                'target_concept_id' => $target->id,
            ])
            ->assertHasNoTableActionErrors();

        // Invocación DIRECTA del servicio, sorteando por completo la visibilidad de la UI.
        $second = app(ReviewedProposalService::class)->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_REJECT,
            $reviewer,
            ['notes' => 'segundo congelamiento invocado a mano'],
        );

        $this->assertSame(ReviewedProposalService::RESULT_ALREADY_HAS_PENDING_PROPOSAL, $second['result']);
        $this->assertSame(1, TaxonomyReviewedProposal::where('candidate_link_id', $candidate->id)
            ->where('status', TaxonomyReviewedProposal::STATUS_PENDING_APPLY)->count(), 'Un segundo congelamiento no debe duplicar la propuesta (DB-enforced, no por visibilidad de UI).');
        $this->assertSame(TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING, TaxonomyReviewedProposal::where('candidate_link_id', $candidate->id)->sole()->decision, 'La primera decisión congelada es inmutable: el segundo intento no la reescribe.');
    }

    // =========================================================================================
    // TASK-0006D (Issue #2 comentario `5949253156`), PARTE 2: corrección del defecto de UX
    // reportado en el comentario `5947407519`.
    // =========================================================================================

    #[Test]
    public function freeze_review_is_hidden_once_the_candidate_has_a_live_pending_apply_proposal(): void
    {
        // EL DEFECTO EXACTO: `freeze()` deja el candidato en `pending` a propósito (congelar no
        // publica ni resuelve nada), así que la condición vieja - `status === pending` + permiso -
        // seguía siendo verdadera y la grilla ofrecía un SEGUNDO camino de revisión en la misma fila
        // que ya mostraba `CONGELADA_PENDIENTE`.
        $candidate = $this->mappableCandidate();
        $target = TaxonomyCanonicalConcept::create(['canonical_name_es' => 'zzz_task0006d_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);
        $reviewer = $this->authorizedReviewer();

        Livewire::actingAs($reviewer)
            ->test(ListTaxonomyCandidateConceptLinks::class)
            ->assertTableActionVisible('freezeReview', $candidate)
            ->assertTableActionHidden('viewReviewedProposal', $candidate);

        $frozen = app(ReviewedProposalService::class)->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING,
            $reviewer,
            ['target_concept_id' => $target->id],
        );
        $this->assertSame(ReviewedProposalService::RESULT_FROZEN, $frozen['result']);

        // El candidato sigue `pending` - eso es lo correcto y es justo lo que confundía al chequeo
        // anterior.
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $candidate->fresh()->status);

        Livewire::actingAs($reviewer)
            ->test(ListTaxonomyCandidateConceptLinks::class)
            ->assertTableActionHidden('freezeReview', $candidate->fresh())
            ->assertTableActionVisible('viewReviewedProposal', $candidate->fresh());
    }

    #[Test]
    public function freeze_review_stays_available_after_an_aborted_proposal_because_abort_does_not_resolve_the_candidate(): void
    {
        // Comportamiento ELEGIDO y documentado (no una omisión): solo `PENDING_APPLY` esconde el
        // botón. `abort()` es terminal para la PROPUESTA pero no toca la fila fuente, así que el
        // candidato queda legítimamente pendiente de una revisión nueva - que es el camino de
        // recuperación documentado para una propuesta obsoleta. Esconder el botón acá dejaría al
        // candidato sin ninguna forma de volver a revisarse, y el índice único parcial
        // (`WHERE status = 'PENDING_APPLY'`) tampoco lo impide.
        $candidate = $this->newConceptCandidate();
        $reviewer = $this->authorizedReviewer();

        $frozen = app(ReviewedProposalService::class)->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_CREATE_NEW,
            $reviewer,
            ['new_concept_name' => 'zzz_task0006d_aborted_'.uniqid()],
        );

        // Fuerza el aborto por obsolescencia: un concepto nuevo cambia `dryRunInputFingerprint()`.
        TaxonomyCanonicalConcept::create(['canonical_name_es' => 'zzz_task0006d_stale_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);
        $aborted = app(ReviewedProposalService::class)->apply($frozen['proposal']->id, 'TASK-0006D test-suite 0006');
        $this->assertSame(ReviewedProposalService::RESULT_ABORTED, $aborted['result']);
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $candidate->fresh()->status, 'abort() no resuelve el candidato.');

        Livewire::actingAs($reviewer)
            ->test(ListTaxonomyCandidateConceptLinks::class)
            ->assertTableActionVisible('freezeReview', $candidate->fresh())
            ->assertTableActionHidden('viewReviewedProposal', $candidate->fresh());
    }

    // =========================================================================================
    // TASK-0006E (Issue #2 comentario `5955148859`), requisitos 7 y 8: tras supersedir, el candidato
    // vuelve a ser revisable por la UI normal, y la propuesta vieja queda visible y de solo lectura.
    // =========================================================================================

    #[Test]
    public function a_candidate_becomes_reviewable_again_through_the_normal_ui_after_supersession(): void
    {
        $candidate = $this->newConceptCandidate();
        $reviewer = $this->authorizedReviewer();

        $frozen = app(ReviewedProposalService::class)->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED,
            $reviewer,
            ['context_reason' => 'demasiado genérico (fixture 0006E)'],
        );
        $this->assertSame(ReviewedProposalService::RESULT_FROZEN, $frozen['result']);

        // Con la propuesta viva, el endurecimiento de TASK-0006D sigue en pie: no hay segundo freeze.
        Livewire::actingAs($reviewer)
            ->test(ListTaxonomyCandidateConceptLinks::class)
            ->assertTableActionHidden('freezeReview', $candidate->fresh())
            ->assertTableActionVisible('viewReviewedProposal', $candidate->fresh());

        // Se vuelve obsoleta y se supersede.
        TaxonomyCanonicalConcept::create(['canonical_name_es' => 'zzz_task0006e_state_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);
        $outcome = app(ReviewedProposalService::class)->supersedeStaleProposal(
            $frozen['proposal']->id,
            'Issue #2 — TASK-0006E UI test-suite 5955148859',
            'Obsoleta: el grafo de conceptos cambió desde la revisión.',
        );
        $this->assertSame(ReviewedProposalService::RESULT_SUPERSEDED, $outcome['result']);

        // AHORA el candidato vuelve a estar revisable, y el enlace a la propuesta viva desaparece
        // porque ya no hay ninguna viva - la vieja quedó como historia.
        Livewire::actingAs($reviewer)
            ->test(ListTaxonomyCandidateConceptLinks::class)
            ->assertTableActionVisible('freezeReview', $candidate->fresh())
            ->assertTableActionHidden('viewReviewedProposal', $candidate->fresh());

        // Y la decisión nueva la congela una PERSONA por la UI, contra el estado actual.
        Livewire::actingAs($reviewer)
            ->test(ListTaxonomyCandidateConceptLinks::class)
            ->callTableAction('freezeReview', $candidate->fresh(), data: [
                'decision' => TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED,
                'context_reason' => 'revisión nueva desde la evidencia actual',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, TaxonomyReviewedProposal::where('candidate_link_id', $candidate->id)
            ->where('status', TaxonomyReviewedProposal::STATUS_PENDING_APPLY)->count());
        $this->assertSame(TaxonomyReviewedProposal::STATUS_SUPERSEDED, $frozen['proposal']->fresh()->status,
            'La propuesta vieja sigue supersedida: coexisten, no se reemplazan.');
    }

    #[Test]
    public function the_detail_page_explains_that_the_previous_review_was_superseded(): void
    {
        $candidate = $this->newConceptCandidate();
        $reviewer = $this->authorizedReviewer();

        $frozen = app(ReviewedProposalService::class)->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED,
            $reviewer,
            ['context_reason' => 'demasiado genérico (fixture 0006E)'],
        );

        TaxonomyCanonicalConcept::create(['canonical_name_es' => 'zzz_task0006e_detail_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);
        app(ReviewedProposalService::class)->supersedeStaleProposal(
            $frozen['proposal']->id,
            'Issue #2 — TASK-0006E UI test-suite 5955148859',
            'Obsoleta: el grafo de conceptos cambió desde la revisión.',
        );

        // Quien revise de nuevo merece saber que YA hubo una decisión y que caducó - sin eso el
        // candidato parece nunca revisado y se pierde el contexto de por qué volvió a la cola.
        $notice = \App\Filament\Resources\TaxonomyCandidateConceptLinkResource::frozenProposalNotice($candidate->fresh());

        $this->assertStringContainsString('SUPERSEDIDA', $notice);
        $this->assertStringContainsString('#'.$frozen['proposal']->id, $notice);
        $this->assertStringContainsString('revisión NUEVA', $notice);
    }

    #[Test]
    public function the_reviewed_proposal_link_is_hidden_from_a_reviewer_who_cannot_view_that_proposal(): void
    {
        // La visibilidad del enlace se resuelve con la policy del recurso de destino, así que nunca
        // lleva a un 403/404. Un usuario sin permiso de VER candidatos no puede ver la propuesta.
        $candidate = $this->mappableCandidate();
        $target = TaxonomyCanonicalConcept::create(['canonical_name_es' => 'zzz_task0006d_link_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);

        app(ReviewedProposalService::class)->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING,
            $this->authorizedReviewer(),
            ['target_concept_id' => $target->id],
        );

        $viewOnlyNoCandidateView = User::factory()->create();
        $viewOnlyNoCandidateView->givePermissionTo(['view_any_taxonomy::candidate::concept::link']);

        Livewire::actingAs($viewOnlyNoCandidateView)
            ->test(ListTaxonomyCandidateConceptLinks::class)
            ->assertTableActionHidden('freezeReview', $candidate->fresh())
            ->assertTableActionHidden('viewReviewedProposal', $candidate->fresh());
    }

    #[Test]
    public function a_candidate_with_a_live_proposal_is_not_offered_as_a_bilingual_convergence_partner(): void
    {
        // Misma corrección en la OTRA puerta de entrada al mismo defecto: si un candidato con
        // propuesta viva se eligiera como socio de convergencia, el índice único parcial lo
        // rechazaría y `freezeBilingualConceptGroup()` revertiría el grupo COMPLETO - el revisor
        // perdería también la revisión de los demás miembros.
        $reviewer = $this->authorizedReviewer();
        $available = $this->newConceptCandidate();
        $alreadyFrozen = $this->newConceptCandidate();

        app(ReviewedProposalService::class)->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $alreadyFrozen->id,
            TaxonomyReviewedProposal::DECISION_CREATE_NEW,
            $reviewer,
            ['new_concept_name' => 'zzz_task0006d_partner_'.uniqid()],
        );

        $options = \App\Filament\Resources\TaxonomyCandidateConceptLinkResource::convergenceCandidateOptions($this->newConceptCandidate());

        $this->assertArrayHasKey($available->id, $options, 'Un candidato pending sin propuesta viva debe seguir ofreciéndose.');
        $this->assertArrayNotHasKey($alreadyFrozen->id, $options, 'Un candidato con propuesta PENDING_APPLY no puede participar de un grupo nuevo.');
    }

    /**
     * Se verifica el TEXTO que la página de detalle muestra, no el HTML renderizado. Motivo concreto:
     * renderizar esa página necesita `ext-intl` (`TextEntry::make('confidence')->numeric(4)` llama a
     * `Number::format()`), que no está disponible en este entorno local - una limitación de entorno
     * PREEXISTENTE, verificada corriendo el test heredado
     * `viewing_a_propose_new_concept_candidate_with_duplicate_signals_does_not_500` contra el archivo
     * SIN estos cambios: falla igual, con la misma excepción. Esa cobertura de renderizado sigue
     * siendo del test heredado (verde en staging); acá se prueba la lógica que agrega esta tarea, de
     * forma determinística y sin depender de la extensión.
     */
    #[Test]
    public function the_detail_page_states_that_a_frozen_proposal_already_exists(): void
    {
        $candidate = $this->mappableCandidate();
        $target = TaxonomyCanonicalConcept::create(['canonical_name_es' => 'zzz_task0006d_detail_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);

        $this->assertStringContainsString(
            'Ninguna propuesta viva',
            \App\Filament\Resources\TaxonomyCandidateConceptLinkResource::frozenProposalNotice($candidate),
        );

        $frozen = app(ReviewedProposalService::class)->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING,
            $this->authorizedReviewer(),
            ['target_concept_id' => $target->id],
        );

        $notice = \App\Filament\Resources\TaxonomyCandidateConceptLinkResource::frozenProposalNotice($candidate->fresh());

        $this->assertStringContainsString('Propuesta #'.$frozen['proposal']->id, $notice);
        $this->assertStringContainsString('PENDIENTE DE APLICACIÓN', $notice);
        $this->assertStringContainsString('Propuestas revisadas', $notice, 'El texto tiene que dirigir al revisor a la pantalla correcta.');
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $candidate->fresh()->status);
    }

    #[Test]
    public function source_drift_protection_still_applies_to_a_proposal_frozen_through_the_ui(): void
    {
        // No es una duplicación de ReviewedProposalServiceTest - prueba específicamente que el
        // camino de la UI produce EXACTAMENTE el mismo tipo de fila protegida que el servicio
        // directo, no una versión "más floja" por pasar por Filament.
        $candidate = $this->newConceptCandidate();
        $reviewerChosenName = 'Nombre elegido por el revisor '.uniqid();

        Livewire::actingAs($this->authorizedReviewer())
            ->test(ListTaxonomyCandidateConceptLinks::class)
            ->callTableAction('freezeReview', $candidate, data: [
                'decision' => TaxonomyReviewedProposal::DECISION_CREATE_NEW,
                'new_concept_name' => $reviewerChosenName,
            ])
            ->assertHasNoTableActionErrors();

        $proposal = TaxonomyReviewedProposal::where('candidate_link_id', $candidate->id)->sole();

        // Deriva la fuente DESPUÉS de congelar vía la UI.
        $candidate->update(['suggested_new_concept_name' => 'nombre editado después del freeze de la UI '.uniqid()]);

        $outcome = app(ReviewedProposalService::class)->apply($proposal->id, 'TASK-0005 UI test-suite');

        $this->assertSame(ReviewedProposalService::RESULT_ABORTED, $outcome['result']);
        $this->assertSame(ReviewedProposalService::ABORT_SOURCE_DRIFT, $outcome['abort_reason']);
        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_canonical_concepts')
            ->where('canonical_name_es', $reviewerChosenName)->count(), 'No debe crearse ningún concepto tras el drift.');
    }

    #[Test]
    public function the_ui_does_not_expose_any_apply_or_publish_table_action(): void
    {
        $candidate = $this->mappableCandidate();
        $component = Livewire::actingAs($this->authorizedReviewer())->test(ListTaxonomyCandidateConceptLinks::class);

        foreach (['apply', 'publish', 'applyReview', 'execute', 'approve', 'resolveNewConcept'] as $forbiddenAction) {
            $threw = false;
            try {
                $component->callTableAction($forbiddenAction, $candidate);
            } catch (\Throwable $e) {
                $threw = true;
            }
            $this->assertTrue($threw, "La acción '{$forbiddenAction}' no debería existir en esta UI (sección D del comentario 5914793857: ningún camino de ejecución real desde el reviewer ordinario).");
        }
    }

    /**
     * Incidente 503/500 (TASK-0002): `CanonicalConceptApplyService::apply()` encola candidatos
     * PROPOSE_NEW_CONCEPT con `signals = ['possible_existing_concepts' => [...]]` (ver línea 229
     * de ese servicio) - un valor ANIDADO, no plano. `KeyValueEntry::make('signals')` en la página
     * de detalle exige string=>string y llama `htmlspecialchars()` sobre cada valor; con PHP 8 eso
     * es un TypeError en cuanto el valor es un array, incluso uno vacío `[]`. Sin cambios respecto
     * a la ronda anterior - protegido explícitamente por el comentario `5914793857`, sección F
     * ("existing TASK-0002 nested-signals rendering regression remains protected").
     */
    #[Test]
    public function viewing_a_propose_new_concept_candidate_with_duplicate_signals_does_not_500(): void
    {
        $term = TaxonomyTerm::create([
            'external_id' => 'task0002-503-'.uniqid('', true),
            'term' => 'zzz_task0002_503_'.uniqid('', true),
            'language' => 'es',
            'canonical_term' => 'zzz_task0002_503_'.uniqid('', true),
            'term_type' => TaxonomyTerm::TERM_TYPE_TECHNICAL,
            'region' => [], 'negative_context' => [], 'positive_context' => [],
            'mapping_review_status' => TaxonomyTerm::MAPPING_UNMAPPED,
        ]);

        $candidate = TaxonomyCandidateConceptLink::create([
            'suggested_term_id' => $term->id,
            'suggested_concept_id' => null,
            'suggested_new_concept_name' => 'zzz task0002 503 concept',
            'signals' => ['possible_existing_concepts' => [
                ['concept_id' => 1, 'concept_name' => 'zzz existing concept', 'score' => 0.8, 'tier' => 'REVIEW'],
            ]],
            'confidence' => 0.0,
            'tier' => TaxonomyCandidateConceptLink::TIER_REVIEW,
            'status' => TaxonomyCandidateConceptLink::STATUS_PENDING,
        ]);

        $response = $this->actingAs($this->authorizedReviewer())
            ->get(\App\Filament\Resources\TaxonomyCandidateConceptLinkResource::getUrl('view', ['record' => $candidate]));

        $response->assertOk();
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $candidate->fresh()->status, 'Abrir la página de detalle no debe mutar el candidato.');
    }
}
