<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\TaxonomyCandidateConceptLinkResource;
use App\Filament\Resources\TaxonomyCandidateConceptLinkResource\Pages\ListTaxonomyCandidateConceptLinks;
use App\Models\TaxonomyCandidateConceptLink;
use App\Models\TaxonomyCanonicalConcept;
use App\Models\TaxonomyReviewedProposal;
use App\Models\TaxonomyTerm;
use App\Models\User;
use App\Services\Taxonomy\ConceptExplorerService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-0006A (Issue #2 comentario `5929287629`), secciones A/B/C: el selector de MAP_TO_EXISTING
 * solo ofrecía los duplicados sugeridos por el Builder y truncaba la búsqueda remota a 20 sin avisar,
 * así que un término amplio/polisémico como `pipeline` podía dar falsa confianza de que las pocas
 * opciones visibles eran las únicas válidas.
 *
 * Esta suite prueba que el catálogo ACTIVO completo es descubrible, que nada se pierde en silencio,
 * y que el panel de diagnóstico expone solo asociaciones CPV YA gobernadas.
 *
 * `DatabaseTransactions` sobre `pgsql`, fixtures desechables con prefijo `zzz_task0006a_`. No toca
 * los 10 candidatos reales, las 2 relaciones reales, ni las 3 propuestas congeladas por el humano.
 */
class TaxonomyConceptExplorerTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function explorer(): ConceptExplorerService
    {
        return app(ConceptExplorerService::class);
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

    private function concept(?string $es, ?string $en = null, string $status = TaxonomyCanonicalConcept::STATUS_ACTIVE): TaxonomyCanonicalConcept
    {
        return TaxonomyCanonicalConcept::create([
            'canonical_name_es' => $es,
            'canonical_name_en' => $en,
            'status' => $status,
        ]);
    }

    private function term(string $name): TaxonomyTerm
    {
        return TaxonomyTerm::create([
            'external_id' => 'task0006a-'.uniqid('', true),
            'term' => $name,
            'language' => 'es',
            'canonical_term' => $name,
            'term_type' => TaxonomyTerm::TERM_TYPE_TECHNICAL,
            'region' => [], 'negative_context' => [], 'positive_context' => [],
            'mapping_review_status' => TaxonomyTerm::MAPPING_UNMAPPED,
        ]);
    }

    private function linkTermToConcept(TaxonomyTerm $term, TaxonomyCanonicalConcept $concept): void
    {
        DB::connection('pgsql')->table('taxonomy_term_concepts')->insert([
            'term_id' => $term->id, 'concept_id' => $concept->id, 'created_at' => now(),
        ]);
    }

    private function addAlias(TaxonomyTerm $term, string $alias): void
    {
        DB::connection('pgsql')->table('taxonomy_term_aliases')->insert([
            'term_id' => $term->id, 'alias' => $alias, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** IDs de categorías CPV REALES, solo de lectura - no se crea ninguna categoría nueva. */
    private function existingCategoryIds(int $count): array
    {
        return DB::connection('pgsql')->table('taxonomy_categories')
            ->orderBy('id')->limit($count)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** Relación término->CPV APROBADA, dentro de la transacción del test (se revierte al terminar). */
    private function approveCpvRelation(TaxonomyTerm $term, int $categoryId): void
    {
        $code = DB::connection('pgsql')->table('taxonomy_categories')->where('id', $categoryId)->value('code');

        DB::connection('pgsql')->table('taxonomy_term_cpv_relations')->insert([
            'term_id' => $term->id,
            'cpv_code' => $code,
            'category_id' => $categoryId,
            'level' => 'category',
            'relation_type' => 'lexical',
            'weight' => 0.5,
            'confidence' => 0.5,
            'source' => 'task0006a_test_fixture',
            'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function pendingCandidate(?TaxonomyCanonicalConcept $suggested = null): TaxonomyCandidateConceptLink
    {
        return TaxonomyCandidateConceptLink::create([
            'suggested_term_id' => $this->term('zzz_task0006a_cand_'.uniqid('', true))->id,
            'suggested_concept_id' => $suggested?->id,
            'suggested_new_concept_name' => $suggested ? null : 'zzz_task0006a_new_'.uniqid('', true),
            'signals' => [], 'confidence' => 0.5,
            'tier' => TaxonomyCandidateConceptLink::TIER_REVIEW,
            'status' => TaxonomyCandidateConceptLink::STATUS_PENDING,
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // Sección A — descubrimiento de conceptos
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_search_covers_the_whole_active_catalogue_not_only_builder_suggestions(): void
    {
        $suggested = $this->concept('zzz_task0006a_sugerido', 'zzz_task0006a_suggested');
        $unrelated = $this->concept('zzz_task0006a_aislado_totalmente', 'zzz_task0006a_fully_unrelated');
        $candidate = $this->pendingCandidate($suggested);

        // El concepto no sugerido no guarda ninguna relación con el candidato, y aun así la búsqueda
        // del selector lo encuentra - eso es exactamente lo que antes era imposible.
        $options = $this->explorer()->searchOptions('zzz_task0006a_aislado');

        $this->assertArrayHasKey($unrelated->id, $options);
        $this->assertArrayNotHasKey($suggested->id, $options, 'La búsqueda debe filtrar, no devolver todo.');
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $candidate->fresh()->status);
    }

    #[Test]
    public function browsing_with_an_empty_search_reports_the_real_total_of_active_concepts(): void
    {
        $before = $this->explorer()->activeConceptCount();
        $this->concept('zzz_task0006a_browse_'.uniqid('', true));

        $result = $this->explorer()->searchActiveConcepts(null);

        $this->assertSame($before + 1, $result['total'], 'El total informado debe ser el real del catálogo activo.');
        $this->assertSame($before + 1, $this->explorer()->activeConceptCount());
    }

    #[Test]
    public function more_than_twenty_matching_concepts_are_not_silently_lost(): void
    {
        $marker = 'zzz_task0006a_many'.substr(uniqid('', true), -8);
        for ($i = 1; $i <= 25; $i++) {
            $this->concept($marker.'_'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        $result = $this->explorer()->searchActiveConcepts($marker);

        // El viejo `limit(20)` habría devuelto 20 sin decir nada. Ahora: 25 coincidencias, 25
        // devueltas, y `truncated` en false porque el tope (50) no se alcanzó.
        $this->assertSame(25, $result['total']);
        $this->assertSame(25, $result['shown']);
        $this->assertFalse($result['truncated']);
        $this->assertCount(25, $this->explorer()->searchOptions($marker));
    }

    #[Test]
    public function when_matches_exceed_the_cap_the_overflow_is_reported_instead_of_hidden(): void
    {
        $marker = 'zzz_task0006a_cap'.substr(uniqid('', true), -8);
        for ($i = 1; $i <= 12; $i++) {
            $this->concept($marker.'_'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        $result = $this->explorer()->searchActiveConcepts($marker, cap: 5);

        $this->assertSame(12, $result['total'], 'El total real se informa siempre.');
        $this->assertSame(5, $result['shown']);
        $this->assertTrue($result['truncated'], 'El overflow debe quedar declarado, no silencioso.');
    }

    #[Test]
    public function search_matches_both_spanish_and_english_canonical_names(): void
    {
        $token = substr(uniqid('', true), -8);
        $es = $this->concept('zzz_task0006a_tuberia_'.$token, null);
        $en = $this->concept(null, 'zzz_task0006a_pipeworkonly_'.$token);

        $this->assertArrayHasKey($es->id, $this->explorer()->searchOptions('tuberia_'.$token));
        $this->assertArrayHasKey($en->id, $this->explorer()->searchOptions('pipeworkonly_'.$token));
    }

    #[Test]
    public function search_also_finds_concepts_through_member_terms_and_aliases(): void
    {
        $token = substr(uniqid('', true), -8);

        $byTerm = $this->concept('zzz_task0006a_porTermino_'.$token);
        $term = $this->term('zzz_task0006a_terminomiembro_'.$token);
        $this->linkTermToConcept($term, $byTerm);

        $byAlias = $this->concept('zzz_task0006a_porAlias_'.$token);
        $aliasTerm = $this->term('zzz_task0006a_portadoralias_'.$token);
        $this->linkTermToConcept($aliasTerm, $byAlias);
        $this->addAlias($aliasTerm, 'zzz_task0006a_aliasbuscable_'.$token);

        $this->assertArrayHasKey(
            $byTerm->id,
            $this->explorer()->searchOptions('terminomiembro_'.$token),
            'Un concepto debe ser descubrible por un término miembro, no solo por su nombre canónico.',
        );
        $this->assertArrayHasKey(
            $byAlias->id,
            $this->explorer()->searchOptions('aliasbuscable_'.$token),
            'Un concepto debe ser descubrible por un alias de un término miembro.',
        );
    }

    #[Test]
    public function merged_concepts_are_never_offered_as_a_mapping_target(): void
    {
        $token = substr(uniqid('', true), -8);
        $active = $this->concept('zzz_task0006a_activo_'.$token);
        $merged = $this->concept('zzz_task0006a_activo_fusionado_'.$token, null, TaxonomyCanonicalConcept::STATUS_MERGED);

        $options = $this->explorer()->searchOptions('zzz_task0006a_activo_'.$token);

        $this->assertArrayHasKey($active->id, $options);
        $this->assertArrayNotHasKey($merged->id, $options, 'Un concepto `merged` no es destino de mapeo válido.');
    }

    #[Test]
    public function the_option_label_carries_enough_identity_to_tell_homonyms_apart(): void
    {
        $token = substr(uniqid('', true), -8);
        $a = $this->concept('zzz_task0006a_homonimo_'.$token);
        $b = $this->concept('zzz_task0006a_homonimo_'.$token);

        $labelA = $this->explorer()->optionLabel($a);
        $labelB = $this->explorer()->optionLabel($b);

        $this->assertNotSame($labelA, $labelB, 'Dos conceptos con el mismo nombre deben distinguirse en la etiqueta.');
        $this->assertStringContainsString('#'.$a->id, $labelA);
        $this->assertStringContainsString('#'.$b->id, $labelB);
    }

    // ---------------------------------------------------------------------------------------------
    // Sección B — panel de diagnóstico
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function diagnostics_expose_the_governed_cpv_associations_of_the_selected_concept(): void
    {
        $concept = $this->concept('zzz_task0006a_diag_'.uniqid('', true));
        $term = $this->term('zzz_task0006a_diagterm_'.uniqid('', true));
        $this->linkTermToConcept($term, $concept);
        $this->addAlias($term, 'zzz_task0006a_diagalias');

        [$categoryId] = $this->existingCategoryIds(1);
        $this->approveCpvRelation($term, $categoryId);

        $d = $this->explorer()->diagnostics($concept);

        $this->assertSame(1, $d['member_term_count']);
        $this->assertSame(1, $d['alias_count']);
        $this->assertSame(1, $d['category_total']);
        $this->assertSame($categoryId, (int) $d['categories']->first()->id);
        $this->assertSame(0, $d['category_overflow']);
        $this->assertArrayHasKey('total_unique_company_count', $d['impact']);

        // El render no debe explotar y debe mostrar el código CPV gobernado.
        $html = TaxonomyCandidateConceptLinkResource::formatConceptDiagnostics($d)->toHtml();
        $this->assertStringContainsString((string) $d['categories']->first()->code, $html);
    }

    #[Test]
    public function a_cpv_relation_that_is_not_approved_is_not_presented_as_reachable(): void
    {
        $concept = $this->concept('zzz_task0006a_notapproved_'.uniqid('', true));
        $term = $this->term('zzz_task0006a_notapprovedterm_'.uniqid('', true));
        $this->linkTermToConcept($term, $concept);

        [$categoryId] = $this->existingCategoryIds(1);
        $code = DB::connection('pgsql')->table('taxonomy_categories')->where('id', $categoryId)->value('code');
        DB::connection('pgsql')->table('taxonomy_term_cpv_relations')->insert([
            'term_id' => $term->id, 'cpv_code' => $code, 'category_id' => $categoryId,
            'level' => 'category', 'relation_type' => 'lexical', 'weight' => 0.5, 'confidence' => 0.5,
            'source' => 'task0006a_test_fixture', 'status' => 'needs_review',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $d = $this->explorer()->diagnostics($concept);

        $this->assertSame(0, $d['category_total'], 'Solo las relaciones CPV aprobadas son taxonomía gobernada.');
    }

    #[Test]
    public function a_large_cpv_association_set_is_not_silently_truncated(): void
    {
        $concept = $this->concept('zzz_task0006a_many_cats_'.uniqid('', true));
        $term = $this->term('zzz_task0006a_manycatsterm_'.uniqid('', true));
        $this->linkTermToConcept($term, $concept);

        $categoryIds = $this->existingCategoryIds(18);
        $this->assertCount(18, $categoryIds, 'Precondición: hacen falta 18 categorías reales de referencia.');
        foreach ($categoryIds as $id) {
            $this->approveCpvRelation($term, $id);
        }

        $d = $this->explorer()->diagnostics($concept, categoryPreviewLimit: 12);

        $this->assertSame(18, $d['category_total'], 'El total real se informa completo.');
        $this->assertCount(12, $d['categories'], 'Se listan solo las primeras 12 en detalle.');
        $this->assertSame(6, $d['category_overflow'], 'Y las 6 restantes quedan declaradas explícitamente.');

        $html = TaxonomyCandidateConceptLinkResource::formatConceptDiagnostics($d)->toHtml();
        $this->assertStringContainsString('18', $html, 'El render debe mostrar el total real.');
        $this->assertStringContainsString('6', $html, 'Y cuántas quedaron sin listar.');
    }

    #[Test]
    public function diagnostics_warn_about_data_gaps_instead_of_staying_silent(): void
    {
        $orphan = $this->concept('zzz_task0006a_orphan_'.uniqid('', true));

        $d = $this->explorer()->diagnostics($orphan);

        $this->assertSame(0, $d['member_term_count']);
        $this->assertSame(0, $d['category_total']);
        $this->assertNotEmpty($d['warnings']);

        $joined = implode(' || ', $d['warnings']);
        $this->assertStringContainsString('identidad aprobada', $joined);
        $this->assertStringContainsString('CPV', $joined);
    }

    // ---------------------------------------------------------------------------------------------
    // Secciones A/C — integración real con el formulario de revisión, sin cambiar la semántica C2
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_concept_outside_the_suggestion_set_can_be_found_and_frozen_as_map_to_existing(): void
    {
        $suggested = $this->concept('zzz_task0006a_sug_'.uniqid('', true));
        $discovered = $this->concept('zzz_task0006a_descubierto_'.uniqid('', true));
        $candidate = $this->pendingCandidate($suggested);
        $reviewer = $this->reviewer();

        // El revisor lo encuentra por búsqueda (no estaba entre las recomendaciones) y lo congela.
        $this->assertArrayHasKey($discovered->id, $this->explorer()->searchOptions('zzz_task0006a_descubierto'));

        Livewire::actingAs($reviewer)
            ->test(ListTaxonomyCandidateConceptLinks::class)
            ->callTableAction('freezeReview', $candidate, data: [
                'decision' => TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING,
                'target_concept_id' => $discovered->id,
            ])
            ->assertHasNoTableActionErrors();

        $proposal = TaxonomyReviewedProposal::where('candidate_link_id', $candidate->id)->sole();

        $this->assertSame(TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING, $proposal->decision);
        $this->assertSame(TaxonomyReviewedProposal::STATUS_PENDING_APPLY, $proposal->status);
        $this->assertSame($discovered->id, (int) $proposal->decision_payload['target_concept_id'], 'Congela EXACTAMENTE el concepto que el revisor eligió.');
        $this->assertNotSame($suggested->id, (int) $proposal->decision_payload['target_concept_id']);

        // Sigue congelando UN solo concepto, y no publicó nada.
        $this->assertSame(TaxonomyCandidateConceptLink::STATUS_PENDING, $candidate->fresh()->status);
        $this->assertNull($proposal->applied_at);
        $this->assertSame(0, DB::connection('pgsql')->table('taxonomy_term_concepts')
            ->where('term_id', $candidate->suggested_term_id)->count(), 'freeze() no crea identidad término→concepto.');
    }

    #[Test]
    public function context_required_still_freezes_zero_mappings_even_after_inspecting_a_concept(): void
    {
        $inspected = $this->concept('zzz_task0006a_inspeccionado_'.uniqid('', true));
        $candidate = $this->pendingCandidate($this->concept('zzz_task0006a_ctxsug_'.uniqid('', true)));

        $conceptsBefore = DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count();
        $linksBefore = DB::connection('pgsql')->table('taxonomy_term_concepts')->count();

        Livewire::actingAs($this->reviewer())
            ->test(ListTaxonomyCandidateConceptLinks::class)
            ->callTableAction('freezeReview', $candidate, data: [
                'decision' => TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED,
                'context_reason' => 'Término demasiado amplio para un mapeo incondicional.',
                // Inspeccionar un concepto es SOLO evidencia - no debe entrar al payload.
                'inspect_concept_id' => $inspected->id,
            ])
            ->assertHasNoTableActionErrors();

        $proposal = TaxonomyReviewedProposal::where('candidate_link_id', $candidate->id)->sole();

        $this->assertSame(TaxonomyReviewedProposal::DECISION_CONTEXT_REQUIRED, $proposal->decision);
        $this->assertArrayNotHasKey('inspect_concept_id', $proposal->decision_payload, 'El concepto inspeccionado NO debe congelarse como mapeo.');
        $this->assertArrayNotHasKey('target_concept_id', $proposal->decision_payload);
        $this->assertSame('Término demasiado amplio para un mapeo incondicional.', $proposal->decision_payload['context_reason']);

        $this->assertSame($conceptsBefore, DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count(), 'Cero conceptos creados.');
        $this->assertSame($linksBefore, DB::connection('pgsql')->table('taxonomy_term_concepts')->count(), 'Cero mapeos creados.');
    }

    #[Test]
    public function an_already_frozen_candidate_is_still_protected_from_a_second_freeze(): void
    {
        $concept = $this->concept('zzz_task0006a_idem_'.uniqid('', true));
        $candidate = $this->pendingCandidate($concept);
        $reviewer = $this->reviewer();

        foreach ([1, 2] as $attempt) {
            Livewire::actingAs($reviewer)
                ->test(ListTaxonomyCandidateConceptLinks::class)
                ->callTableAction('freezeReview', $candidate->fresh(), data: [
                    'decision' => TaxonomyReviewedProposal::DECISION_MAP_TO_EXISTING,
                    'target_concept_id' => $concept->id,
                ])
                ->assertHasNoTableActionErrors();
        }

        $this->assertSame(1, TaxonomyReviewedProposal::where('candidate_link_id', $candidate->id)
            ->where('status', TaxonomyReviewedProposal::STATUS_PENDING_APPLY)->count());
    }

    #[Test]
    public function the_review_form_exposes_no_apply_or_publish_action(): void
    {
        $candidate = $this->pendingCandidate($this->concept('zzz_task0006a_noapply_'.uniqid('', true)));
        $component = Livewire::actingAs($this->reviewer())->test(ListTaxonomyCandidateConceptLinks::class);

        foreach (['apply', 'publish', 'applyReview', 'execute', 'approve'] as $forbidden) {
            $threw = false;
            try {
                $component->callTableAction($forbidden, $candidate);
            } catch (\Throwable $e) {
                $threw = true;
            }
            $this->assertTrue($threw, "La acción '{$forbidden}' no debería existir en esta UI.");
        }
    }
}
