<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\TaxonomyConceptRelationResource;
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

    /**
     * TASK-0005 re-audit (comentario `5917275454`, corrección 2): una relación cuyo ciclo de revisión
     * C2 YA TERMINÓ, o sea editable por el CRUD administrativo acotado que se conservó.
     *
     * Hace falta porque desde esa corrección las relaciones `candidate` ya no son editables en
     * absoluto (`TaxonomyConceptRelationResource::canEdit()` devuelve false y
     * `EditRecord::authorizeAccess()` corta con 403), así que los tests de esta suite que prueban la
     * VALIDACIÓN de la página de edición necesitan una fila que la página todavía sirva. Se usa
     * `rejected` y no `approved`: el guard de publicación de TASK-0004 impide crear/guardar
     * `approved` vía Eloquent fuera del `apply()` autorizado, y además editar una relación publicada
     * es otra discusión (ver la observación registrada en el audit de esta corrección).
     */
    private function relationWithReviewCycleOver(string $prefix): TaxonomyConceptRelation
    {
        return TaxonomyConceptRelation::create([
            'source_concept_id' => $this->concept($prefix.'_src_'.uniqid())->id,
            'target_concept_id' => $this->concept($prefix.'_tgt_'.uniqid())->id,
            'relation_type' => 'RELATED_TO',
            'weight' => 0.5, 'confidence' => 0.5,
            'status' => TaxonomyConceptRelation::STATUS_REJECTED,
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

    /**
     * TASK-0005 re-audit (corrección 2): el escenario es el mismo de siempre - editar los endpoints
     * hasta duplicar otra relación existente debe rechazarse - pero ahora corre sobre una fila cuyo
     * ciclo de revisión ya terminó, porque una `candidate` ni siquiera abre la página de edición.
     * La propiedad que este test protege (la página REVALIDA al cambiar endpoints, no guarda a
     * ciegas) se sigue ejercitando de verdad; lo que cambió es sobre qué fila puede ejercitarse.
     */
    #[Test]
    public function editing_a_relation_to_duplicate_another_existing_one_is_rejected(): void
    {
        $toEdit = $this->relationWithReviewCycleOver('zzz_rel_edit');
        $a = $toEdit->source_concept_id;
        $c = $toEdit->target_concept_id;
        $b = $this->concept('zzz_rel_edit_dup_'.uniqid())->id;

        // La relación A->B que el submit intentará duplicar.
        TaxonomyConceptRelation::create([
            'source_concept_id' => $a, 'target_concept_id' => $b, 'relation_type' => 'RELATED_TO',
            'weight' => 0.5, 'confidence' => 0.5, 'status' => TaxonomyConceptRelation::STATUS_CANDIDATE,
        ]);

        Livewire::actingAs($this->authorizedUser())
            ->test(EditTaxonomyConceptRelation::class, ['record' => $toEdit->getRouteKey()])
            ->fillForm([
                'source_concept_id' => $a,
                'target_concept_id' => $b, // ahora duplica la relación A->B ya existente
                'relation_type' => 'RELATED_TO',
                'weight' => 0.5,
                'confidence' => 0.5,
                'status' => TaxonomyConceptRelation::STATUS_REJECTED,
            ])
            ->call('save');

        $this->assertSame($c, $toEdit->fresh()->target_concept_id, 'El registro editado NO debe haberse guardado con el destino duplicado.');
    }

    /**
     * TASK-0005 re-audit (corrección 2): mismo motivo que el test anterior - la edición benigna
     * (solo el peso) se prueba sobre una fila fuera del ciclo C2. El caso "candidata" ya no es
     * "guarda igual" sino "no se puede editar", y eso se prueba explícitamente en
     * `a_candidate_relation_cannot_even_open_the_edit_page` más abajo.
     */
    #[Test]
    public function editing_a_relation_without_changing_its_endpoints_type_or_status_still_saves(): void
    {
        $relation = $this->relationWithReviewCycleOver('zzz_rel_noop');

        Livewire::actingAs($this->authorizedUser())
            ->test(EditTaxonomyConceptRelation::class, ['record' => $relation->getRouteKey()])
            ->fillForm([
                'source_concept_id' => $relation->source_concept_id,
                'target_concept_id' => $relation->target_concept_id,
                'relation_type' => 'RELATED_TO',
                'weight' => 0.75, // solo cambia el peso, no los endpoints/tipo/status
                'confidence' => 0.5,
                'status' => TaxonomyConceptRelation::STATUS_REJECTED,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(0.75, (float) $relation->fresh()->weight);
        $this->assertSame(TaxonomyConceptRelation::STATUS_REJECTED, $relation->fresh()->status);
    }

    /**
     * TASK-0005 re-audit (comentario `5917275454`, corrección 2): el caso que antes estaba implícito
     * en los dos tests de arriba y ahora es una propiedad por derecho propio - una relación que
     * participa del ciclo C2 no abre siquiera la página de edición.
     */
    #[Test]
    public function a_candidate_relation_cannot_even_open_the_edit_page(): void
    {
        $a = $this->concept('zzz_rel_c2locked_a_'.uniqid());
        $b = $this->concept('zzz_rel_c2locked_b_'.uniqid());
        $candidate = TaxonomyConceptRelation::create([
            'source_concept_id' => $a->id, 'target_concept_id' => $b->id, 'relation_type' => 'RELATED_TO',
            'weight' => 0.5, 'confidence' => 0.5, 'status' => TaxonomyConceptRelation::STATUS_CANDIDATE,
        ]);

        $this->actingAs($this->authorizedUser())
            ->get(TaxonomyConceptRelationResource::getUrl('edit', ['record' => $candidate]))
            ->assertForbidden();

        $fresh = $candidate->fresh();
        $this->assertSame(TaxonomyConceptRelation::STATUS_CANDIDATE, $fresh->status);
        $this->assertSame(0.5, (float) $fresh->weight, 'Nada debe haber cambiado.');
    }

    /**
     * TASK-0004, re-audit HIGH-2 (Issue #2 comentario `5890113782`): esta página ya NO puede
     * aprobar/publicar una relación - eso ahora exige el flujo autorizado de Phase C2
     * (`ReviewedProposalService::freeze()` + `apply()`).
     *
     * TASK-0005 re-audit (corrección 2): corre sobre una fila fuera del ciclo C2 a propósito. Para
     * una `candidate` el bloqueo ahora ocurre ANTES (403 al abrir la página, ver
     * `a_candidate_relation_cannot_even_open_the_edit_page`), lo que dejaría sin ejercitar el guard
     * HIGH-2 de `handleRecordUpdate()`, que sigue existiendo y debe seguir probado. Con una fila
     * editable se alcanza ese guard de verdad: la publicación se corta ahí, no por la ruta.
     */
    #[Test]
    public function editing_a_relation_to_approve_it_is_blocked_publication_requires_phase_c2(): void
    {
        $relation = $this->relationWithReviewCycleOver('zzz_rel_c2gate');

        Livewire::actingAs($this->authorizedUser())
            ->test(EditTaxonomyConceptRelation::class, ['record' => $relation->getRouteKey()])
            ->fillForm([
                'source_concept_id' => $relation->source_concept_id,
                'target_concept_id' => $relation->target_concept_id,
                'relation_type' => 'RELATED_TO',
                'weight' => 0.75,
                'confidence' => 0.5,
                'status' => TaxonomyConceptRelation::STATUS_APPROVED,
            ])
            ->call('save');

        $fresh = $relation->fresh();
        $this->assertSame(TaxonomyConceptRelation::STATUS_REJECTED, $fresh->status, 'No debe quedar aprobada.');
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

        // TASK-0004, re-audit HIGH-2: intentar aprobar por esta página quedó bloqueado
        // INCONDICIONALMENTE - el gate de Phase C2 corta ANTES de llegar a la revalidación semántica
        // que este test originalmente aislaba.
        //
        // TASK-0005 re-audit (corrección 2): ahora se corta incluso antes - `$waiting` es
        // `candidate`, o sea participa del ciclo C2, así que la página de edición devuelve 403 y el
        // submit no existe como camino. El resultado esperado del escenario es el mismo (sigue
        // candidate, no se aprueba), más estricto, no menos, así que se conserva como regresión.
        // La revalidación semántica en sí (el objeto original del hallazgo 6 de TASK-0003) sigue
        // probada de forma aislada y directa en
        // `the_model_itself_refuses_to_be_saved_as_approved_when_no_longer_valid`, más abajo.
        $this->actingAs($this->authorizedUser())
            ->get(TaxonomyConceptRelationResource::getUrl('edit', ['record' => $waiting]))
            ->assertForbidden();

        $this->assertSame(
            TaxonomyConceptRelation::STATUS_CANDIDATE,
            $waiting->fresh()->status,
            'No debe poder aprobarse - ya existe una relación equivalente aprobada, y la edición de una fila en revisión C2 está cerrada de por sí.'
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
