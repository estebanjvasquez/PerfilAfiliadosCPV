<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\TaxonomyConceptRelationResource\Pages\CreateTaxonomyConceptRelation;
use App\Filament\Resources\TaxonomyConceptRelationResource\Pages\EditTaxonomyConceptRelation;
use App\Models\TaxonomyCanonicalConcept;
use App\Models\TaxonomyConceptRelation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase B.1 (sección 12 del pedido): antes de esta entrega, `TaxonomyConceptRelationResource` era
 * un CRUD administrativo puro - guardaba lo que fuera sin pasar por
 * `CanonicalConceptBuilderService::validateConceptRelationProposal()` (la misma validación que ya
 * protege las propuestas auto-generadas del Builder: auto-relación, tipo inactivo, duplicado
 * exacto/simétrico/vía inverso, ciclos). Esta suite prueba que un humano proponiendo una relación a
 * mano por el panel ahora pasa por la MISMA validación.
 */
class TaxonomyConceptRelationValidationTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function authorizedUser(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            'view_any_taxonomy::concept::relation',
            'create_taxonomy::concept::relation',
            'update_taxonomy::concept::relation',
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

    #[Test]
    public function creating_a_relation_between_a_concept_and_itself_is_rejected(): void
    {
        $a = $this->concept('zzz_rel_self_'.uniqid());
        $countBefore = DB::connection('pgsql')->table('taxonomy_concept_relations')->count();

        Livewire::actingAs($this->authorizedUser())
            ->test(CreateTaxonomyConceptRelation::class)
            ->fillForm([
                'source_concept_id' => $a->id,
                'target_concept_id' => $a->id,
                'relation_type' => 'RELATED_TO',
                'weight' => 0.5,
                'confidence' => 0.5,
                'status' => TaxonomyConceptRelation::STATUS_CANDIDATE,
            ])
            ->call('create');

        $this->assertSame($countBefore, DB::connection('pgsql')->table('taxonomy_concept_relations')->count());
    }

    #[Test]
    public function creating_a_duplicate_relation_is_rejected(): void
    {
        $a = $this->concept('zzz_rel_dup_a_'.uniqid());
        $b = $this->concept('zzz_rel_dup_b_'.uniqid());
        TaxonomyConceptRelation::create([
            'source_concept_id' => $a->id, 'target_concept_id' => $b->id, 'relation_type' => 'RELATED_TO',
            'weight' => 0.5, 'confidence' => 0.5, 'status' => TaxonomyConceptRelation::STATUS_CANDIDATE,
        ]);
        $countBefore = DB::connection('pgsql')->table('taxonomy_concept_relations')->count();

        Livewire::actingAs($this->authorizedUser())
            ->test(CreateTaxonomyConceptRelation::class)
            ->fillForm([
                'source_concept_id' => $a->id,
                'target_concept_id' => $b->id,
                'relation_type' => 'RELATED_TO',
                'weight' => 0.5,
                'confidence' => 0.5,
                'status' => TaxonomyConceptRelation::STATUS_CANDIDATE,
            ])
            ->call('create');

        $this->assertSame($countBefore, DB::connection('pgsql')->table('taxonomy_concept_relations')->count(), 'No debe crear un duplicado exacto.');
    }

    #[Test]
    public function creating_a_symmetric_duplicate_in_reverse_order_is_rejected(): void
    {
        $a = $this->concept('zzz_rel_sym_a_'.uniqid());
        $b = $this->concept('zzz_rel_sym_b_'.uniqid());
        TaxonomyConceptRelation::create([
            'source_concept_id' => $a->id, 'target_concept_id' => $b->id, 'relation_type' => 'RELATED_TO',
            'weight' => 0.5, 'confidence' => 0.5, 'status' => TaxonomyConceptRelation::STATUS_CANDIDATE,
        ]);
        $countBefore = DB::connection('pgsql')->table('taxonomy_concept_relations')->count();

        // (B, A, RELATED_TO) es la MISMA relación que (A, B, RELATED_TO) - RELATED_TO no es direccional.
        Livewire::actingAs($this->authorizedUser())
            ->test(CreateTaxonomyConceptRelation::class)
            ->fillForm([
                'source_concept_id' => $b->id,
                'target_concept_id' => $a->id,
                'relation_type' => 'RELATED_TO',
                'weight' => 0.5,
                'confidence' => 0.5,
                'status' => TaxonomyConceptRelation::STATUS_CANDIDATE,
            ])
            ->call('create');

        $this->assertSame($countBefore, DB::connection('pgsql')->table('taxonomy_concept_relations')->count());
    }

    #[Test]
    public function creating_a_relation_that_would_close_a_cycle_is_rejected(): void
    {
        $a = $this->concept('zzz_rel_cycle_a_'.uniqid());
        $b = $this->concept('zzz_rel_cycle_b_'.uniqid());
        $c = $this->concept('zzz_rel_cycle_c_'.uniqid());
        // A PART_OF B, B PART_OF C ya existen - proponer C PART_OF A cerraría el ciclo A->B->C->A.
        TaxonomyConceptRelation::create([
            'source_concept_id' => $a->id, 'target_concept_id' => $b->id, 'relation_type' => 'PART_OF',
            'weight' => 0.5, 'confidence' => 0.5, 'status' => TaxonomyConceptRelation::STATUS_APPROVED,
        ]);
        TaxonomyConceptRelation::create([
            'source_concept_id' => $b->id, 'target_concept_id' => $c->id, 'relation_type' => 'PART_OF',
            'weight' => 0.5, 'confidence' => 0.5, 'status' => TaxonomyConceptRelation::STATUS_APPROVED,
        ]);
        $countBefore = DB::connection('pgsql')->table('taxonomy_concept_relations')->count();

        Livewire::actingAs($this->authorizedUser())
            ->test(CreateTaxonomyConceptRelation::class)
            ->fillForm([
                'source_concept_id' => $c->id,
                'target_concept_id' => $a->id,
                'relation_type' => 'PART_OF',
                'weight' => 0.5,
                'confidence' => 0.5,
                'status' => TaxonomyConceptRelation::STATUS_CANDIDATE,
            ])
            ->call('create');

        $this->assertSame($countBefore, DB::connection('pgsql')->table('taxonomy_concept_relations')->count(), 'No debe crear una relación que cierre un ciclo.');
    }

    #[Test]
    public function creating_a_valid_non_duplicate_relation_succeeds(): void
    {
        $a = $this->concept('zzz_rel_ok_a_'.uniqid());
        $b = $this->concept('zzz_rel_ok_b_'.uniqid());
        $countBefore = DB::connection('pgsql')->table('taxonomy_concept_relations')->count();

        Livewire::actingAs($this->authorizedUser())
            ->test(CreateTaxonomyConceptRelation::class)
            ->fillForm([
                'source_concept_id' => $a->id,
                'target_concept_id' => $b->id,
                'relation_type' => 'RELATED_TO',
                'weight' => 0.5,
                'confidence' => 0.5,
                'status' => TaxonomyConceptRelation::STATUS_CANDIDATE,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($countBefore + 1, DB::connection('pgsql')->table('taxonomy_concept_relations')->count());
    }

    #[Test]
    public function editing_a_relation_to_duplicate_another_existing_one_is_rejected(): void
    {
        $a = $this->concept('zzz_rel_edit_a_'.uniqid());
        $b = $this->concept('zzz_rel_edit_b_'.uniqid());
        $c = $this->concept('zzz_rel_edit_c_'.uniqid());
        TaxonomyConceptRelation::create([
            'source_concept_id' => $a->id, 'target_concept_id' => $b->id, 'relation_type' => 'RELATED_TO',
            'weight' => 0.5, 'confidence' => 0.5, 'status' => TaxonomyConceptRelation::STATUS_CANDIDATE,
        ]);
        $toEdit = TaxonomyConceptRelation::create([
            'source_concept_id' => $a->id, 'target_concept_id' => $c->id, 'relation_type' => 'RELATED_TO',
            'weight' => 0.5, 'confidence' => 0.5, 'status' => TaxonomyConceptRelation::STATUS_CANDIDATE,
        ]);

        Livewire::actingAs($this->authorizedUser())
            ->test(EditTaxonomyConceptRelation::class, ['record' => $toEdit->getRouteKey()])
            ->fillForm([
                'source_concept_id' => $a->id,
                'target_concept_id' => $b->id, // ahora duplica la relación A->B ya existente
                'relation_type' => 'RELATED_TO',
                'weight' => 0.5,
                'confidence' => 0.5,
                'status' => TaxonomyConceptRelation::STATUS_CANDIDATE,
            ])
            ->call('save');

        $this->assertSame($c->id, $toEdit->fresh()->target_concept_id, 'El registro editado NO debe haberse guardado con el destino duplicado.');
    }

    #[Test]
    public function editing_a_relation_without_changing_its_endpoints_or_type_still_saves(): void
    {
        $a = $this->concept('zzz_rel_noop_a_'.uniqid());
        $b = $this->concept('zzz_rel_noop_b_'.uniqid());
        $relation = TaxonomyConceptRelation::create([
            'source_concept_id' => $a->id, 'target_concept_id' => $b->id, 'relation_type' => 'RELATED_TO',
            'weight' => 0.5, 'confidence' => 0.5, 'status' => TaxonomyConceptRelation::STATUS_CANDIDATE,
        ]);

        Livewire::actingAs($this->authorizedUser())
            ->test(EditTaxonomyConceptRelation::class, ['record' => $relation->getRouteKey()])
            ->fillForm([
                'source_concept_id' => $a->id,
                'target_concept_id' => $b->id,
                'relation_type' => 'RELATED_TO',
                'weight' => 0.75, // solo cambia el peso, no los endpoints/tipo
                'confidence' => 0.5,
                'status' => TaxonomyConceptRelation::STATUS_APPROVED,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(0.75, (float) $relation->fresh()->weight);
        $this->assertSame(TaxonomyConceptRelation::STATUS_APPROVED, $relation->fresh()->status);
    }
}
