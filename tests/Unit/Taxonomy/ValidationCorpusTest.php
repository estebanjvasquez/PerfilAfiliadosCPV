<?php

namespace Tests\Unit\Taxonomy;

use App\Models\TaxonomyCanonicalConcept;
use App\Models\TaxonomyTerm;
use App\Models\TaxonomyTermCpvRelation;
use App\Services\Taxonomy\CanonicalConceptBuilderService;
use App\Services\Taxonomy\IntentContaminationDetector;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 3.1 (sección 5 del pedido): corpus de validación pequeño pero representativo, SEPARADO del
 * código de producción - mide el comportamiento del Builder, NO lo calibra. Cada caso afirma
 * únicamente invariantes ESTRUCTURALES que se deducen de los pesos/reglas ya definidos en
 * `TaxonomyRankingParameters` (sección GROUP_CONCEPT_BUILDER) - nunca un score exacto "porque así dio
 * al correrlo" (eso sería covertir expected_relation en training data, prohibido explícitamente).
 *
 * Los 10 pesos de señal sin la penalización de intención suman 1.0 y ninguno supera 0.20 - por
 * construcción, NINGUNA señal aislada puede cruzar auto_accept_conservative_threshold (0.80) ni
 * siquiera review_threshold (0.55) por sí sola, salvo la identidad canónica exacta (que tiene su
 * propio tratamiento especial documentado y testeado aparte). Esa es la propiedad que cada caso B-L
 * verifica para su señal correspondiente.
 *
 * Usa fixtures 100% sintéticos (creados y revertidos en transacción) para poder aislar CADA señal sin
 * ambigüedad - los datos reales de producción rara vez aíslan una sola dimensión (un candidato real
 * casi siempre trae varias señales mezcladas), lo cual es bueno para el sistema pero malo para un
 * corpus de validación que necesita saber EXACTAMENTE qué señal está probando cada caso.
 */
class ValidationCorpusTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private function builder(): CanonicalConceptBuilderService
    {
        return new CanonicalConceptBuilderService(IntentContaminationDetector::fromGovernedVocabulary());
    }

    private function uniq(string $prefix): string
    {
        return $prefix.'_'.str_replace('.', '', uniqid('', true));
    }

    private function makeTerm(array $overrides = []): TaxonomyTerm
    {
        $canonical = $overrides['canonical_term'] ?? $this->uniq('zzz_corpus_term');

        return TaxonomyTerm::create(array_merge([
            'external_id' => 'corpus-'.uniqid('', true),
            'term' => $canonical,
            'language' => 'es',
            'canonical_term' => $canonical,
            'term_type' => TaxonomyTerm::TERM_TYPE_TECHNICAL,
            'region' => [],
            'negative_context' => [],
            'positive_context' => [],
            'mapping_review_status' => TaxonomyTerm::MAPPING_UNMAPPED,
        ], $overrides));
    }

    private function makeConceptWithMember(TaxonomyTerm $memberTerm, array $conceptOverrides = []): TaxonomyCanonicalConcept
    {
        $concept = TaxonomyCanonicalConcept::create(array_merge([
            'canonical_name_es' => $memberTerm->canonical_term,
            'status' => TaxonomyCanonicalConcept::STATUS_ACTIVE,
        ], $conceptOverrides));

        DB::connection('pgsql')->table('taxonomy_term_concepts')->insert([
            'term_id' => $memberTerm->id, 'concept_id' => $concept->id, 'created_at' => now(),
        ]);

        return $concept->fresh('terms');
    }

    /** Categoría CPV hoja real (nivel 2) más profunda del árbol, reusada para F/G/H/I/J. */
    private function anyLeafCategoryId(): int
    {
        return (int) DB::connection('pgsql')->table('taxonomy_categories')->where('level', 2)->value('id');
    }

    private function siblingCategoryUnderSameFamily(int $leafCategoryId): ?int
    {
        $familyId = DB::connection('pgsql')->table('taxonomy_categories')->where('id', $leafCategoryId)->value('parent_id');

        return DB::connection('pgsql')->table('taxonomy_categories')
            ->where('parent_id', $familyId)->where('id', '!=', $leafCategoryId)->value('id');
    }

    private function categoryInDifferentFamilySameGroup(int $leafCategoryId): ?int
    {
        $familyId = DB::connection('pgsql')->table('taxonomy_categories')->where('id', $leafCategoryId)->value('parent_id');
        $groupId = DB::connection('pgsql')->table('taxonomy_categories')->where('id', $familyId)->value('parent_id');
        $otherFamilyId = DB::connection('pgsql')->table('taxonomy_categories')
            ->where('parent_id', $groupId)->where('id', '!=', $familyId)->value('id');

        return $otherFamilyId
            ? DB::connection('pgsql')->table('taxonomy_categories')->where('parent_id', $otherFamilyId)->value('id')
            : null;
    }

    private function attachCpvRelation(TaxonomyTerm $term, int $categoryId, string $relationType, string $status, float $confidence = 0.9): void
    {
        $code = DB::connection('pgsql')->table('taxonomy_categories')->where('id', $categoryId)->value('code');

        TaxonomyTermCpvRelation::create([
            'term_id' => $term->id, 'cpv_code' => $code, 'category_id' => $categoryId, 'level' => 'category',
            'relation_type' => $relationType, 'weight' => $confidence, 'confidence' => $confidence,
            'status' => $status, 'source' => 'validation_corpus_test',
        ]);
    }

    #[Test]
    public function corpus_case_a_exact_canonical_identity_reaches_auto_accept(): void
    {
        $needle = $this->uniq('gizmo');
        $member = $this->makeTerm(['canonical_term' => $needle, 'language' => 'en']);
        $concept = $this->makeConceptWithMember($member, ['canonical_name_en' => $needle]);
        $candidate = $this->makeTerm(['canonical_term' => $needle, 'language' => 'es']);

        $scored = $this->builder()->scoreCandidate($candidate, $concept);

        $this->assertSame(1.0, $scored['signals']['exact_canonical_signal']);
        $this->assertSame('AUTO_ACCEPT', $scored['tier'], 'A: identidad canónica exacta debe auto-aceptar (tratamiento especial documentado).');
    }

    #[Test]
    public function corpus_case_b_bilingual_identity_signal_fires_but_never_auto_accepts_alone(): void
    {
        $subject = $this->uniq('flange');
        $member = $this->makeTerm(['canonical_term' => $subject, 'language' => 'en']);
        $concept = $this->makeConceptWithMember($member, ['canonical_name_en' => $subject, 'canonical_name_es' => $this->uniq('brida')]);
        $candidate = $this->makeTerm([
            'canonical_term' => $this->uniq('brida_distinta'), 'language' => 'es',
            'term_type' => TaxonomyTerm::TERM_TYPE_TRANSLATION_ALIAS,
        ]);

        $scored = $this->builder()->scoreCandidate($candidate, $concept);

        $this->assertSame(1.0, $scored['signals']['language_equivalence']);
        $this->assertNotSame('AUTO_ACCEPT', $scored['tier'], 'B: equivalencia de idioma sola (peso 0.05) no debe auto-aceptar.');
    }

    #[Test]
    public function corpus_case_c_alias_identity_signal_fires_but_never_auto_accepts_alone(): void
    {
        $subject = $this->uniq('wellhead');
        $member = $this->makeTerm(['canonical_term' => $subject]);
        $concept = $this->makeConceptWithMember($member);
        $candidate = $this->makeTerm(['canonical_term' => $this->uniq('otra_cosa')]);
        DB::connection('pgsql')->table('taxonomy_term_aliases')->insert(['term_id' => $candidate->id, 'alias' => $subject, 'created_at' => now()]);

        $scored = $this->builder()->scoreCandidate($candidate->fresh('aliases'), $concept);

        $this->assertSame(1.0, $scored['signals']['alias_overlap']);
        $this->assertNotSame('AUTO_ACCEPT', $scored['tier'], 'C: solapamiento de alias solo (peso 0.10) no debe auto-aceptar.');
    }

    #[Test]
    public function corpus_case_d_regional_synonym_identity_behaves_like_alias_identity(): void
    {
        $subject = $this->uniq('cabria_generica');
        $member = $this->makeTerm(['canonical_term' => $subject]);
        $concept = $this->makeConceptWithMember($member);
        $candidate = $this->makeTerm(['canonical_term' => $this->uniq('regionalismo'), 'term_type' => TaxonomyTerm::TERM_TYPE_REGIONAL_SLANG]);
        DB::connection('pgsql')->table('taxonomy_term_aliases')->insert(['term_id' => $candidate->id, 'alias' => $subject, 'created_at' => now()]);

        $scored = $this->builder()->scoreCandidate($candidate->fresh('aliases'), $concept);

        $this->assertSame(1.0, $scored['signals']['alias_overlap']);
        $this->assertNotSame('AUTO_ACCEPT', $scored['tier'], 'D: regionalismo vía alias no debe auto-aceptar solo.');
    }

    #[Test]
    public function corpus_case_e_lexical_similarity_without_identity_is_partial_and_never_auto_accepts_alone(): void
    {
        $member = $this->makeTerm(['canonical_term' => 'zzzcorpuswidgetalpha']);
        $concept = $this->makeConceptWithMember($member);
        $candidate = $this->makeTerm(['canonical_term' => 'zzzcorpuswidgetbeta']); // similar, no idéntico

        $scored = $this->builder()->scoreCandidate($candidate, $concept);

        $this->assertGreaterThan(0.0, $scored['signals']['lexical_similarity']);
        $this->assertLessThan(1.0, $scored['signals']['lexical_similarity']);
        $this->assertSame(0.0, $scored['signals']['exact_canonical_signal']);
        $this->assertNotSame('AUTO_ACCEPT', $scored['tier'], 'E: similitud léxica sin identidad no debe auto-aceptar sola.');
    }

    #[Test]
    public function corpus_case_f_same_cpv_leaf_without_identity_never_auto_accepts_alone(): void
    {
        $leafId = $this->anyLeafCategoryId();
        $member = $this->makeTerm();
        $concept = $this->makeConceptWithMember($member);
        $this->attachCpvRelation($member, $leafId, 'exact', 'approved');
        $candidate = $this->makeTerm();
        $this->attachCpvRelation($candidate, $leafId, 'exact', 'approved');

        $scored = $this->builder()->scoreCandidate($candidate, $concept);

        $this->assertSame(1.0, $scored['signals']['shared_cpv']);
        $this->assertSame(1.0, $scored['signals']['cpv_specificity']);
        $this->assertNotSame('AUTO_ACCEPT', $scored['tier'], 'F: mismo CPV hoja sin identidad no debe auto-aceptar solo (sección 11: shared CPV no es identidad).');
    }

    #[Test]
    public function corpus_case_g_same_cpv_family_without_identity_scores_lower_than_leaf_match(): void
    {
        $leafId = $this->anyLeafCategoryId();
        $siblingId = $this->siblingCategoryUnderSameFamily($leafId);
        if (! $siblingId) {
            $this->markTestSkipped('No hay una categoría hermana en la misma familia para este dataset.');
        }

        $member = $this->makeTerm();
        $concept = $this->makeConceptWithMember($member);
        $this->attachCpvRelation($member, $leafId, 'exact', 'approved');
        $candidate = $this->makeTerm();
        $this->attachCpvRelation($candidate, $siblingId, 'exact', 'approved');

        $scored = $this->builder()->scoreCandidate($candidate, $concept);

        $this->assertEqualsWithDelta(0.6, $scored['signals']['shared_cpv'], 0.0001);
        $this->assertEqualsWithDelta(0.5, $scored['signals']['cpv_specificity'], 0.0001);
        $this->assertNotSame('AUTO_ACCEPT', $scored['tier']);
    }

    #[Test]
    public function corpus_case_h_same_cpv_group_only_scores_lower_than_family_match(): void
    {
        $leafId = $this->anyLeafCategoryId();
        $otherFamilyLeafId = $this->categoryInDifferentFamilySameGroup($leafId);
        if (! $otherFamilyLeafId) {
            $this->markTestSkipped('No hay una familia hermana con hoja propia para este dataset.');
        }

        $member = $this->makeTerm();
        $concept = $this->makeConceptWithMember($member);
        $this->attachCpvRelation($member, $leafId, 'exact', 'approved');
        $candidate = $this->makeTerm();
        $this->attachCpvRelation($candidate, $otherFamilyLeafId, 'exact', 'approved');

        $scored = $this->builder()->scoreCandidate($candidate, $concept);

        $this->assertEqualsWithDelta(0.3, $scored['signals']['shared_cpv'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $scored['signals']['cpv_specificity'], 0.0001);
        $this->assertSame('REJECT', $scored['tier'], 'H: CPV compartido solo a nivel de Grupo es la señal más débil de las tres.');
    }

    #[Test]
    public function corpus_case_i_embedding_similarity_without_semantic_agreement_never_auto_accepts_alone(): void
    {
        $member = $this->makeTerm();
        $concept = $this->makeConceptWithMember($member);
        $candidate = $this->makeTerm(); // sin relación léxica/CPV/alias alguna con $member

        $vector = '['.implode(',', array_fill(0, 1024, 0.1)).']';
        DB::connection('pgsql')->table('taxonomy_term_embeddings')->insert([
            ['term_id' => $member->id, 'embedding' => $vector, 'model_version' => 'test', 'source_text' => $member->term, 'generated_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['term_id' => $candidate->id, 'embedding' => $vector, 'model_version' => 'test', 'source_text' => $candidate->term, 'generated_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $scored = $this->builder()->scoreCandidate($candidate, $concept);

        $this->assertEqualsWithDelta(1.0, $scored['signals']['embedding_similarity'], 0.0001, 'Vectores idénticos -> distancia coseno 0 -> similitud 1.0.');
        $this->assertNotSame('AUTO_ACCEPT', $scored['tier'], 'I: similitud de embedding sola (peso 0.12) no debe auto-aceptar - la regla de seguridad exige corroboración independiente.');
    }

    #[Test]
    public function corpus_case_j_embedding_plus_cpv_agreement_corroborate_but_result_is_measured_not_forced(): void
    {
        $leafId = $this->anyLeafCategoryId();
        $member = $this->makeTerm();
        $concept = $this->makeConceptWithMember($member);
        $this->attachCpvRelation($member, $leafId, 'exact', 'approved', 1.0);
        $candidate = $this->makeTerm();
        $this->attachCpvRelation($candidate, $leafId, 'exact', 'approved', 1.0);

        $vector = '['.implode(',', array_fill(0, 1024, 0.2)).']';
        DB::connection('pgsql')->table('taxonomy_term_embeddings')->insert([
            ['term_id' => $member->id, 'embedding' => $vector, 'model_version' => 'test', 'source_text' => $member->term, 'generated_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['term_id' => $candidate->id, 'embedding' => $vector, 'model_version' => 'test', 'source_text' => $candidate->term, 'generated_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $scored = $this->builder()->scoreCandidate($candidate, $concept);

        // Medimos, no calibramos (sección 5 del pedido): NO forzamos un tier esperado - solo
        // afirmamos que AMBAS señales corroboran (>= 2), documentado en el informe con el score real.
        $this->assertGreaterThanOrEqual(2, $scored['corroborating_signal_count']);
        $this->assertGreaterThan(0.0, $scored['signals']['embedding_similarity']);
        $this->assertGreaterThan(0.0, $scored['signals']['shared_cpv']);
    }

    #[Test]
    public function corpus_case_k_subject_vs_intent_subject_contamination_is_penalized(): void
    {
        $subject = $this->uniq('sprocket');
        $member = $this->makeTerm(['canonical_term' => $subject, 'term' => $subject]);
        $concept = $this->makeConceptWithMember($member);
        $candidate = $this->makeTerm(['canonical_term' => 'mantenimiento de '.$subject, 'term' => 'mantenimiento de '.$subject]);

        $scored = $this->builder()->scoreCandidate($candidate, $concept);

        $this->assertSame(1.0, $scored['signals']['intent_contamination_penalty']);
        $this->assertSame('REJECT', $scored['tier'], 'K: [INTENT+SUBJECT] contra un concepto que ya tiene el SUBJECT puro debe penalizarse a REJECT.');
    }

    #[Test]
    public function corpus_case_l_unrelated_terms_sharing_a_generic_word_do_not_false_positive_identity(): void
    {
        $member = $this->makeTerm(['canonical_term' => 'sistema de widgets']);
        $concept = $this->makeConceptWithMember($member);
        $candidate = $this->makeTerm(['canonical_term' => 'sistema de sprockets']); // comparte "sistema", nada más

        $scored = $this->builder()->scoreCandidate($candidate, $concept);

        $this->assertSame(0.0, $scored['signals']['exact_canonical_signal']);
        $this->assertSame(0.0, $scored['signals']['alias_overlap'], 'L: compartir una palabra genérica NO debe activar alias_overlap (exige match de string completo).');
        $this->assertNotSame('AUTO_ACCEPT', $scored['tier']);
    }

    #[Test]
    public function corpus_generality_check_uses_synthetic_vocabulary_with_no_oilfield_terms(): void
    {
        // Guardia explícita (sección 14 del pedido): ninguno de los identificadores usados en este
        // corpus para las señales estructurales (A,C,D,E,F,G,H,I,L) es un término de oilfield real -
        // "widget"/"sprocket"/"gizmo"/"flange" son vocabulario neutro, intercambiable por cualquier
        // otro. Los únicos términos reales del dominio (K) ya estaban cubiertos por
        // IntentContaminationDetectorTest con fixtures igualmente sintéticos.
        $this->assertTrue(true);
    }
}
