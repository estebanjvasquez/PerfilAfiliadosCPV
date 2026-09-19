<?php

namespace Tests\Unit\Taxonomy;

use App\Models\TaxonomyCanonicalConcept;
use App\Models\TaxonomyTerm;
use App\Services\Taxonomy\CanonicalConceptBuilderService;
use App\Services\Taxonomy\IntentContaminationDetector;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 3: pruebas del Builder contra la BD compartida real (Supabase de pruebas) - envueltas en
 * `DatabaseTransactions` sobre la conexión `pgsql` para que CUALQUIER fila creada por un test se
 * revierta automáticamente al terminar, incluso si el test falla. Ningún test de esta clase deja
 * datos permanentes - ver sección 21 del pedido ("dry-run write barrier") y el requisito de
 * ZERO-POPULATION de la entrega completa.
 */
class CanonicalConceptBuilderServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private function builder(): CanonicalConceptBuilderService
    {
        return new CanonicalConceptBuilderService(new IntentContaminationDetector(['mantenimiento', 'maintenance']));
    }

    // ============================= REGLA DE SEGURIDAD (sección 12) =============================

    #[Test]
    public function a_single_corroborating_signal_never_reaches_auto_accept(): void
    {
        $settings = $this->syntheticTierSettings();

        $tier = $this->builder()->classifyTier(0.95, 1, $settings);

        $this->assertSame('REVIEW', $tier, 'Un score alto con solo 1 señal corroborante debe degradarse a REVIEW, nunca AUTO_ACCEPT.');
    }

    #[Test]
    public function auto_accept_requires_the_configured_minimum_corroboration(): void
    {
        $settings = $this->syntheticTierSettings();

        $this->assertSame('AUTO_ACCEPT', $this->builder()->classifyTier(0.95, 2, $settings));
    }

    #[Test]
    public function auto_accept_conservative_also_respects_the_corroboration_floor(): void
    {
        $settings = $this->syntheticTierSettings();

        $this->assertSame('REVIEW', $this->builder()->classifyTier(0.85, 1, $settings));
        $this->assertSame('AUTO_ACCEPT_CONSERVATIVE', $this->builder()->classifyTier(0.85, 2, $settings));
    }

    #[Test]
    public function scores_below_review_threshold_are_rejected(): void
    {
        $settings = $this->syntheticTierSettings();

        $this->assertSame('REJECT', $this->builder()->classifyTier(0.10, 0, $settings));
    }

    // ============================= IDENTIDAD CANÓNICA EXACTA =============================

    #[Test]
    public function exact_canonical_identity_reaches_auto_accept_end_to_end(): void
    {
        $needle = 'zzz_phase3_test_widget_'.uniqid();

        $memberTerm = TaxonomyTerm::create($this->syntheticTermAttributes($needle, 'en'));
        $concept = TaxonomyCanonicalConcept::create(['canonical_name_en' => $needle, 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);
        DB::connection('pgsql')->table('taxonomy_term_concepts')->insert([
            'term_id' => $memberTerm->id, 'concept_id' => $concept->id, 'created_at' => now(),
        ]);

        $candidateTerm = TaxonomyTerm::create($this->syntheticTermAttributes($needle, 'es'));

        $scored = $this->builder()->scoreCandidate($candidateTerm, $concept->fresh('terms'));

        $this->assertSame(1.0, $scored['signals']['exact_canonical_signal']);
        $this->assertSame('AUTO_ACCEPT', $scored['tier']);
    }

    #[Test]
    public function exact_canonical_identity_is_downgraded_when_intent_contaminated(): void
    {
        $subject = 'zzz_phase3_test_gizmo_'.uniqid();
        $intentTerm = 'mantenimiento de '.$subject;

        $memberTerm = TaxonomyTerm::create($this->syntheticTermAttributes($subject, 'es'));
        $concept = TaxonomyCanonicalConcept::create(['canonical_name_es' => $subject, 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);
        DB::connection('pgsql')->table('taxonomy_term_concepts')->insert([
            'term_id' => $memberTerm->id, 'concept_id' => $concept->id, 'created_at' => now(),
        ]);

        // canonical_term IDÉNTICO al del miembro (exact_canonical_signal=1.0) pero el propio TERM
        // (no el canonical_term) es [INTENT + SUBJECT] - el detector debe seguir viendo la
        // contaminación por el texto real del término, no solo por canonical_term.
        $attrs = $this->syntheticTermAttributes($subject, 'es');
        $attrs['term'] = $intentTerm;
        $candidateTerm = TaxonomyTerm::create($attrs);

        $scored = $this->builder()->scoreCandidate($candidateTerm, $concept->fresh('terms'));

        $this->assertSame(1.0, $scored['signals']['intent_contamination_penalty']);
        $this->assertNotSame('AUTO_ACCEPT', $scored['tier'], 'Una coincidencia exacta contaminada por intención no debe auto-aceptarse ciegamente.');
    }

    // ============================= AUDIT_EXISTING (sección 14) =============================

    #[Test]
    public function audit_existing_loads_every_concept_dynamically_without_assuming_a_fixed_count(): void
    {
        $audit = $this->builder()->auditExisting();

        $realCount = DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count();

        $this->assertSame($realCount, $audit['concept_count']);
        $this->assertCount($realCount, $audit['concepts']);
        foreach ($audit['concepts'] as $concept) {
            $this->assertNotEmpty($concept['flags']);
        }
    }

    // ============================= DRY-RUN WRITE BARRIER (sección 15/16/21) =============================

    #[Test]
    public function dry_run_never_writes_to_any_canonical_or_relation_table(): void
    {
        $before = $this->canonicalTableCounts();

        $this->builder()->dryRun(10);

        $this->assertSame($before, $this->canonicalTableCounts(), 'dryRun() no debe alterar ninguna fila de las tablas canónicas.');
    }

    // ============================= HELPERS =============================

    private function syntheticTierSettings(): array
    {
        return [
            'concept_builder.auto_accept_threshold' => 0.90,
            'concept_builder.auto_accept_conservative_threshold' => 0.80,
            'concept_builder.review_threshold' => 0.55,
            'concept_builder.min_corroborating_signals_for_auto_accept' => 2,
        ];
    }

    private function syntheticTermAttributes(string $canonical, string $language): array
    {
        return [
            'external_id' => 'phase3-test-'.uniqid('', true),
            'term' => $canonical,
            'language' => $language,
            'canonical_term' => $canonical,
            'term_type' => TaxonomyTerm::TERM_TYPE_TECHNICAL,
            'region' => [],
            'negative_context' => [],
            'positive_context' => [],
            'mapping_review_status' => TaxonomyTerm::MAPPING_UNMAPPED,
        ];
    }

    private function canonicalTableCounts(): array
    {
        return [
            'taxonomy_canonical_concepts' => DB::connection('pgsql')->table('taxonomy_canonical_concepts')->count(),
            'taxonomy_term_concepts' => DB::connection('pgsql')->table('taxonomy_term_concepts')->count(),
            'taxonomy_concept_relations' => DB::connection('pgsql')->table('taxonomy_concept_relations')->count(),
            'taxonomy_candidate_concept_links' => DB::connection('pgsql')->table('taxonomy_candidate_concept_links')->count(),
            'taxonomy_term_cpv_relations' => DB::connection('pgsql')->table('taxonomy_term_cpv_relations')->count(),
            'empresa_taxonomy_category' => DB::connection('pgsql')->table('empresa_taxonomy_category')->count(),
        ];
    }
}
