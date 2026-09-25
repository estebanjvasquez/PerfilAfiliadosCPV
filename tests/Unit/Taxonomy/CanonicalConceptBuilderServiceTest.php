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

    // ============================= PHASE B - B2: IMPACTO PREDICHO =============================

    #[Test]
    public function predict_affected_companies_returns_zero_impact_for_empty_category_list(): void
    {
        $impact = $this->builder()->predictAffectedCompanies([]);

        $this->assertSame(0, $impact['total_unique_company_count']);
        $this->assertContains('CONCEPT_RESOLUTION_FAILURE', $impact['data_gap_flags']);
    }

    #[Test]
    public function predict_affected_companies_counts_direct_companies_deduplicated_by_empresa_id(): void
    {
        $category = $this->syntheticCategory();
        $empresaId = DB::connection('pgsql')->table('empresas')->value('id');
        $this->assertNotNull($empresaId, 'Se necesita al menos una empresa real en la BD de pruebas para este test.');

        // Dos filas para la MISMA empresa (dos orígenes distintos) - debe contar como 1 empresa
        // única, preservando ambas rutas de evidencia (sección 12/13 del pedido).
        DB::connection('pgsql')->table('empresa_taxonomy_category')->insert([
            ['empresa_id' => $empresaId, 'category_id' => $category->id, 'origen' => 'self_declared', 'es_principal' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $impact = $this->builder()->predictAffectedCompanies([$category->id]);

        $this->assertSame(1, $impact['total_unique_company_count']);
        $this->assertSame(1, $impact['direct_company_count']);
        $this->assertSame($empresaId, $impact['companies'][0]['company_id']);
        $this->assertContains('declared_taxonomy_category', [$impact['companies'][0]['reason']]);
    }

    #[Test]
    public function predict_affected_companies_breaks_down_direct_evidence_by_origen(): void
    {
        // Phase B.1 (seguimiento 2026-09-25): `direct_confirmed_company_count` (self_declared) vs
        // `direct_suggested_company_count` (suggested, viene de taxonomy:homologate-empresas) -
        // un revisor necesita distinguir evidencia confirmada por la empresa de una sugerencia
        // automática nunca confirmada, sin tener que leer companies[].evidence_paths a mano.
        $category = $this->syntheticCategory();
        $empresaIds = DB::connection('pgsql')->table('empresas')->limit(2)->pluck('id')->all();
        $this->assertCount(2, $empresaIds, 'Se necesitan al menos 2 empresas reales en la BD de pruebas para este test.');
        [$confirmedEmpresaId, $suggestedEmpresaId] = $empresaIds;

        DB::connection('pgsql')->table('empresa_taxonomy_category')->insert([
            ['empresa_id' => $confirmedEmpresaId, 'category_id' => $category->id, 'origen' => 'self_declared', 'es_principal' => true, 'created_at' => now(), 'updated_at' => now()],
            ['empresa_id' => $suggestedEmpresaId, 'category_id' => $category->id, 'origen' => 'suggested', 'es_principal' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $impact = $this->builder()->predictAffectedCompanies([$category->id]);

        $this->assertSame(2, $impact['direct_company_count']);
        $this->assertSame(1, $impact['direct_confirmed_company_count']);
        $this->assertSame(1, $impact['direct_suggested_company_count']);
    }

    #[Test]
    public function predict_affected_companies_flags_cpv_without_companies_as_data_gap(): void
    {
        $category = $this->syntheticCategory();

        $impact = $this->builder()->predictAffectedCompanies([$category->id]);

        $this->assertSame(0, $impact['total_unique_company_count']);
        $this->assertContains('CPV_WITHOUT_COMPANIES', $impact['data_gap_flags']);
    }

    #[Test]
    public function dry_run_attaches_predicted_impact_to_every_scored_candidate(): void
    {
        $result = $this->builder()->dryRun(5);

        foreach ($result['sample_results'] as $scored) {
            $this->assertArrayHasKey('predicted_impact', $scored);
            $this->assertArrayHasKey('total_unique_company_count', $scored['predicted_impact']);
        }
    }

    // ============================= PHASE B - B1: RELACIONES CONCEPTO<->CONCEPTO =============================

    #[Test]
    public function propose_concept_relations_finds_related_to_via_shared_cpv(): void
    {
        $category = $this->syntheticCategory();

        [$conceptA, $termA] = $this->conceptWithApprovedCpv('zzz_phaseb_concept_a_'.uniqid(), $category);
        [$conceptB, $termB] = $this->conceptWithApprovedCpv('zzz_phaseb_concept_b_'.uniqid(), $category);

        $result = $this->builder()->proposeConceptRelations([$conceptA->id, $conceptB->id]);

        $this->assertSame(1, $result['pairs_evaluated']);
        $this->assertNotEmpty($result['proposals'], 'CPV compartido + termino/alias no deberia dar null - debe proponer al menos RELATED_TO.');
        $proposal = $result['proposals'][0];
        $this->assertSame('RELATED_TO', $proposal['relation_type']);
        $this->assertSame('symmetric', $proposal['direction']);
        $this->assertGreaterThan(0, $proposal['evidence']['shared_cpv']);
    }

    #[Test]
    public function propose_concept_relations_never_returns_directional_types(): void
    {
        $result = $this->builder()->proposeConceptRelations();

        foreach ($result['proposals'] as $proposal) {
            $this->assertSame('RELATED_TO', $proposal['relation_type'], 'El Builder nunca debe auto-proponer tipos direccionales/jerarquicos (PART_OF/HAS_PART/SUPERSEDES/SUPERSEDED_BY) - solo un humano puede proponerlos, ver audit/phase3_phase_b.md.');
        }
    }

    #[Test]
    public function propose_concept_relations_skips_pairs_below_review_threshold(): void
    {
        $conceptA = TaxonomyCanonicalConcept::create(['canonical_name_es' => 'zzz_phaseb_unrelated_a_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);
        $conceptB = TaxonomyCanonicalConcept::create(['canonical_name_es' => 'zzz_phaseb_unrelated_b_completely_different_xyz_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);

        $result = $this->builder()->proposeConceptRelations([$conceptA->id, $conceptB->id]);

        $this->assertEmpty($result['proposals'], 'Dos conceptos sin ninguna senal en comun no deben generar ninguna propuesta.');
    }

    #[Test]
    public function validate_concept_relation_proposal_rejects_self_relation(): void
    {
        $concept = TaxonomyCanonicalConcept::create(['canonical_name_es' => 'zzz_phaseb_self_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);

        $result = $this->builder()->validateConceptRelationProposal($concept->id, $concept->id, 'RELATED_TO');

        $this->assertFalse($result['valid']);
        $this->assertSame('SELF_RELATION_NOT_ALLOWED', $result['reason']);
    }

    #[Test]
    public function validate_concept_relation_proposal_rejects_unknown_relation_type(): void
    {
        $a = TaxonomyCanonicalConcept::create(['canonical_name_es' => 'zzz_phaseb_a_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);
        $b = TaxonomyCanonicalConcept::create(['canonical_name_es' => 'zzz_phaseb_b_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);

        $result = $this->builder()->validateConceptRelationProposal($a->id, $b->id, 'INVENTED_TYPE_NOT_IN_CATALOG');

        $this->assertFalse($result['valid']);
        $this->assertSame('UNKNOWN_OR_INACTIVE_RELATION_TYPE', $result['reason']);
    }

    #[Test]
    public function validate_concept_relation_proposal_rejects_exact_duplicate(): void
    {
        $a = TaxonomyCanonicalConcept::create(['canonical_name_es' => 'zzz_phaseb_dup_a_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);
        $b = TaxonomyCanonicalConcept::create(['canonical_name_es' => 'zzz_phaseb_dup_b_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);
        DB::connection('pgsql')->table('taxonomy_concept_relations')->insert([
            'source_concept_id' => $a->id, 'target_concept_id' => $b->id, 'relation_type' => 'RELATED_TO',
            'weight' => 0.5, 'confidence' => 0.5, 'status' => 'candidate', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->builder()->validateConceptRelationProposal($a->id, $b->id, 'RELATED_TO');

        $this->assertFalse($result['valid']);
        $this->assertSame('DUPLICATE', $result['reason']);
    }

    #[Test]
    public function validate_concept_relation_proposal_rejects_symmetric_duplicate_in_reverse_order(): void
    {
        $a = TaxonomyCanonicalConcept::create(['canonical_name_es' => 'zzz_phaseb_sym_a_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);
        $b = TaxonomyCanonicalConcept::create(['canonical_name_es' => 'zzz_phaseb_sym_b_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);
        DB::connection('pgsql')->table('taxonomy_concept_relations')->insert([
            'source_concept_id' => $b->id, 'target_concept_id' => $a->id, 'relation_type' => 'RELATED_TO',
            'weight' => 0.5, 'confidence' => 0.5, 'status' => 'candidate', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // RELATED_TO no es direccional - proponer (a,b) cuando ya existe (b,a) es la MISMA relacion.
        $result = $this->builder()->validateConceptRelationProposal($a->id, $b->id, 'RELATED_TO');

        $this->assertFalse($result['valid']);
        $this->assertSame('DUPLICATE_VIA_SYMMETRY', $result['reason']);
    }

    #[Test]
    public function validate_concept_relation_proposal_rejects_duplicate_via_inverse_directional_type(): void
    {
        $a = TaxonomyCanonicalConcept::create(['canonical_name_es' => 'zzz_phaseb_inv_a_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);
        $b = TaxonomyCanonicalConcept::create(['canonical_name_es' => 'zzz_phaseb_inv_b_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);
        // B ya tiene registrado HAS_PART hacia A (o sea, A ya es PART_OF B, expresado al reves).
        DB::connection('pgsql')->table('taxonomy_concept_relations')->insert([
            'source_concept_id' => $b->id, 'target_concept_id' => $a->id, 'relation_type' => 'HAS_PART',
            'weight' => 0.7, 'confidence' => 0.5, 'status' => 'candidate', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->builder()->validateConceptRelationProposal($a->id, $b->id, 'PART_OF');

        $this->assertFalse($result['valid']);
        $this->assertSame('DUPLICATE_VIA_INVERSE', $result['reason']);
    }

    #[Test]
    public function validate_concept_relation_proposal_detects_a_cycle_in_hierarchical_relations(): void
    {
        $a = TaxonomyCanonicalConcept::create(['canonical_name_es' => 'zzz_phaseb_cycle_a_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);
        $b = TaxonomyCanonicalConcept::create(['canonical_name_es' => 'zzz_phaseb_cycle_b_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);
        $c = TaxonomyCanonicalConcept::create(['canonical_name_es' => 'zzz_phaseb_cycle_c_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);

        // A PART_OF B, B PART_OF C ya existen. Proponer C PART_OF A cerraria el ciclo A->B->C->A.
        DB::connection('pgsql')->table('taxonomy_concept_relations')->insert([
            ['source_concept_id' => $a->id, 'target_concept_id' => $b->id, 'relation_type' => 'PART_OF', 'weight' => 0.7, 'confidence' => 0.5, 'status' => 'candidate', 'created_at' => now(), 'updated_at' => now()],
            ['source_concept_id' => $b->id, 'target_concept_id' => $c->id, 'relation_type' => 'PART_OF', 'weight' => 0.7, 'confidence' => 0.5, 'status' => 'candidate', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $result = $this->builder()->validateConceptRelationProposal($c->id, $a->id, 'PART_OF');

        $this->assertFalse($result['valid']);
        $this->assertSame('CYCLE_DETECTED', $result['reason']);
    }

    #[Test]
    public function validate_concept_relation_proposal_allows_a_genuinely_new_valid_relation(): void
    {
        $a = TaxonomyCanonicalConcept::create(['canonical_name_es' => 'zzz_phaseb_valid_a_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);
        $b = TaxonomyCanonicalConcept::create(['canonical_name_es' => 'zzz_phaseb_valid_b_'.uniqid(), 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);

        $result = $this->builder()->validateConceptRelationProposal($a->id, $b->id, 'PART_OF');

        $this->assertTrue($result['valid']);
        $this->assertNull($result['reason']);
    }

    // ============================= HELPERS =============================

    /** @return array{0: TaxonomyCanonicalConcept, 1: TaxonomyTerm} */
    private function conceptWithApprovedCpv(string $name, object $category): array
    {
        $term = TaxonomyTerm::create($this->syntheticTermAttributes($name, 'es'));
        $concept = TaxonomyCanonicalConcept::create(['canonical_name_es' => $name, 'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE]);
        DB::connection('pgsql')->table('taxonomy_term_concepts')->insert([
            'term_id' => $term->id, 'concept_id' => $concept->id, 'created_at' => now(),
        ]);
        DB::connection('pgsql')->table('taxonomy_term_cpv_relations')->insert([
            'term_id' => $term->id, 'category_id' => $category->id, 'cpv_code' => $category->code, 'level' => 0,
            'relation_type' => 'exact', 'weight' => 1.0, 'confidence' => 1.0, 'source' => 'phaseb-test',
            'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$concept->fresh('terms'), $term];
    }

    private function syntheticCategory(): object
    {
        $code = 'ZZZ-TEST-'.uniqid();
        $id = DB::connection('pgsql')->table('taxonomy_categories')->insertGetId([
            'code' => $code, 'level' => 0, 'path' => $code, 'is_active' => true,
            'source_version' => 'phaseb-test', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (object) ['id' => $id, 'code' => $code];
    }

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
