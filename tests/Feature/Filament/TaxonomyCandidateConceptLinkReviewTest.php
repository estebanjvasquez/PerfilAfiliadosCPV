<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\TaxonomyCandidateConceptLinkResource\Pages\ListTaxonomyCandidateConceptLinks;
use App\Models\TaxonomyCandidateConceptLink;
use App\Models\TaxonomyCanonicalConcept;
use App\Models\TaxonomyTerm;
use App\Models\User;
use App\Services\Taxonomy\CandidateConceptApprovalService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase B.1 (sección 17 del pedido): prueba el WIRING real del panel de Filament - que el botón
 * efectivamente invoque `CandidateConceptApprovalService` (ya probado exhaustivamente a nivel de
 * servicio en `CandidateConceptApprovalServiceTest`) y produzca el mismo estado en la base de datos
 * real. No reimplementa las pruebas de autorización/idempotencia/transacción del servicio - esas ya
 * existen; esto prueba que el Action de Filament efectivamente llega hasta ahí, con los permisos de
 * Shield reales.
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
        // Las 3 - no solo `update_` (probado hasta ahora solo a nivel de servicio, que nunca pasa
        // por la Policy de la PÁGINA). Sin `view_any_`, Filament devuelve 403 antes de que el
        // Livewire component siquiera monte - esto lo encontró el diagnóstico de esta misma tarea
        // (ver audit/phase3_phase_b1_review_workflow.md sección 13).
        $user->givePermissionTo([
            'view_any_taxonomy::candidate::concept::link',
            'view_taxonomy::candidate::concept::link',
            'update_taxonomy::candidate::concept::link',
        ]);

        return $user;
    }

    /** Sin ningún permiso - ni siquiera `view_any_` - la página del panel devuelve 403 (probado aparte). */
    private function unauthorizedUser(): User
    {
        return User::factory()->create();
    }

    /** Puede VER la cola (viewAny/view) pero no tiene `update_` - la página monta, pero las acciones de decisión deben estar ocultas. */
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
            'external_id' => 'phaseb1-ui-test-'.uniqid('', true),
            'term' => 'zzz_phaseb1_ui_'.uniqid('', true),
            'language' => 'es',
            'canonical_term' => 'zzz_phaseb1_ui_'.uniqid('', true),
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
            'external_id' => 'phaseb1-ui-map-'.uniqid('', true),
            'term' => 'zzz_phaseb1_ui_map_'.uniqid('', true),
            'language' => 'es',
            'canonical_term' => 'zzz_phaseb1_ui_map_'.uniqid('', true),
            'term_type' => TaxonomyTerm::TERM_TYPE_TECHNICAL,
            'region' => [], 'negative_context' => [], 'positive_context' => [],
            'mapping_review_status' => TaxonomyTerm::MAPPING_UNMAPPED,
        ]);
        $concept = TaxonomyCanonicalConcept::create([
            'canonical_name_es' => 'zzz_phaseb1_ui_concept_'.uniqid('', true),
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
    public function an_authorized_reviewer_sees_the_resolve_new_concept_action_for_a_propose_new_candidate(): void
    {
        $candidate = $this->newConceptCandidate();

        Livewire::actingAs($this->authorizedReviewer())
            ->test(ListTaxonomyCandidateConceptLinks::class)
            ->assertTableActionVisible('resolveNewConcept', $candidate)
            ->assertTableActionHidden('approve', $candidate)
            ->assertTableActionHidden('reject', $candidate);
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
    public function a_read_only_reviewer_can_see_the_queue_but_not_the_decision_actions(): void
    {
        $candidate = $this->newConceptCandidate();
        $mappable = $this->mappableCandidate();

        Livewire::actingAs($this->readOnlyReviewer())
            ->test(ListTaxonomyCandidateConceptLinks::class)
            ->assertSuccessful()
            ->assertTableActionHidden('resolveNewConcept', $candidate)
            ->assertTableActionHidden('approve', $mappable)
            ->assertTableActionHidden('reject', $mappable)
            ->assertTableActionVisible('view', $candidate);
    }

    #[Test]
    public function resolve_new_concept_action_maps_to_an_existing_concept_via_the_panel(): void
    {
        $candidate = $this->newConceptCandidate();
        $existingConcept = TaxonomyCanonicalConcept::create([
            'canonical_name_es' => 'zzz_phaseb1_ui_target_'.uniqid('', true),
            'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE,
        ]);
        $conceptCountBefore = DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count();

        Livewire::actingAs($this->authorizedReviewer())
            ->test(ListTaxonomyCandidateConceptLinks::class)
            ->callTableAction('resolveNewConcept', $candidate, data: [
                'decision' => CandidateConceptApprovalService::DECISION_MAP_TO_EXISTING,
                'target_concept_id' => $existingConcept->id,
                'notes' => null,
            ])
            ->assertHasNoTableActionErrors();

        $fresh = $candidate->fresh();
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PUBLISHED, $fresh->status);
        $this->assertSame($conceptCountBefore, DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count(), 'MAP_TO_EXISTING no debe crear ningún concepto.');
        $this->assertSame(1, DB::connection('pgsql')->table('taxonomy_term_concepts')
            ->where('term_id', $candidate->suggested_term_id)->where('concept_id', $existingConcept->id)->count());
    }

    #[Test]
    public function resolve_new_concept_action_creates_a_new_concept_via_the_panel(): void
    {
        $candidate = $this->newConceptCandidate();
        $conceptCountBefore = DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count();

        Livewire::actingAs($this->authorizedReviewer())
            ->test(ListTaxonomyCandidateConceptLinks::class)
            ->callTableAction('resolveNewConcept', $candidate, data: [
                'decision' => CandidateConceptApprovalService::DECISION_CREATE_NEW,
                'notes' => null,
            ])
            ->assertHasNoTableActionErrors();

        $fresh = $candidate->fresh();
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PUBLISHED, $fresh->status);
        $this->assertSame($conceptCountBefore + 1, DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count());
    }

    #[Test]
    public function resolve_new_concept_action_rejects_with_a_structured_reason_composed_into_review_notes(): void
    {
        $candidate = $this->newConceptCandidate();

        Livewire::actingAs($this->authorizedReviewer())
            ->test(ListTaxonomyCandidateConceptLinks::class)
            ->callTableAction('resolveNewConcept', $candidate, data: [
                'decision' => CandidateConceptApprovalService::DECISION_REJECT,
                'reject_reason_category' => CandidateConceptApprovalService::REJECT_REASON_DUPLICATE,
                'notes' => null,
            ])
            ->assertHasNoTableActionErrors();

        $fresh = $candidate->fresh();
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_REJECTED, $fresh->status);
        $this->assertSame('[DUPLICATE] Duplicado de un concepto/relación ya existente', $fresh->review_notes);
    }

    #[Test]
    public function resolve_new_concept_action_does_not_duplicate_on_a_second_call_already_processed(): void
    {
        $candidate = $this->newConceptCandidate();
        $reviewer = $this->authorizedReviewer();
        $conceptCountBefore = DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count();

        $component = Livewire::actingAs($reviewer)->test(ListTaxonomyCandidateConceptLinks::class);

        $component->callTableAction('resolveNewConcept', $candidate, data: [
            'decision' => CandidateConceptApprovalService::DECISION_CREATE_NEW,
            'notes' => null,
        ])->assertHasNoTableActionErrors();

        // Segunda invocación (doble click) - la Action ya no debería estar visible (status ya no
        // es pending), consistente con el resto del panel (approve/reject se ocultan igual).
        $component = Livewire::actingAs($reviewer)->test(ListTaxonomyCandidateConceptLinks::class);
        $component->assertTableActionHidden('resolveNewConcept', $candidate->fresh());

        $this->assertSame($conceptCountBefore + 1, DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count(), 'Un segundo intento no debe crear un segundo concepto.');
    }

    #[Test]
    public function approve_action_still_works_end_to_end_through_the_panel_regression(): void
    {
        $candidate = $this->mappableCandidate();

        Livewire::actingAs($this->authorizedReviewer())
            ->test(ListTaxonomyCandidateConceptLinks::class)
            ->callTableAction('approve', $candidate)
            ->assertHasNoTableActionErrors();

        $fresh = $candidate->fresh();
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PUBLISHED, $fresh->status);
        $this->assertSame(1, DB::connection('pgsql')->table('taxonomy_term_concepts')
            ->where('term_id', $candidate->suggested_term_id)->where('concept_id', $candidate->suggested_concept_id)->count());
    }

    #[Test]
    public function reject_action_requires_a_reason_and_composes_it_into_review_notes(): void
    {
        $candidate = $this->mappableCandidate();

        Livewire::actingAs($this->authorizedReviewer())
            ->test(ListTaxonomyCandidateConceptLinks::class)
            ->callTableAction('reject', $candidate, data: [
                'reject_reason_category' => CandidateConceptApprovalService::REJECT_REASON_INSUFFICIENT_EVIDENCE,
                'notes' => null,
            ])
            ->assertHasNoTableActionErrors();

        $fresh = $candidate->fresh();
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_REJECTED, $fresh->status);
        $this->assertSame('[INSUFFICIENT_EVIDENCE] Evidencia insuficiente', $fresh->review_notes);
    }
}
