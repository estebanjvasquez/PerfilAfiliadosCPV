<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\TaxonomyConceptRelationResource\Pages\ListTaxonomyConceptRelations;
use App\Models\TaxonomyCanonicalConcept;
use App\Models\TaxonomyConceptRelation;
use App\Models\TaxonomyReviewedProposal;
use App\Models\User;
use App\Services\Taxonomy\ReviewedProposalService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-0005 (Issue #2 comentario `5914793857`), sección B: prueba el wiring real de la acción
 * `freezeReview` en `TaxonomyConceptRelationResource` - que efectivamente llame a
 * `ReviewedProposalService::freeze()` (ya probado exhaustivamente a nivel de servicio en
 * `ReviewedProposalServiceTest`) y produzca el mismo estado en la base de datos real, sin publicar
 * nunca la relación desde el panel.
 *
 * `DatabaseTransactions` sobre `pgsql` - nada persiste al terminar la clase.
 */
class TaxonomyConceptRelationReviewTest extends TestCase
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
            'view_any_taxonomy::concept::relation',
            'view_taxonomy::concept::relation',
            'update_taxonomy::concept::relation',
        ]);

        return $user;
    }

    private function readOnlyReviewer(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            'view_any_taxonomy::concept::relation',
            'view_taxonomy::concept::relation',
        ]);

        return $user;
    }

    private function concept(string $name): TaxonomyCanonicalConcept
    {
        return TaxonomyCanonicalConcept::create([
            'canonical_name_es' => $name,
            'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE,
        ]);
    }

    private function candidateRelation(): TaxonomyConceptRelation
    {
        $a = $this->concept('zzz_task0005_rel_a_'.uniqid());
        $b = $this->concept('zzz_task0005_rel_b_'.uniqid());

        return TaxonomyConceptRelation::create([
            'source_concept_id' => $a->id, 'target_concept_id' => $b->id, 'relation_type' => 'RELATED_TO',
            'weight' => 0.5, 'confidence' => 0.5, 'status' => TaxonomyConceptRelation::STATUS_CANDIDATE,
        ]);
    }

    #[Test]
    public function an_authorized_reviewer_sees_the_freeze_review_action_for_a_candidate_relation(): void
    {
        $relation = $this->candidateRelation();

        Livewire::actingAs($this->authorizedReviewer())
            ->test(ListTaxonomyConceptRelations::class)
            ->assertTableActionVisible('freezeReview', $relation);
    }

    #[Test]
    public function a_read_only_reviewer_does_not_see_the_freeze_review_action(): void
    {
        $relation = $this->candidateRelation();

        Livewire::actingAs($this->readOnlyReviewer())
            ->test(ListTaxonomyConceptRelations::class)
            ->assertSuccessful()
            ->assertTableActionHidden('freezeReview', $relation);
    }

    #[Test]
    public function freeze_review_publish_relation_freezes_without_approving(): void
    {
        $relation = $this->candidateRelation();
        $reviewer = $this->authorizedReviewer();

        Livewire::actingAs($reviewer)
            ->test(ListTaxonomyConceptRelations::class)
            ->callTableAction('freezeReview', $relation, data: [
                'decision' => TaxonomyReviewedProposal::DECISION_PUBLISH_RELATION,
            ])
            ->assertHasNoTableActionErrors();

        $fresh = $relation->fresh();
        $this->assertSame(TaxonomyConceptRelation::STATUS_CANDIDATE, $fresh->status, 'freeze() nunca aprueba - la relación sigue candidate.');

        $proposal = TaxonomyReviewedProposal::where('concept_relation_id', $relation->id)->sole();
        $this->assertSame(TaxonomyReviewedProposal::DECISION_PUBLISH_RELATION, $proposal->decision);
        $this->assertSame(TaxonomyReviewedProposal::STATUS_PENDING_APPLY, $proposal->status);
        $this->assertSame($relation->source_concept_id, $proposal->decision_payload['source_concept_id']);
        $this->assertSame($relation->target_concept_id, $proposal->decision_payload['target_concept_id']);
        $this->assertSame($relation->relation_type, $proposal->decision_payload['relation_type']);
        $this->assertSame($reviewer->id, $proposal->reviewer_id);
    }

    #[Test]
    public function freeze_review_reject_freezes_without_changing_the_relation(): void
    {
        $relation = $this->candidateRelation();

        Livewire::actingAs($this->authorizedReviewer())
            ->test(ListTaxonomyConceptRelations::class)
            ->callTableAction('freezeReview', $relation, data: [
                'decision' => TaxonomyReviewedProposal::DECISION_REJECT,
                'notes' => 'No corroborada por suficiente evidencia.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(TaxonomyConceptRelation::STATUS_CANDIDATE, $relation->fresh()->status);

        $proposal = TaxonomyReviewedProposal::where('concept_relation_id', $relation->id)->sole();
        $this->assertSame(TaxonomyReviewedProposal::DECISION_REJECT, $proposal->decision);
        $this->assertSame('No corroborada por suficiente evidencia.', $proposal->decision_payload['notes']);
    }

    #[Test]
    public function freeze_review_double_submit_does_not_create_a_second_pending_proposal(): void
    {
        $relation = $this->candidateRelation();
        $reviewer = $this->authorizedReviewer();

        foreach ([1, 2] as $attempt) {
            Livewire::actingAs($reviewer)
                ->test(ListTaxonomyConceptRelations::class)
                ->callTableAction('freezeReview', $relation->fresh(), data: [
                    'decision' => TaxonomyReviewedProposal::DECISION_PUBLISH_RELATION,
                ])
                ->assertHasNoTableActionErrors();
        }

        $this->assertSame(1, TaxonomyReviewedProposal::where('concept_relation_id', $relation->id)
            ->where('status', TaxonomyReviewedProposal::STATUS_PENDING_APPLY)->count());
    }

    #[Test]
    public function source_revalidation_still_applies_to_a_relation_proposal_frozen_through_the_ui(): void
    {
        // Simétrica inversa YA aprobada ANTES del freeze - apply() debe re-validar y abortar, igual
        // que si el freeze hubiera venido del servicio directo (ver ReviewedProposalServiceTest).
        $a = $this->concept('zzz_task0005_sym_a_'.uniqid());
        $b = $this->concept('zzz_task0005_sym_b_'.uniqid());
        DB::connection('pgsql')->table('taxonomy_concept_relations')->insert([
            'source_concept_id' => $b->id, 'target_concept_id' => $a->id, 'relation_type' => 'RELATED_TO',
            'weight' => 0.5, 'confidence' => 0.9, 'status' => TaxonomyConceptRelation::STATUS_APPROVED,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $relation = TaxonomyConceptRelation::create([
            'source_concept_id' => $a->id, 'target_concept_id' => $b->id, 'relation_type' => 'RELATED_TO',
            'weight' => 0.5, 'confidence' => 0.5, 'status' => TaxonomyConceptRelation::STATUS_CANDIDATE,
        ]);

        Livewire::actingAs($this->authorizedReviewer())
            ->test(ListTaxonomyConceptRelations::class)
            ->callTableAction('freezeReview', $relation, data: [
                'decision' => TaxonomyReviewedProposal::DECISION_PUBLISH_RELATION,
            ])
            ->assertHasNoTableActionErrors();

        $proposal = TaxonomyReviewedProposal::where('concept_relation_id', $relation->id)->sole();
        $outcome = app(ReviewedProposalService::class)->apply($proposal->id, 'TASK-0005 UI test-suite');

        $this->assertSame(ReviewedProposalService::RESULT_ABORTED, $outcome['result']);
        $this->assertSame(ReviewedProposalService::ABORT_RELATION_INVALID_AT_APPLY_TIME, $outcome['abort_reason']);
        $this->assertSame(TaxonomyConceptRelation::STATUS_CANDIDATE, $relation->fresh()->status);
    }

    #[Test]
    public function the_ui_does_not_expose_any_apply_or_publish_table_action(): void
    {
        $relation = $this->candidateRelation();
        $component = Livewire::actingAs($this->authorizedReviewer())->test(ListTaxonomyConceptRelations::class);

        foreach (['apply', 'publish', 'applyReview', 'execute', 'approve'] as $forbiddenAction) {
            $threw = false;
            try {
                $component->callTableAction($forbiddenAction, $relation);
            } catch (\Throwable $e) {
                $threw = true;
            }
            $this->assertTrue($threw, "La acción '{$forbiddenAction}' no debería existir en esta UI.");
        }
    }
}
