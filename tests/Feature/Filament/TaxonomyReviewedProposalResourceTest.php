<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\TaxonomyReviewedProposalResource;
use App\Models\TaxonomyCandidateConceptLink;
use App\Models\TaxonomyCanonicalConcept;
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

    private function authorizedViewer(): User
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

    private function frozenProposal(User $reviewer): TaxonomyReviewedProposal
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
        $concept = TaxonomyCanonicalConcept::create([
            'canonical_name_es' => 'zzz_task0005_proposal_concept_'.uniqid('', true),
            'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE,
        ]);
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

        return $outcome['proposal'];
    }

    #[Test]
    public function an_authorized_viewer_can_list_reviewed_proposals(): void
    {
        $reviewer = $this->authorizedViewer();
        $this->frozenProposal($reviewer);

        $this->actingAs($reviewer)
            ->get(TaxonomyReviewedProposalResource::getUrl('index'))
            ->assertOk();
    }

    #[Test]
    public function an_authorized_viewer_can_open_the_detail_page_without_a_500(): void
    {
        $reviewer = $this->authorizedViewer();
        $proposal = $this->frozenProposal($reviewer);

        $this->actingAs($reviewer)
            ->get(TaxonomyReviewedProposalResource::getUrl('view', ['record' => $proposal]))
            ->assertOk();
    }

    #[Test]
    public function a_user_with_no_permission_cannot_open_the_list(): void
    {
        $reviewer = $this->authorizedViewer();
        $this->frozenProposal($reviewer);

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
}
