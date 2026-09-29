<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\TaxonomyConceptRelationResource\Pages\CreateTaxonomyConceptRelation;
use App\Filament\Resources\TaxonomyConceptRelationResource\Pages\EditTaxonomyConceptRelation;
use App\Models\TaxonomyCanonicalConcept;
use App\Models\TaxonomyConceptRelation;
use App\Models\User;
use App\Services\Taxonomy\ReviewedProposalService;
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
        // INSERT crudo (no ::create()) - TASK-0004 hallazgo HIGH-2 bloquea crear/guardar vía
        // Eloquent con status=approved fuera del apply() autorizado de Phase C2; esto es solo
        // fixture de "ya existen aprobadas", no la acción bajo prueba (que es la creación vía
        // Filament de la relación que CERRARÍA el ciclo).
        DB::connection('pgsql')->table('taxonomy_concept_relations')->insert([
            ['source_concept_id' => $a->id, 'target_concept_id' => $b->id, 'relation_type' => 'PART_OF',
                'weight' => 0.5, 'confidence' => 0.5, 'status' => TaxonomyConceptRelation::STATUS_APPROVED,
                'created_at' => now(), 'updated_at' => now()],
            ['source_concept_id' => $b->id, 'target_concept_id' => $c->id, 'relation_type' => 'PART_OF',
                'weight' => 0.5, 'confidence' => 0.5, 'status' => TaxonomyConceptRelation::STATUS_APPROVED,
                'created_at' => now(), 'updated_at' => now()],
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
    public function editing_a_relation_without_changing_its_endpoints_type_or_status_still_saves(): void
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
                'weight' => 0.75, // solo cambia el peso, no los endpoints/tipo/status
                'confidence' => 0.5,
                'status' => TaxonomyConceptRelation::STATUS_CANDIDATE,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(0.75, (float) $relation->fresh()->weight);
        $this->assertSame(TaxonomyConceptRelation::STATUS_CANDIDATE, $relation->fresh()->status);
    }

    /**
     * TASK-0004, re-audit HIGH-2 (Issue #2 comentario `5890113782`): esta página ya NO puede
     * aprobar/publicar una relación - eso ahora exige el flujo autorizado de Phase C2
     * (`ReviewedProposalService::freeze()` + `apply()`). Reemplaza al viejo
     * `editing_a_relation_without_changing_its_endpoints_or_type_still_saves`, que probaba
     * justamente el camino que este hallazgo pidió cerrar.
     */
    #[Test]
    public function editing_a_relation_to_approve_it_is_blocked_publication_requires_phase_c2(): void
    {
        $a = $this->concept('zzz_rel_c2gate_a_'.uniqid());
        $b = $this->concept('zzz_rel_c2gate_b_'.uniqid());
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
                'weight' => 0.75,
                'confidence' => 0.5,
                'status' => TaxonomyConceptRelation::STATUS_APPROVED,
            ])
            ->call('save');

        $fresh = $relation->fresh();
        $this->assertSame(TaxonomyConceptRelation::STATUS_CANDIDATE, $fresh->status, 'No debe quedar aprobada.');
        $this->assertSame(0.5, (float) $fresh->weight, 'Ningún campo de este submit debe persistir - se corta antes del update.');
    }

    /**
     * TASK-0003, hallazgo 6: el escenario exacto que el orquestador señaló - "a candidate may be
     * valid when queued and stale when reviewed". La relación A->B era válida cuando se encoló;
     * mientras esperaba revisión, alguien aprobó la relación SIMÉTRICA inversa B->A (mismo tipo no
     * direccional - semánticamente la misma relación, ver `DUPLICATE_VIA_SYMMETRY`). Esta es la
     * forma REAL en que el conflicto puede colarse: el índice único de la tabla es sobre la tupla
     * literal (source, target, type), así que A->B y B->A conviven sin violarlo - solo la
     * revalidación a nivel de aplicación detecta que son la misma relación. Antes de esta
     * corrección, aprobar $waiting "sin tocar sus endpoints" se consideraba `$unchanged` y NUNCA
     * revalidaba. Ahora aprobar SIEMPRE revalida.
     */
    #[Test]
    public function approving_a_relation_that_became_a_duplicate_while_it_waited_for_review_is_rejected(): void
    {
        $a = $this->concept('zzz_rel_stale_a_'.uniqid());
        $b = $this->concept('zzz_rel_stale_b_'.uniqid());

        // La simétrica inversa (B->A) ya estaba aprobada ANTES de que $waiting se creara - orden
        // deliberado: si se creara al revés, el propio guard del modelo (hallazgo 6) ya bloquearía
        // esta creación como duplicado, lo cual probaría otra cosa (creación), no la revalidación al
        // aprobar, que es lo que este test necesita aislar. INSERT crudo - TASK-0004 hallazgo HIGH-2
        // bloquea crear vía Eloquent con status=approved fuera del apply() autorizado de Phase C2.
        DB::connection('pgsql')->table('taxonomy_concept_relations')->insert([
            'source_concept_id' => $b->id, 'target_concept_id' => $a->id, 'relation_type' => 'RELATED_TO',
            'weight' => 0.5, 'confidence' => 0.9, 'status' => TaxonomyConceptRelation::STATUS_APPROVED,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // $waiting se crea DESPUÉS y directo por Eloquent (no por la página de Filament, que sí
        // habría bloqueado esto en la creación) - simula un candidato que --apply encoló sin pasar
        // por esa validación de creación manual, y que queda esperando revisión ya obsoleto.
        $waiting = TaxonomyConceptRelation::create([
            'source_concept_id' => $a->id, 'target_concept_id' => $b->id, 'relation_type' => 'RELATED_TO',
            'weight' => 0.5, 'confidence' => 0.5, 'status' => TaxonomyConceptRelation::STATUS_CANDIDATE,
        ]);

        // TASK-0004, re-audit HIGH-2: desde esta corrección, intentar aprobar por esta página queda
        // bloqueado INCONDICIONALMENTE (ver `editing_a_relation_to_approve_it_is_blocked_publication_requires_phase_c2`)
        // - el gate de Phase C2 corta ANTES de siquiera llegar a la revalidación semántica que este
        // test originalmente aislaba. El resultado esperado (sigue candidate, no se aprueba) se
        // mantiene igual - más estricto, no menos - así que el escenario se conserva como
        // regresión, aunque el guard que efectivamente lo bloquea ya no sea el mismo.
        Livewire::actingAs($this->authorizedUser())
            ->test(EditTaxonomyConceptRelation::class, ['record' => $waiting->getRouteKey()])
            ->fillForm([
                'source_concept_id' => $a->id,
                'target_concept_id' => $b->id,
                'relation_type' => 'RELATED_TO', // endpoints/tipo SIN cambiar
                'weight' => 0.5,
                'confidence' => 0.5,
                'status' => TaxonomyConceptRelation::STATUS_APPROVED, // pero SÍ se intenta aprobar
            ])
            ->call('save');

        $this->assertSame(
            TaxonomyConceptRelation::STATUS_CANDIDATE,
            $waiting->fresh()->status,
            'No debe poder aprobarse - ya existe una relación equivalente aprobada (y, además, la publicación por esta página ya está bloqueada de por sí).'
        );
    }

    /**
     * Hallazgo 6, capa de modelo: el guard de `TaxonomyConceptRelation::booted()` protege incluso
     * fuera de esta página de Filament. TASK-0004, re-audit HIGH-2: el guard de modelo ahora TAMBIÉN
     * exige `ReviewedProposalService::isApplyingC2Publication()` - este test envuelve el `update()`
     * en `withC2PublicationContext()` para aislar específicamente que el chequeo SEMÁNTICO (el que
     * este test siempre probó) sigue funcionando de forma independiente del gate de bypass nuevo,
     * no para simular una publicación real autorizada.
     */
    #[Test]
    public function the_model_itself_refuses_to_be_saved_as_approved_when_no_longer_valid(): void
    {
        $a = $this->concept('zzz_rel_model_guard_a_'.uniqid());
        $b = $this->concept('zzz_rel_model_guard_b_'.uniqid());

        // Orden deliberado - ver el comentario del test anterior. INSERT crudo por el mismo motivo.
        DB::connection('pgsql')->table('taxonomy_concept_relations')->insert([
            'source_concept_id' => $b->id, 'target_concept_id' => $a->id, 'relation_type' => 'RELATED_TO',
            'weight' => 0.5, 'confidence' => 0.9, 'status' => TaxonomyConceptRelation::STATUS_APPROVED,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $waiting = TaxonomyConceptRelation::create([
            'source_concept_id' => $a->id, 'target_concept_id' => $b->id, 'relation_type' => 'RELATED_TO',
            'weight' => 0.5, 'confidence' => 0.5, 'status' => TaxonomyConceptRelation::STATUS_CANDIDATE,
        ]);

        $this->expectException(\RuntimeException::class);

        ReviewedProposalService::withC2PublicationContext(
            fn () => $waiting->update(['status' => TaxonomyConceptRelation::STATUS_APPROVED])
        );
    }
}
