<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\TaxonomyReviewedProposalResource;
use App\Filament\Resources\TaxonomyReviewedProposalResource\Pages\ListTaxonomyReviewedProposals;
use App\Models\TaxonomyCandidateConceptLink;
use App\Models\TaxonomyReviewedProposal;
use App\Models\TaxonomyTerm;
use App\Models\User;
use App\Services\Taxonomy\ReviewedProposalService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-0006B (Issue #2 comentario `5936206843`), sección B: la acción de CONFIRMACIÓN humana en
 * `TaxonomyReviewedProposalResource`.
 *
 * Lo que estos tests protegen, que es exactamente lo que la sección B pidió: que la acción exista y
 * sea alcanzable para quien tiene permiso de revisión, que NO sea alcanzable para quien no lo tiene,
 * que NO aparezca para propuestas que no requieren confirmación, que confirmar por la UI escriba la
 * confirmación sin tocar la decisión, y que esta pantalla siga sin ofrecer ningún camino de
 * APPLY/publicación.
 *
 * Ninguna fila real de la cola se usa acá: todo es fixture desechable dentro de
 * `DatabaseTransactions`.
 */
class TaxonomyReviewedProposalConfirmationUiTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function reviewer(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            'view_any_taxonomy::candidate::concept::link',
            'view_taxonomy::candidate::concept::link',
            'update_taxonomy::candidate::concept::link',
        ]);

        return $user;
    }

    /** Puede VER las propuestas de candidatos, pero no tiene permiso de revisión (`update`). */
    private function viewerWithoutReviewPermission(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo([
            'view_any_taxonomy::candidate::concept::link',
            'view_taxonomy::candidate::concept::link',
        ]);

        return $user;
    }

    /**
     * Cada candidato necesita su PROPIO término: `taxonomy_candidate_concept_links` tiene un índice
     * único parcial sobre `(suggested_term_id) WHERE suggested_concept_id IS NULL` desde TASK-0003,
     * así que reusar un término para dos candidatos de concepto nuevo lo viola.
     */
    private function pendingNewConceptCandidate(): TaxonomyCandidateConceptLink
    {
        $term = TaxonomyTerm::create([
            'external_id' => 'c2b-ui-'.uniqid('', true),
            'term' => 'zzz_task0006b_ui_'.uniqid('', true),
            'language' => 'es',
            'canonical_term' => 'zzz_task0006b_ui_canon_'.uniqid('', true),
            'term_type' => TaxonomyTerm::TERM_TYPE_TECHNICAL,
            'region' => [], 'negative_context' => [], 'positive_context' => [],
            'mapping_review_status' => TaxonomyTerm::MAPPING_UNMAPPED,
        ]);

        return TaxonomyCandidateConceptLink::create([
            'suggested_term_id' => $term->id,
            'suggested_concept_id' => null,
            'suggested_new_concept_name' => 'zzz_task0006b_ui_suggested_'.uniqid('', true),
            'signals' => [], 'confidence' => 0.5, 'tier' => TaxonomyCandidateConceptLink::TIER_REVIEW,
            'status' => TaxonomyCandidateConceptLink::STATUS_PENDING,
        ]);
    }

    private function agentPreparedProposal(User $reviewer): TaxonomyReviewedProposal
    {
        $candidate = $this->pendingNewConceptCandidate();

        $frozen = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $candidate->id,
            TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED,
            $reviewer,
            ['context_reason' => 'zzz_task0006b_ui motivo preparado por agente'],
            TaxonomyReviewedProposal::ACTOR_AGENT,
        );

        return $frozen['proposal'];
    }

    /**
     * NOTA DE ENTORNO: estos tests NO llaman `loadTable`. El panel aplica `deferLoading()` a todas
     * las tablas, así que `loadTable` renderiza la vista de paginación, que llama `Number::format()`
     * y necesita `ext-intl` - ausente en este entorno local por política de Application Control
     * (gap preexistente, verde en staging; documentado desde TASK-0005). Pasar el registro
     * explícitamente a las aserciones de acción evita ese render sin debilitar nada de lo que se
     * prueba: es el mismo patrón ya aprobado en `TaxonomyConceptExplorerTest`.
     */
    #[Test]
    public function the_confirmation_action_is_offered_to_a_reviewer_for_an_agent_prepared_proposal(): void
    {
        $reviewer = $this->reviewer();
        $proposal = $this->agentPreparedProposal($reviewer);

        Livewire::actingAs($reviewer)
            ->test(ListTaxonomyReviewedProposals::class)
            ->assertTableActionVisible('confirmPreparedDecision', $proposal);
    }

    #[Test]
    public function the_confirmation_action_is_hidden_from_someone_who_can_only_view(): void
    {
        // Confirmar es un acto de REVISIÓN: exige el permiso `update` del tipo de origen, no alcanza
        // con poder ver la propuesta.
        $reviewer = $this->reviewer();
        $proposal = $this->agentPreparedProposal($reviewer);
        $viewer = $this->viewerWithoutReviewPermission();

        // La policy es la salvaguarda real; `visible()` solo la refleja.
        $this->actingAs($viewer);
        $this->assertFalse($viewer->can('confirm', $proposal));

        Livewire::actingAs($viewer)
            ->test(ListTaxonomyReviewedProposals::class)
            ->assertTableActionHidden('confirmPreparedDecision', $proposal);
    }

    #[Test]
    public function the_confirmation_action_is_hidden_for_a_proposal_that_does_not_require_confirmation(): void
    {
        $reviewer = $this->reviewer();

        // Una propuesta decidida por el propio revisor no pide confirmación, así que la acción no
        // debe ofrecerse: evita que "confirmar" se vuelva un paso ritual sobre todo.
        $selfDecided = (new ReviewedProposalService())->freeze(
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK,
            $this->pendingNewConceptCandidate()->id,
            TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED,
            $reviewer,
            ['context_reason' => 'zzz_task0006b_ui decidido por el revisor'],
        );

        Livewire::actingAs($reviewer)
            ->test(ListTaxonomyReviewedProposals::class)
            ->assertTableActionHidden('confirmPreparedDecision', $selfDecided['proposal']);
    }

    #[Test]
    public function confirming_through_the_ui_records_the_confirmer_without_touching_the_decision(): void
    {
        $reviewer = $this->reviewer();
        $proposal = $this->agentPreparedProposal($reviewer);
        $frozenFingerprint = $proposal->payload_fingerprint;
        $frozenPayload = $proposal->decision_payload;

        Livewire::actingAs($reviewer)
            ->test(ListTaxonomyReviewedProposals::class)
            ->mountTableAction('confirmPreparedDecision', $proposal)
            ->setTableActionData([
                'confirmation_reference' => 'Issue #2 comentario 5936206843',
                'confirmation_note' => 'confirmado desde la UI en el test',
                'deliberate' => true,
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $fresh = $proposal->fresh();
        $this->assertSame($reviewer->id, (int) $fresh->confirmed_by_id);
        $this->assertNotNull($fresh->confirmed_at);
        $this->assertSame('Issue #2 comentario 5936206843', $fresh->confirmation_reference);
        // La decisión congelada y su fingerprint quedan intactos.
        $this->assertSame($frozenFingerprint, $fresh->payload_fingerprint);
        $this->assertSame($frozenPayload, $fresh->decision_payload);
        // Y el candidato de origen SIGUE SIN PUBLICAR - confirmar no publica nada.
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $fresh->candidateLink->fresh()->status);
        $this->assertSame(TaxonomyReviewedProposal::STATUS_PENDING_APPLY, $fresh->status);
        $this->assertNull($fresh->applied_at);
    }

    #[Test]
    public function the_confirmation_modal_shows_that_an_agent_prepared_the_decision(): void
    {
        // Requisito literal de la sección B: mostrar la procedencia "without pretending the account
        // holder made the agent-prepared decision".
        $reviewer = $this->reviewer();
        $proposal = $this->agentPreparedProposal($reviewer);

        Livewire::actingAs($reviewer)
            ->test(ListTaxonomyReviewedProposals::class)
            ->mountTableAction('confirmPreparedDecision', $proposal)
            // La evidencia del modal declara la procedencia REAL en vez de presentar al titular de
            // la cuenta como autor de una decisión que no redactó.
            ->assertSee('AGENTE')
            // Y el modal dice explícitamente que esto no es aplicar ni publicar.
            ->assertSee('NO aplica ni publica nada');
    }

    #[Test]
    public function the_confirmation_action_disappears_once_the_proposal_is_confirmed(): void
    {
        $reviewer = $this->reviewer();
        $proposal = $this->agentPreparedProposal($reviewer);

        // TASK-0006C: la confirmación se hace por la ACCIÓN de la UI, no llamando al servicio desde
        // consola - ese camino ahora se rechaza por canal (`RESULT_CHANNEL_NOT_HUMAN`), que es
        // exactamente la corrección del re-audit `5938949812`.
        Livewire::actingAs($reviewer)
            ->test(ListTaxonomyReviewedProposals::class)
            ->mountTableAction('confirmPreparedDecision', $proposal)
            ->setTableActionData([
                'confirmation_reference' => 'Issue #2 comentario 5936206843',
                'deliberate' => true,
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertTrue($proposal->fresh()->isHumanConfirmed());

        Livewire::actingAs($reviewer)
            ->test(ListTaxonomyReviewedProposals::class)
            ->assertTableActionHidden('confirmPreparedDecision', $proposal->fresh());
    }

    #[Test]
    public function the_confirmation_action_becomes_available_again_after_the_provenance_correction(): void
    {
        // TASK-0006C (Issue #2 `5939903005`, «HUMAN UI FOLLOW-UP»): tras anular una confirmación con
        // procedencia inválida, la propuesta tiene que volver a ser CONFIRMABLE por la UI - es la
        // condición para que el dueño pueda cerrarla personalmente.
        $reviewer = $this->reviewer();
        $proposal = $this->agentPreparedProposal($reviewer);

        // Confirmación legítima por la UI...
        Livewire::actingAs($reviewer)
            ->test(ListTaxonomyReviewedProposals::class)
            ->mountTableAction('confirmPreparedDecision', $proposal)
            ->setTableActionData([
                'confirmation_reference' => 'Issue #2 comentario 5936206843',
                'deliberate' => true,
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertTrue($proposal->fresh()->isHumanConfirmed());

        // ...anulada por la corrección de gobernanza...
        (new ReviewedProposalService())->invalidateConfirmation(
            $proposal->id,
            'Issue #2 — explicit owner authorization following orchestrator comment 5939882569',
            'procedencia de confirmación inválida',
        );

        // ...y la acción vuelve a estar disponible para un revisor autenticado.
        Livewire::actingAs($reviewer)
            ->test(ListTaxonomyReviewedProposals::class)
            ->assertTableActionVisible('confirmPreparedDecision', $proposal->fresh());

        $this->assertTrue($proposal->fresh()->awaitsHumanConfirmation());
        $this->assertTrue($proposal->fresh()->hasInvalidatedConfirmation());
    }

    #[Test]
    public function the_detail_page_surfaces_the_invalidated_confirmation_trail(): void
    {
        // La corrección tiene que ser auditable desde la propia pantalla, sin leer taxonomy_audit_log.
        $reviewer = $this->reviewer();
        $proposal = $this->agentPreparedProposal($reviewer);

        Livewire::actingAs($reviewer)
            ->test(ListTaxonomyReviewedProposals::class)
            ->mountTableAction('confirmPreparedDecision', $proposal)
            ->setTableActionData([
                'confirmation_reference' => 'Issue #2 comentario 5936206843',
                'deliberate' => true,
            ])
            ->callMountedTableAction();

        (new ReviewedProposalService())->invalidateConfirmation(
            $proposal->id,
            'Issue #2 — explicit owner authorization following orchestrator comment 5939882569',
            'procedencia de confirmación inválida',
        );

        $this->actingAs($reviewer)
            ->get(TaxonomyReviewedProposalResource::getUrl('view', ['record' => $proposal->fresh()]))
            ->assertOk()
            ->assertSee('Corrección de procedencia de confirmación')
            ->assertSee('ninguna cuenta de persona ejecutó esta corrección');
    }

    #[Test]
    public function the_reviewed_proposal_screen_still_offers_no_apply_or_publish_path(): void
    {
        // Invariante de TASK-0005 sección D que esta tarea NO puede debilitar: la UI de revisión no
        // tiene ningún botón de aplicar/publicar. Se verifica sobre la lista de acciones REAL del
        // resource, no sobre el HTML.
        $reviewer = $this->reviewer();
        $this->actingAs($reviewer);

        $table = Livewire::test(ListTaxonomyReviewedProposals::class)->instance()->getTable();
        $actionNames = array_map(fn ($action) => $action->getName(), $table->getActions());

        $this->assertSame(['view', 'confirmPreparedDecision'], $actionNames);
        foreach ($actionNames as $name) {
            $this->assertStringNotContainsStringIgnoringCase('apply', $name);
            $this->assertStringNotContainsStringIgnoringCase('publish', $name);
        }

        // Y el resource sigue sin páginas de escritura.
        $this->assertSame(['index', 'view'], array_keys(TaxonomyReviewedProposalResource::getPages()));
        $this->assertFalse(TaxonomyReviewedProposalResource::canCreate());
    }
}
