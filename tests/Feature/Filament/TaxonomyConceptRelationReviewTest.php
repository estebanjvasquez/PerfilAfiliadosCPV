<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\TaxonomyConceptRelationResource;
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
 * TASK-0005 re-audit (comentario `5917275454`, corrección 2): cubre además que el CRUD genérico no
 * pueda editar ni borrar una relación que participa del ciclo C2 - ni por acción de tabla, ni por la
 * ruta de edición, ni a nivel de modelo - mientras el CRUD administrativo sigue disponible, acotado,
 * para filas cuyo ciclo de revisión ya terminó.
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

    // ---------------------------------------------------------------------------------------------
    // Corrección 2 del comentario `5917275454`: el CRUD genérico no puede mutar/borrar una relación
    // que participa del ciclo de revisión C2.
    // ---------------------------------------------------------------------------------------------

    /** Con permisos plenos de CRUD, para probar que el bloqueo es por CICLO DE VIDA y no por permiso. */
    private function fullCrudAdmin(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            'view_any_taxonomy::concept::relation',
            'view_taxonomy::concept::relation',
            'create_taxonomy::concept::relation',
            'update_taxonomy::concept::relation',
            'delete_taxonomy::concept::relation',
        ]);

        return $user;
    }

    private function completedRelation(string $status): TaxonomyConceptRelation
    {
        $relation = $this->candidateRelation();
        // Se escribe por query builder a propósito: pasar a `approved` por Eloquent dispararía el
        // guard de publicación de TASK-0004 (que debe seguir vigente y se prueba aparte).
        DB::connection('pgsql')->table('taxonomy_concept_relations')
            ->where('id', $relation->id)->update(['status' => $status]);

        return $relation->fresh();
    }

    #[Test]
    public function a_c2_candidate_relation_exposes_no_edit_or_delete_action(): void
    {
        $relation = $this->candidateRelation();

        $this->assertTrue($relation->isUnderC2Review());

        Livewire::actingAs($this->fullCrudAdmin())
            ->test(ListTaxonomyConceptRelations::class)
            ->assertSuccessful()
            ->assertTableActionHidden('edit', $relation)
            ->assertTableActionHidden('delete', $relation)
            ->assertTableActionVisible('freezeReview', $relation);
    }

    #[Test]
    public function the_edit_route_is_forbidden_for_a_c2_candidate_relation(): void
    {
        $relation = $this->candidateRelation();

        // `canEdit()` resuelve `can('update')` contra el usuario AUTENTICADO, así que autenticar
        // primero es imprescindible: aseverar antes daría false por falta de sesión, no por el
        // bloqueo de ciclo de vida que se quiere probar.
        $this->actingAs($this->fullCrudAdmin());

        $this->assertTrue(
            $relation->isUnderC2Review(),
            'Precondición: la fila debe estar bajo revisión C2 para que el bloqueo sea el que se prueba.',
        );
        $this->assertFalse(TaxonomyConceptRelationResource::canEdit($relation));
        $this->assertFalse(TaxonomyConceptRelationResource::canDelete($relation));

        $this->get(TaxonomyConceptRelationResource::getUrl('edit', ['record' => $relation]))
            ->assertForbidden();
    }

    #[Test]
    public function a_relation_with_a_pending_frozen_proposal_cannot_be_edited_or_deleted_even_if_not_candidate(): void
    {
        $relation = $this->candidateRelation();

        Livewire::actingAs($this->authorizedReviewer())
            ->test(ListTaxonomyConceptRelations::class)
            ->callTableAction('freezeReview', $relation, data: [
                'decision' => TaxonomyReviewedProposal::DECISION_PUBLISH_RELATION,
            ])
            ->assertHasNoTableActionErrors();

        // Se fuerza el status fuera de `candidate` para aislar la SEGUNDA condición del predicado:
        // la propuesta congelada pendiente, por sí sola, ya protege la fila.
        DB::connection('pgsql')->table('taxonomy_concept_relations')
            ->where('id', $relation->id)->update(['status' => TaxonomyConceptRelation::STATUS_REJECTED]);
        $relation = $relation->fresh();

        $this->actingAs($this->fullCrudAdmin());

        $this->assertSame(TaxonomyConceptRelation::STATUS_REJECTED, $relation->status);
        $this->assertTrue($relation->isUnderC2Review(), 'Una propuesta PENDING_APPLY debe seguir protegiendo la fila.');
        $this->assertFalse(TaxonomyConceptRelationResource::canEdit($relation));
        $this->assertFalse(TaxonomyConceptRelationResource::canDelete($relation));
    }

    #[Test]
    public function the_model_itself_refuses_to_delete_a_c2_candidate_relation_from_any_entry_point(): void
    {
        $relation = $this->candidateRelation();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/ciclo de revisi/i');

        try {
            $relation->delete();
        } finally {
            $this->assertDatabaseHas('taxonomy_concept_relations', ['id' => $relation->id], 'pgsql');
        }
    }

    #[Test]
    public function rejecting_is_a_c2_decision_and_never_removes_the_source_relation(): void
    {
        $relation = $this->candidateRelation();

        Livewire::actingAs($this->authorizedReviewer())
            ->test(ListTaxonomyConceptRelations::class)
            ->callTableAction('freezeReview', $relation, data: [
                'decision' => TaxonomyReviewedProposal::DECISION_REJECT,
                'notes' => 'Rechazo revisado, no borrado.',
            ])
            ->assertHasNoTableActionErrors();

        // La fila fuente sigue existiendo como evidencia, y la decisión vive en la propuesta.
        $this->assertDatabaseHas('taxonomy_concept_relations', ['id' => $relation->id], 'pgsql');
        $this->assertSame(
            TaxonomyReviewedProposal::DECISION_REJECT,
            TaxonomyReviewedProposal::where('concept_relation_id', $relation->id)->sole()->decision,
        );
    }

    #[Test]
    public function administrative_crud_remains_available_for_a_relation_whose_review_cycle_is_over(): void
    {
        $rejected = $this->completedRelation(TaxonomyConceptRelation::STATUS_REJECTED);

        $this->actingAs($this->fullCrudAdmin());

        $this->assertFalse($rejected->isUnderC2Review());
        $this->assertTrue(TaxonomyConceptRelationResource::canEdit($rejected));
        $this->assertTrue(TaxonomyConceptRelationResource::canDelete($rejected));

        // Y el borrado realmente funciona para esa fila - el guard del modelo es acotado, no total.
        $rejected->delete();
        $this->assertDatabaseMissing('taxonomy_concept_relations', ['id' => $rejected->id], 'pgsql');
    }

    #[Test]
    public function the_task0004_publication_guard_is_still_in_force(): void
    {
        $relation = $this->candidateRelation();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Phase C2/i');

        $relation->update(['status' => TaxonomyConceptRelation::STATUS_APPROVED]);
    }
}
