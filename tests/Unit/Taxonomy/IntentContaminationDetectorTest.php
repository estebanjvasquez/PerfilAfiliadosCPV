<?php

namespace Tests\Unit\Taxonomy;

use App\Services\Taxonomy\IntentContaminationDetector;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 3 (sección 13/21 del pedido): el detector debe ser GENERAL - se prueba con fixtures
 * SINTÉTICOS de vocabulario completamente distinto al oilfield para demostrar que la lógica no
 * depende de ningún término de ejemplo concreto, y por separado con los 2 casos reales que motivaron
 * la sección 9 del PRE-PHASE-3 (concept #10 "artificial lift", concept #77 "Derrick and Mast") para
 * confirmar que el mecanismo GENERAL también los cubre - nunca al revés.
 */
class IntentContaminationDetectorTest extends TestCase
{
    private function detector(): IntentContaminationDetector
    {
        // Vocabulario de marcadores inyectado directamente (no depende de la BD) - mismas 9
        // categorías genéricas de negocio de la sección 2 del pedido.
        return new IntentContaminationDetector([
            'mantenimiento', 'maintenance', 'alquiler', 'rental', 'inspección', 'inspection',
            'fabricación', 'manufacturing', 'instalación', 'installation', 'reparación', 'repair',
            'suministro', 'supply', 'transporte', 'transport', 'ingeniería', 'engineering',
        ]);
    }

    #[Test]
    public function detects_intent_subject_contamination_with_completely_synthetic_vocabulary(): void
    {
        // "widget"/"sprocket" no existen en ningún dominio de este proyecto - demuestra que la
        // detección es estructural (marcador + subject), no un match contra un término de ejemplo.
        $match = $this->detector()->detectPair('widget', 'mantenimiento de widget');

        $this->assertNotNull($match);
        $this->assertSame('mantenimiento', $match['marker']);
    }

    #[Test]
    public function detects_english_suffix_pattern_with_synthetic_vocabulary(): void
    {
        $match = $this->detector()->detectPair('sprocket', 'sprocket maintenance');

        $this->assertNotNull($match);
        $this->assertSame('maintenance', $match['marker']);
    }

    #[Test]
    public function tolerates_simple_plural_of_the_subject(): void
    {
        $match = $this->detector()->detectPair('widget', 'mantenimiento de widgets');

        $this->assertNotNull($match);
    }

    #[Test]
    public function does_not_flag_two_unrelated_synthetic_terms(): void
    {
        $this->assertNull($this->detector()->detectPair('widget', 'sprocket'));
    }

    #[Test]
    public function does_not_flag_identical_terms(): void
    {
        $this->assertNull($this->detector()->detectPair('widget', 'widget'));
    }

    #[Test]
    public function does_not_flag_two_terms_that_are_both_intent_derived(): void
    {
        // "mantenimiento de widget" vs "reparación de widget" - ambos son INTENT+SUBJECT, ninguno
        // es el SUBJECT puro, así que no hay un par [SUBJECT] vs [INTENT+SUBJECT] que colapsar.
        $match = $this->detector()->detectPair('mantenimiento de widget', 'reparación de widget');

        $this->assertNull($match);
    }

    #[Test]
    public function cluster_detection_flags_the_real_concept_10_case_via_the_general_mechanism(): void
    {
        // Vocabulario real de concept #10 (PRE-PHASE-3 sección 9) - se pasa como DATO, no como
        // regla de código: el detector nunca compara contra "artificial lift" en su lógica.
        $terms = [
            'artificial lift',
            'artificial lift maintenance',
            'alquiler de levantamiento artificial',
            'inspección de levantamiento artificial',
        ];

        $matches = $this->detector()->detectClusterContamination($terms);

        $this->assertNotEmpty($matches);
    }

    #[Test]
    public function cluster_detection_flags_the_real_concept_77_case_via_the_general_mechanism(): void
    {
        $terms = ['cabria', 'derrick', 'mast', 'mantenimiento de cabrias', 'inspección de cabrias', 'fabricación de cabrias'];

        $matches = $this->detector()->detectClusterContamination($terms);

        $this->assertNotEmpty($matches);
    }

    #[Test]
    public function contamination_penalty_signal_is_zero_for_a_clean_synthetic_candidate(): void
    {
        $penalty = $this->detector()->contaminationPenaltySignal('gizmo', ['widget', 'sprocket']);

        $this->assertSame(0.0, $penalty);
    }

    #[Test]
    public function contamination_penalty_signal_is_one_when_candidate_is_intent_plus_existing_subject(): void
    {
        $penalty = $this->detector()->contaminationPenaltySignal('mantenimiento de widget', ['widget']);

        $this->assertSame(1.0, $penalty);
    }
}
