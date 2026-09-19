<?php

namespace App\Services\Taxonomy;

use App\Models\TaxonomyIntentMarker;

/**
 * Phase 3 (sección 2/13 del pedido): detección GENERAL, estructural, de contaminación SUBJECT/INTENT
 * dentro de un cluster de términos (un `taxonomy_canonical_concepts` existente, o un candidato del
 * Builder contra los miembros actuales de un concepto).
 *
 * Regla semántica que protege: `taxonomy_term_concepts` significa IDENTIDAD/EXPRESIÓN
 * (TERM --IS_EXPRESSION_OF--> CONCEPT), nunca RELATED_TO/USED_FOR/MAINTAINS/MANUFACTURES/etc. Un
 * término [INTENT + SUBJECT] (ej. "mantenimiento de X") no es una expresión de identidad de X - es
 * una intención de negocio aplicada a X, y agruparlo en el mismo concepto que X colapsa esa
 * distinción (POSSIBLE_INTENT_CONCEPT_COLLAPSE).
 *
 * DELIBERADAMENTE NO compara contra ningún término de ejemplo concreto ("artificial lift", "cabria",
 * etc.) - solo contra el vocabulario GOBERNADO de `taxonomy_intent_markers` (mantenimiento/alquiler/
 * inspección/fabricación/instalación/reparación/suministro/transporte/ingeniería, sección 2 del
 * pedido) y contra la relación estructural "el término largo contiene literalmente al corto Y a un
 * marcador de intención". Los tests de este detector usan fixtures sintéticos con vocabulario
 * completamente distinto (sección 21/13 del pedido) para demostrar que no hay hardcoding oilfield.
 */
class IntentContaminationDetector
{
    /** @param array<int, string> $markers Formas normalizadas (minúsculas, sin acentos) ES+EN. */
    public function __construct(private readonly array $markers) {}

    public static function fromGovernedVocabulary(): self
    {
        return new self(TaxonomyIntentMarker::activeNormalizedForms());
    }

    public function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = normalizer_normalize($text, \Normalizer::FORM_D) ?: $text;
        $text = preg_replace('/[\x{0300}-\x{036f}]/u', '', $text) ?? $text;

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    /**
     * Compara un par de términos. Si el más largo contiene literalmente (como palabra completa,
     * tolerante a plural simple) al más corto Y a un marcador de intención que el término corto NO
     * tiene, se considera un par [INTENT + SUBJECT] vs [SUBJECT].
     *
     * @return array{marker:string, subject_term:string, intent_term:string}|null
     */
    public function detectPair(string $termA, string $termB): ?array
    {
        $normalizedA = $this->normalize($termA);
        $normalizedB = $this->normalize($termB);

        if ($normalizedA === '' || $normalizedB === '' || $normalizedA === $normalizedB) {
            return null;
        }

        [$shorter, $longer, $shorterOriginal, $longerOriginal] = mb_strlen($normalizedA) <= mb_strlen($normalizedB)
            ? [$normalizedA, $normalizedB, $termA, $termB]
            : [$normalizedB, $normalizedA, $termB, $termA];

        if (! $this->containsWord($longer, $shorter)) {
            return null;
        }

        foreach ($this->markers as $marker) {
            if ($marker === '') {
                continue;
            }

            if ($this->containsWord($longer, $marker) && ! $this->containsWord($shorter, $marker)) {
                return [
                    'marker' => $marker,
                    'subject_term' => $shorterOriginal,
                    'intent_term' => $longerOriginal,
                ];
            }
        }

        return null;
    }

    /**
     * Corre `detectPair` sobre todos los pares de un cluster (un concepto existente, o un candidato
     * contra los miembros actuales de un concepto). O(n²) sobre el tamaño del cluster - aceptable
     * porque un cluster son términos de UN concepto (decenas, no miles); nunca se corre all-pairs
     * sobre el diccionario completo.
     *
     * @param array<int, string> $terms
     * @return array<int, array{marker:string, subject_term:string, intent_term:string}>
     */
    public function detectClusterContamination(array $terms): array
    {
        $terms = array_values(array_unique($terms));
        $matches = [];

        for ($i = 0; $i < count($terms); $i++) {
            for ($j = $i + 1; $j < count($terms); $j++) {
                $match = $this->detectPair($terms[$i], $terms[$j]);
                if ($match !== null) {
                    $matches[] = $match;
                }
            }
        }

        return $matches;
    }

    /** Señal 0..1 para el scoring del Builder: 1.0 si el candidato contamina el cluster existente. */
    public function contaminationPenaltySignal(string $candidateTerm, array $existingClusterTerms): float
    {
        foreach ($existingClusterTerms as $existingTerm) {
            if ($this->detectPair($candidateTerm, $existingTerm) !== null) {
                return 1.0;
            }
        }

        return 0.0;
    }

    /** Palabra completa, tolerante a un plural simple en español/inglés (cabria/cabrias, tool/tools). */
    private function containsWord(string $haystack, string $needle): bool
    {
        $pattern = '/(?<![\p{L}])'.preg_quote($needle, '/').'(e?s)?(?![\p{L}])/u';

        return (bool) preg_match($pattern, $haystack);
    }
}
