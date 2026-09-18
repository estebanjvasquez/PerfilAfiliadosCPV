<?php

namespace App\Services\Taxonomy;

use App\Models\TaxonomyTerm;
use Illuminate\Support\Collection;

/**
 * TAXV2-13 (sección 6-8 del documento de instrucciones): compara el texto visible de una página de
 * empresa contra el diccionario real ya construido en TAXV2-1/2 - reutiliza esas relaciones
 * aprobadas en vez de reconstruir un motor de matching aparte (mismo principio de TAXV2-5: no
 * duplicar infraestructura de ranking/matching ya calibrada).
 *
 * Solo compara contra términos que YA tienen al menos una relación CPV `approved` (unos pocos
 * cientos de los 1.837, ver conteos de TAXV2-2) - son los únicos capaces de aportar evidencia
 * accionable (un `cpv_code`/`relation_weight` reales), lo que mantiene el loop de comparación por
 * página acotado y rápido.
 */
class PageTermMatcher
{
    /**
     * @return array<int, array{term: TaxonomyTerm, matched_text: string, context: string}>
     */
    public function match(string $pageText, ?Collection $candidateTerms = null): array
    {
        $terms = $candidateTerms ?? $this->defaultCandidateTerms();
        $haystack = ' '.$pageText.' ';
        $matches = [];

        foreach ($terms as $term) {
            $needles = array_values(array_unique(array_filter(array_merge(
                [$term->canonical_term ?: $term->term],
                $term->aliases->pluck('alias')->all()
            ))));

            foreach ($needles as $needle) {
                $needle = trim($needle);
                if (mb_strlen($needle) < 3) {
                    continue;
                }

                $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/iu';

                if (preg_match($pattern, $haystack, $match, PREG_OFFSET_CAPTURE)) {
                    $offset = $match[0][1];
                    $context = trim(mb_substr($haystack, max(0, $offset - 60), 160));

                    $matches[] = [
                        'term' => $term,
                        'matched_text' => $match[0][0],
                        'context' => $context,
                    ];

                    break;
                }
            }
        }

        return $matches;
    }

    public function defaultCandidateTerms(): Collection
    {
        return TaxonomyTerm::query()
            ->with([
                'aliases',
                'cpvRelations' => fn ($q) => $q->where('status', 'approved'),
            ])
            ->whereHas('cpvRelations', fn ($q) => $q->where('status', 'approved'))
            ->get();
    }
}
