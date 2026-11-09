<?php

namespace Tests\Feature\Taxonomy;

use App\Filament\Resources\TaxonomyCategoryResource\Pages\EditTaxonomyCategory;
use App\Filament\Resources\TaxonomyCategoryResource\RelationManagers\TermRelationsRelationManager;
use App\Models\TaxonomyAuditLog;
use App\Models\TaxonomyTermCpvRelation;
use App\Models\User;
use App\Services\Taxonomy\TermCategoryLinker;
use App\Services\TaxonomyCategorySearch;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Taxonomy\Concerns\TaxonomySearchFixtures;
use Tests\TestCase;

/**
 * TASK-0014 (Issue #2 `6736283026`, punto 4): asociar desde la ficha de una categoría un término que
 * ya existe en el diccionario.
 *
 * Qué se prueba:
 * - la relación nueva queda en revisión y no llega al buscador de la empresa hasta aprobarse;
 * - no se duplican relaciones vivas;
 * - una relación rechazada solo se reabre si se pide y con el permiso de revisar;
 * - la aprobación usa el flujo existente: `taxonomy_publish`, motivo y auditoría.
 */
class TermCategoryLinkTest extends TestCase
{
    use DatabaseTransactions;
    use TaxonomySearchFixtures;

    protected $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.mcp.embed_url' => null, 'services.mcp.embed_token' => null]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->buildBalancinTree();
    }

    /** @param  list<string>  $extra */
    private function user(array $extra = []): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p, 'web'), array_merge([
            'view_any_taxonomy::category', 'view_taxonomy::category', 'update_taxonomy::category',
            'view_any_taxonomy::term::cpv::relation', 'view_taxonomy::term::cpv::relation',
        ], $extra)));

        return $user->fresh();
    }

    private function linker(): TermCategoryLinker
    {
        return app(TermCategoryLinker::class);
    }

    #[Test]
    public function a_new_link_stays_in_review_is_audited_and_does_not_reach_the_company_search(): void
    {
        $user = $this->user(['create_taxonomy::term::cpv::relation']);
        $this->actingAs($user);

        $outcome = $this->linker()->link($user, $this->nodes['cabezal'], $this->balancinTerm, 0.7, 'El cliente lo usa así');

        $this->assertSame(TermCategoryLinker::RESULT_CREATED, $outcome['result']);
        $relation = $outcome['relation']->fresh();
        $this->assertSame(TaxonomyTermCpvRelation::STATUS_NEEDS_REVIEW, $relation->status);
        $this->assertSame($this->nodes['cabezal']->id, $relation->category_id);
        $this->assertSame($this->nodes['cabezal']->code, $relation->cpv_code);
        $this->assertSame(TermCategoryLinker::SOURCE, $relation->source);
        $this->assertNull($relation->reviewed_by);

        $log = TaxonomyAuditLog::query()->where('entity_type', TaxonomyTermCpvRelation::class)->where('entity_id', $relation->id)->sole();
        $this->assertSame(TaxonomyTermCpvRelation::STATUS_NEEDS_REVIEW, $log->new_value);
        $this->assertStringContainsString('El cliente lo usa así', $log->reason);
        $this->assertSame($user->id, $log->user_id);

        $companyIds = app(TaxonomyCategorySearch::class)->search("balancín {$this->tok}", 50)->pluck('category_id');
        $this->assertNotContains($this->nodes['cabezal']->id, $companyIds, 'Sin aprobar no lleva a la empresa a la categoría.');
        $this->assertSame(1, TaxonomyTermCpvRelation::query()->where('term_id', $this->balancinTerm->id)->where('status', 'approved')->count(),
            'No se modificó ninguna relación existente.');
    }

    #[Test]
    public function linking_requires_the_create_relation_permission(): void
    {
        $user = $this->user();

        $this->expectException(AuthorizationException::class);
        $this->linker()->link($user, $this->nodes['cabezal'], $this->balancinTerm, 0.7, 'motivo');
    }

    #[Test]
    public function an_existing_live_relation_is_reported_and_never_duplicated(): void
    {
        $user = $this->user(['create_taxonomy::term::cpv::relation']);
        $this->actingAs($user);

        $approved = $this->linker()->link($user, $this->nodes['balancin'], $this->balancinTerm, 0.5, 'otra vez');
        $this->assertSame(TermCategoryLinker::RESULT_EXISTS, $approved['result']);
        $this->assertSame(TaxonomyTermCpvRelation::STATUS_APPROVED, $approved['relation']->status);

        $inReview = $this->linker()->link($user, $this->nodes['cabezal'], $this->wellheadTerm, 0.5, 'otra vez');
        $this->assertSame(TermCategoryLinker::RESULT_EXISTS, $inReview['result']);

        $this->assertSame(1, TaxonomyTermCpvRelation::query()->where('term_id', $this->balancinTerm->id)->count());
        $this->assertSame(1, TaxonomyTermCpvRelation::query()->where('term_id', $this->wellheadTerm->id)->count());
        $this->assertSame(0, TaxonomyAuditLog::query()->where('entity_type', TaxonomyTermCpvRelation::class)
            ->whereIn('entity_id', TaxonomyTermCpvRelation::query()->whereIn('term_id', [$this->balancinTerm->id, $this->wellheadTerm->id])->pluck('id'))
            ->count());
    }

    #[Test]
    public function a_rejected_relation_is_reopened_only_on_request_and_with_the_review_permission(): void
    {
        $rejected = $this->relation($this->balancinTerm, $this->nodes['bajo'], TaxonomyTermCpvRelation::STATUS_REJECTED);

        $creator = $this->user(['create_taxonomy::term::cpv::relation']);
        $this->actingAs($creator);
        $this->assertSame(TermCategoryLinker::RESULT_NEEDS_REOPEN,
            $this->linker()->link($creator, $this->nodes['bajo'], $this->balancinTerm, 0.5, 'nuevo análisis')['result']);
        $this->assertSame(TaxonomyTermCpvRelation::STATUS_REJECTED, $rejected->fresh()->status, 'Sin pedirlo no se toca.');

        try {
            $this->linker()->link($creator, $this->nodes['bajo'], $this->balancinTerm, 0.5, 'nuevo análisis', reopen: true);
            $this->fail('Reabrir sin taxonomy_edit_relations debía fallar.');
        } catch (AuthorizationException) {
            $this->assertSame(TaxonomyTermCpvRelation::STATUS_REJECTED, $rejected->fresh()->status);
        }

        $reviewer = $this->user(['create_taxonomy::term::cpv::relation', 'taxonomy_edit_relations']);
        $this->actingAs($reviewer);
        $outcome = $this->linker()->link($reviewer, $this->nodes['bajo'], $this->balancinTerm, 0.5, 'nuevo análisis', reopen: true);

        $this->assertSame(TermCategoryLinker::RESULT_REOPENED, $outcome['result']);
        $this->assertSame($rejected->id, $outcome['relation']->id, 'Reabre la misma fila, no crea otra.');
        $this->assertSame(TaxonomyTermCpvRelation::STATUS_NEEDS_REVIEW, $rejected->fresh()->status);
        $this->assertSame(2, TaxonomyTermCpvRelation::query()->where('term_id', $this->balancinTerm->id)->count());
        $log = TaxonomyAuditLog::query()->where('entity_type', TaxonomyTermCpvRelation::class)->where('entity_id', $rejected->id)->sole();
        $this->assertSame(TaxonomyTermCpvRelation::STATUS_REJECTED, $log->old_value);
    }

    #[Test]
    public function the_category_tab_lists_terms_with_status_and_associates_through_the_ui(): void
    {
        $user = $this->user(['create_taxonomy::term::cpv::relation']);

        Livewire::actingAs($user)
            ->test(TermRelationsRelationManager::class, ['ownerRecord' => $this->nodes['cabezal'], 'pageClass' => EditTaxonomyCategory::class])
            ->loadTable()
            ->assertCanSeeTableRecords(TaxonomyTermCpvRelation::query()->where('category_id', $this->nodes['cabezal']->id)->get())
            ->assertSee("wellhead{$this->tok}")
            ->assertSee('En revisión')
            ->assertTableActionVisible('asociarTermino')
            ->callTableAction('asociarTermino', data: [
                'term_id' => $this->balancinTerm->id,
                'weight' => 0.75,
                'motivo' => 'Prueba desde la ficha',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(TaxonomyTermCpvRelation::STATUS_NEEDS_REVIEW, TaxonomyTermCpvRelation::query()
            ->where('term_id', $this->balancinTerm->id)->where('category_id', $this->nodes['cabezal']->id)->value('status'));
    }

    #[Test]
    public function without_the_create_permission_the_associate_action_is_hidden(): void
    {
        Livewire::actingAs($this->user())
            ->test(TermRelationsRelationManager::class, ['ownerRecord' => $this->nodes['cabezal'], 'pageClass' => EditTaxonomyCategory::class])
            ->loadTable()
            ->assertTableActionHidden('asociarTermino');
    }

    #[Test]
    public function approval_from_the_tab_keeps_the_publish_permission_reason_and_audit(): void
    {
        $pending = TaxonomyTermCpvRelation::query()->where('category_id', $this->nodes['cabezal']->id)->sole();

        Livewire::actingAs($this->user(['taxonomy_edit_relations']))
            ->test(TermRelationsRelationManager::class, ['ownerRecord' => $this->nodes['cabezal'], 'pageClass' => EditTaxonomyCategory::class])
            ->loadTable()
            ->assertTableActionHidden('approve', $pending);

        $publisher = $this->user(['taxonomy_publish']);
        Livewire::actingAs($publisher)
            ->test(TermRelationsRelationManager::class, ['ownerRecord' => $this->nodes['cabezal'], 'pageClass' => EditTaxonomyCategory::class])
            ->loadTable()
            ->callTableAction('approve', $pending, data: ['reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);
        $this->assertSame(TaxonomyTermCpvRelation::STATUS_NEEDS_REVIEW, $pending->fresh()->status);

        Livewire::actingAs($publisher)
            ->test(TermRelationsRelationManager::class, ['ownerRecord' => $this->nodes['cabezal'], 'pageClass' => EditTaxonomyCategory::class])
            ->loadTable()
            ->callTableAction('approve', $pending, data: ['reason' => 'Revisado con el cliente']);

        $this->assertSame(TaxonomyTermCpvRelation::STATUS_APPROVED, $pending->fresh()->status);
        $this->assertSame($publisher->id, (int) $pending->fresh()->reviewed_by);
        $this->assertSame('Revisado con el cliente', TaxonomyAuditLog::query()
            ->where('entity_type', TaxonomyTermCpvRelation::class)->where('entity_id', $pending->id)->latest('id')->value('reason'));
        $this->assertContains($this->nodes['cabezal']->id,
            app(TaxonomyCategorySearch::class)->search("wellhead{$this->tok}", 50)->pluck('category_id'),
            'Aprobada, ya lleva a la empresa a la categoría.');
    }
}
