<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\TaxonomyReviewedProposalResource;
use App\Filament\Resources\TaxonomyReviewedProposalResource\Pages\ListTaxonomyReviewedProposals;
use App\Models\TaxonomyCandidateConceptLink;
use App\Models\TaxonomyCanonicalConcept;
use App\Models\TaxonomyConceptRelation;
use App\Models\TaxonomyReviewedProposal;
use App\Models\TaxonomyTerm;
use App\Models\User;
use App\Services\Taxonomy\ReviewedProposalService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-0005 (Issue #2 comentario `5914793857`), sección C: `TaxonomyReviewedProposalResource` es la
 * UI de solo lectura para inspeccionar propuestas C2 congeladas (candidato/decisión/revisor/
 * timestamps/fingerprint/estado de ejecución) sin exponer ningún camino de aplicación real.
 *
 * TASK-0005 re-audit (comentario `5917275454`, corrección 1): cubre además que la autorización
 * respete el TIPO DE ORIGEN de cada propuesta - quien solo puede ver candidatos no puede ver
 * propuestas de relación ni al listar ni por URL directa, y viceversa.
 */
class TaxonomyReviewedProposalResourceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** Puede congelar ambos tipos - se usa solo para CREAR las fixtures, nunca como sujeto de prueba. */
    private function fixtureReviewer(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            'view_any_taxonomy::candidate::concept::link',
            'view_taxonomy::candidate::concept::link',
            'update_taxonomy::candidate::concept::link',
            'view_any_taxonomy::concept::relation',
            'view_taxonomy::concept::relation',
            'update_taxonomy::concept::relation',
        ]);

        return $user;
    }

    private function candidateOnlyViewer(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            'view_any_taxonomy::candidate::concept::link',
            'view_taxonomy::candidate::concept::link',
        ]);

        return $user;
    }

    private function relationOnlyViewer(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            'view_any_taxonomy::concept::relation',
            'view_taxonomy::concept::relation',
        ]);

        return $user;
    }

    private function bothTypesViewer(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            'view_any_taxonomy::candidate::concept::link',
            'view_taxonomy::candidate::concept::link',
            'view_any_taxonomy::concept::relation',
            'view_taxonomy::concept::relation',
        ]);

        return $user;
    }

    private function unauthorizedUser(): User
    {
        return User::factory()->create();
    }

    private function concept(string $prefix): TaxonomyCanonicalConcept
    {
        return TaxonomyCanonicalConcept::create([
            'canonical_name_es' => $prefix.uniqid('', true),
            'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE,
        ]);
    }

    private function candidateLinkProposal(User $reviewer): TaxonomyReviewedProposal
    {
        $term = TaxonomyTerm::create([
            'external_id' => 'task0005-proposal-'.uniqid('', true),
            'term' => 'zzz_task0005_proposal_'.uniqid('', true),
            'language' => 'es',
            'canonical_term' => 'zzz_task0005_proposal_'.uniqid('', true),
            'term_type' => TaxonomyTerm::TERM_TYPE_TECHNICAL,
            'region' => [], 'negative_context' => [], 'positive_context' => [],
            'mapping_review_status' => TaxonomyTerm::MAPPING_UNMAPPED,
        ]);
        $concept = $this->concept('zzz_task0005_proposal_concept_');
        $candidate = TaxonomyCandidateConceptLink::create([
            'suggested_term_id' => $term->id,
            'suggested_concept_id' => $concept->id,
            'signals' => [], 'confidence' => 0.5, 'tier' => TaxonomyCandidateConceptLink::TIER_REVIEW,
            'status' => TaxonomyCandidateConceptLink::STATUS_PENDING,
        ]);

        $outcome = app(ReviewedProposalService::class)->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING,
            $reviewer,
            ['target_concept_id' => $concept->id],
        );

        $this->assertSame(ReviewedProposalService::RESULT_FROZEN, $outcome['result']);

        return $outcome['proposal'];
    }

    private function relationProposal(User $reviewer): TaxonomyReviewedProposal
    {
        $relation = TaxonomyConceptRelation::create([
            'source_concept_id' => $this->concept('zzz_task0005_prop_rel_a_')->id,
            'target_concept_id' => $this->concept('zzz_task0005_prop_rel_b_')->id,
            'relation_type' => 'RELATED_TO',
            'weight' => 0.5, 'confidence' => 0.5,
            'status' => TaxonomyConceptRelation::STATUS_CANDIDATE,
        ]);

        $outcome = app(ReviewedProposalService::class)->freeze(
            TaxonomyReviewedProposal::TYPE_CONCEPT_RELATION,
            $relation->id,
            TaxonomyReviewedProposal::DECISION_PUBLISH_RELATION,
            $reviewer,
            [],
        );

        $this->assertSame(ReviewedProposalService::RESULT_FROZEN, $outcome['result']);

        return $outcome['proposal'];
    }

    /** @return array{0: TaxonomyReviewedProposal, 1: TaxonomyReviewedProposal} */
    private function oneProposalOfEachType(): array
    {
        $fixtureReviewer = $this->fixtureReviewer();

        return [$this->candidateLinkProposal($fixtureReviewer), $this->relationProposal($fixtureReviewer)];
    }

    #[Test]
    public function an_authorized_viewer_can_list_reviewed_proposals(): void
    {
        $this->oneProposalOfEachType();

        $this->actingAs($this->bothTypesViewer())
            ->get(TaxonomyReviewedProposalResource::getUrl('index'))
            ->assertOk();
    }

    #[Test]
    public function an_authorized_viewer_can_open_the_detail_page_without_a_500(): void
    {
        [$candidateProposal, $relationProposal] = $this->oneProposalOfEachType();
        $viewer = $this->bothTypesViewer();

        foreach ([$candidateProposal, $relationProposal] as $proposal) {
            $this->actingAs($viewer)
                ->get(TaxonomyReviewedProposalResource::getUrl('view', ['record' => $proposal]))
                ->assertOk();
        }
    }

    #[Test]
    public function a_user_with_no_permission_cannot_open_the_list(): void
    {
        $this->oneProposalOfEachType();

        $this->actingAs($this->unauthorizedUser())
            ->get(TaxonomyReviewedProposalResource::getUrl('index'))
            ->assertForbidden();
    }

    #[Test]
    public function the_resource_does_not_expose_any_create_edit_or_delete_page(): void
    {
        $this->assertFalse(TaxonomyReviewedProposalResource::canCreate());
        $this->assertArrayNotHasKey('create', TaxonomyReviewedProposalResource::getPages());
        $this->assertArrayNotHasKey('edit', TaxonomyReviewedProposalResource::getPages());
    }

    // ---------------------------------------------------------------------------------------------
    // Corrección 1 del comentario `5917275454`: autorización por tipo de origen de la propuesta.
    // ---------------------------------------------------------------------------------------------

    /**
     * IDs de las filas que el listado del panel resuelve para este usuario.
     *
     * Se asevera sobre el QUERY de la página de listado, no sobre el HTML renderizado
     * (`assertCanSeeTableRecords`), por una limitación real del entorno local: `AdminPanelProvider`
     * aplica `deferLoading()` a todas las tablas del panel, así que hay que disparar `loadTable`
     * para que la tabla traiga filas - y ese render arrastra la vista de paginación de Filament, que
     * llama `Number::format()` y exige `ext-intl`, ausente en este Windows (la misma limitación
     * preexistente que bloquea un test de TASK-0002; en el runtime real de staging sí está).
     *
     * No es una aserción más débil: `ListRecords::getTableQuery()` devuelve TEXTUALMENTE
     * `static::getResource()::getEloquentQuery()`, que es la única fuente de filas de la tabla, y acá
     * se comprueba esa equivalencia además de la identidad exacta de las filas. Si alguien llegara a
     * override-ar `getTableQuery()` en la página, la comprobación de equivalencia falla y el test no
     * queda obsoleto en silencio.
     *
     * @return array<int>
     */
    private function visibleProposalIdsFor(User $viewer): array
    {
        $this->actingAs($viewer);

        $pageQueryMethod = new \ReflectionMethod(ListTaxonomyReviewedProposals::class, 'getTableQuery');
        $pageQueryMethod->setAccessible(true);
        $pageQuery = $pageQueryMethod->invoke(new ListTaxonomyReviewedProposals());

        $resourceQuery = TaxonomyReviewedProposalResource::getEloquentQuery();
        $this->assertSame(
            $resourceQuery->toSql(),
            $pageQuery->toSql(),
            'La página de listado debe seguir tomando sus filas del query filtrado del recurso.',
        );
        $this->assertEquals($resourceQuery->getBindings(), $pageQuery->getBindings());

        return $pageQuery->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    #[Test]
    public function a_candidate_only_viewer_does_not_see_relation_proposals_in_the_list(): void
    {
        [$candidateProposal, $relationProposal] = $this->oneProposalOfEachType();

        $visible = $this->visibleProposalIdsFor($this->candidateOnlyViewer());

        $this->assertContains((int) $candidateProposal->id, $visible);
        $this->assertNotContains((int) $relationProposal->id, $visible);
    }

    #[Test]
    public function a_relation_only_viewer_does_not_see_candidate_link_proposals_in_the_list(): void
    {
        [$candidateProposal, $relationProposal] = $this->oneProposalOfEachType();

        $visible = $this->visibleProposalIdsFor($this->relationOnlyViewer());

        $this->assertContains((int) $relationProposal->id, $visible);
        $this->assertNotContains((int) $candidateProposal->id, $visible);
    }

    #[Test]
    public function a_viewer_with_both_permissions_sees_both_proposal_types(): void
    {
        [$candidateProposal, $relationProposal] = $this->oneProposalOfEachType();

        $visible = $this->visibleProposalIdsFor($this->bothTypesViewer());

        $this->assertContains((int) $candidateProposal->id, $visible);
        $this->assertContains((int) $relationProposal->id, $visible);
    }

    // Nota: no hay variante de `visibleProposalIdsFor()` para el usuario sin permisos - no puede ni
    // montar la página (`ListRecords::authorizeAccess()` corta con 403 vía `viewAny()`). Ese caso
    // queda cubierto por `a_user_with_no_permission_cannot_open_the_list` (HTTP),
    // `an_unauthorized_viewer_sees_no_proposal_of_either_type` (policy) y el test del query filtrado.

    #[Test]
    public function a_candidate_only_viewer_cannot_open_a_relation_proposal_by_direct_url(): void
    {
        [, $relationProposal] = $this->oneProposalOfEachType();
        $viewer = $this->candidateOnlyViewer();

        // Regla a nivel de policy, independiente de la capa HTTP.
        $this->assertFalse($viewer->can('view', $relationProposal));

        // Y la URL directa queda cerrada: el query filtrado por tipo no resuelve el registro (404),
        // y de resolverlo la policy lo rechazaría (403) - cualquiera de las dos es "denegado".
        $status = $this->actingAs($viewer)
            ->get(TaxonomyReviewedProposalResource::getUrl('view', ['record' => $relationProposal]))
            ->status();

        $this->assertContains($status, [403, 404], "Se esperaba denegación, se obtuvo HTTP {$status}.");
    }

    #[Test]
    public function a_relation_only_viewer_cannot_open_a_candidate_link_proposal_by_direct_url(): void
    {
        [$candidateProposal] = $this->oneProposalOfEachType();
        $viewer = $this->relationOnlyViewer();

        $this->assertFalse($viewer->can('view', $candidateProposal));

        $status = $this->actingAs($viewer)
            ->get(TaxonomyReviewedProposalResource::getUrl('view', ['record' => $candidateProposal]))
            ->status();

        $this->assertContains($status, [403, 404], "Se esperaba denegación, se obtuvo HTTP {$status}.");
    }

    #[Test]
    public function an_unauthorized_viewer_sees_no_proposal_of_either_type(): void
    {
        [$candidateProposal, $relationProposal] = $this->oneProposalOfEachType();
        $viewer = $this->unauthorizedUser();

        $this->assertFalse($viewer->can('viewAny', TaxonomyReviewedProposal::class));
        $this->assertFalse($viewer->can('view', $candidateProposal));
        $this->assertFalse($viewer->can('view', $relationProposal));
        $this->assertFalse(TaxonomyReviewedProposalResource::canAccess());
    }

    #[Test]
    public function each_viewer_only_gets_their_own_permitted_proposal_types_from_the_scoped_query(): void
    {
        $this->oneProposalOfEachType();

        $typesFor = function (User $user): array {
            $this->actingAs($user);
            $types = TaxonomyReviewedProposalResource::getEloquentQuery()
                ->distinct()->pluck('proposal_type')->sort()->values()->all();

            return $types;
        };

        $this->assertSame([TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK], $typesFor($this->candidateOnlyViewer()));
        $this->assertSame([TaxonomyReviewedProposal::TYPE_CONCEPT_RELATION], $typesFor($this->relationOnlyViewer()));
        $this->assertSame(
            collect([TaxonomyReviewedProposal::TYPE_CONCEPT_RELATION, TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK])->sort()->values()->all(),
            $typesFor($this->bothTypesViewer()),
        );
        $this->assertSame([], $typesFor($this->unauthorizedUser()));
    }
}
