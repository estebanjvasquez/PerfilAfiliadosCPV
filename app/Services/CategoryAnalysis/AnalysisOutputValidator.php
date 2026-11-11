<?php

namespace App\Services\CategoryAnalysis;

use App\Models\TaxonomyCategoryRequestAnalysis;

/**
 * TASK-0016: valida y sanea la respuesta del modelo contra AnalysisPrompt::outputSchema().
 *
 * - Una forma inválida (JSON roto, falta un campo obligatorio, una relación o recomendación fuera de la lista)
 *   descarta la respuesta entera: el análisis queda `failed` con `invalid_output`.
 * - Lo que apunta fuera de lo entregado se QUITA y se anota en `issues`: categorías que no se eligieron, sugerencias
 *   que no estaban entre las candidatas del catálogo y fuentes que no se recuperaron. Así ninguna categoría inventada
 *   ni ninguna cita inventada llega a la pantalla.
 * - Los textos se recortan a un largo razonable; la vista los escapa al mostrarlos.
 */
final class AnalysisOutputValidator
{
    private const MAX_TEXT = 4000;

    private const MAX_ITEMS = 20;

    /**
     * @param  list<int>  $selectedIds
     * @param  list<int>  $candidateIds
     * @param  list<string>  $sourceIds
     * @return array{output: array<string, mixed>, issues: list<string>}
     *
     * @throws ProviderException con `invalid_output`
     */
    public function validate(string $json, array $selectedIds, array $candidateIds, array $sourceIds): array
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            throw new ProviderException('invalid_output', 'not a JSON object');
        }

        $schema = AnalysisPrompt::outputSchema();
        $this->requireKeys($data, $schema['required']);
        $issues = [];

        foreach (array_diff(array_keys($data), array_keys($schema['properties'])) as $extra) {
            $issues[] = "Se descartó el campo no previsto «{$extra}».";
        }

        $categories = [];
        foreach ($this->list($data['categories']) as $item) {
            if (! is_array($item)) {
                throw new ProviderException('invalid_output', 'category item');
            }
            $this->requireKeys($item, $schema['properties']['categories']['items']['required']);
            $id = $this->int($item['category_id']);
            if (! in_array($id, $selectedIds, true)) {
                $issues[] = "Se descartó un juicio sobre la categoría {$id}, que no estaba elegida.";

                continue;
            }
            if (! array_key_exists($item['relation'], TaxonomyCategoryRequestAnalysis::RELATIONS)) {
                throw new ProviderException('invalid_output', 'relation');
            }
            $cited = $this->strings($item['source_ids']);
            $kept = array_values(array_intersect($cited, $sourceIds));
            foreach (array_diff($cited, $sourceIds) as $unknown) {
                $issues[] = "Se descartó la cita «{$this->text($unknown, 40)}», que no está entre las fuentes recuperadas.";
            }
            $categories[$id] = [
                'category_id' => $id,
                'relation' => $item['relation'],
                'overlaps' => $this->strings($item['overlaps']),
                'differences' => $this->strings($item['differences']),
                'covered_activities' => $this->strings($item['covered_activities']),
                'uncovered_activities' => $this->strings($item['uncovered_activities']),
                'rationale' => $this->text($item['rationale']),
                'source_ids' => $kept,
            ];
        }
        foreach (array_diff($selectedIds, array_keys($categories)) as $missing) {
            $issues[] = "La IA no evaluó la categoría elegida {$missing}.";
        }

        $suggested = [];
        foreach ($this->list($data['suggested_category_ids']) as $raw) {
            $id = $this->int($raw);
            if (in_array($id, $candidateIds, true) && ! in_array($id, $selectedIds, true)) {
                $suggested[] = $id;
            } else {
                $issues[] = "Se descartó la sugerencia {$id}: no es una candidata del catálogo sin elegir.";
            }
        }

        $recommendation = $data['recommendation'];
        if (! is_array($recommendation)) {
            throw new ProviderException('invalid_output', 'recommendation');
        }
        $this->requireKeys($recommendation, $schema['properties']['recommendation']['required']);
        if (! array_key_exists($recommendation['type'], TaxonomyCategoryRequestAnalysis::RECOMMENDATIONS)) {
            throw new ProviderException('invalid_output', 'recommendation type');
        }
        $recommended = [];
        foreach ($this->list($recommendation['category_ids']) as $raw) {
            $id = $this->int($raw);
            if (in_array($id, $selectedIds, true) || in_array($id, $suggested, true)) {
                $recommended[] = $id;
            } else {
                $issues[] = "Se descartó la categoría recomendada {$id}, que no estaba en el análisis.";
            }
        }

        return [
            'output' => [
                'summary' => $this->text($data['summary']),
                'interpretation' => $this->text($data['interpretation']),
                'categories' => array_values($categories),
                'suggested_category_ids' => array_values(array_unique($suggested)),
                'recommendation' => [
                    'type' => $recommendation['type'],
                    'rationale' => $this->text($recommendation['rationale']),
                    'category_ids' => array_values(array_unique($recommended)),
                ],
                'clarification_questions' => $this->strings($data['clarification_questions']),
                'limitations' => $this->strings($data['limitations']),
            ],
            'issues' => $issues,
        ];
    }

    private function requireKeys(array $data, array $keys): void
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data)) {
                throw new ProviderException('invalid_output', "missing {$key}");
            }
        }
    }

    private function list(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new ProviderException('invalid_output', 'list expected');
        }

        return array_slice($value, 0, self::MAX_ITEMS);
    }

    private function int(mixed $value): int
    {
        if (! is_int($value)) {
            throw new ProviderException('invalid_output', 'integer expected');
        }

        return $value;
    }

    private function text(mixed $value, int $max = self::MAX_TEXT): string
    {
        if (! is_string($value)) {
            throw new ProviderException('invalid_output', 'string expected');
        }

        return mb_substr(trim($value), 0, $max);
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        return array_values(array_map(fn ($v) => $this->text($v, 1000), $this->list($value)));
    }
}
