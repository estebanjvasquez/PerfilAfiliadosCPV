<?php

namespace Tests\Unit\Taxonomy;

use App\Models\TaxonomyTerm;
use App\Models\TaxonomyTermAlias;
use App\Models\TaxonomyTermCpvRelation;
use App\Services\Taxonomy\PageTermMatcher;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TAXV2-13: `PageTermMatcher::match()` no toca la base de datos si se le pasa una colección de
 * términos ya armada - se prueba en memoria, con relaciones seteadas a mano vía `setRelation()`
 * (patrón estándar de Eloquent para esto), sin conexión real a pgsql.
 */
class PageTermMatcherTest extends TestCase
{
    private function makeTerm(string $canonical, array $aliases, float $weight, ?string $cpvCode = 'CPV-05.01', float $exclusivity = 0.8): TaxonomyTerm
    {
        $term = new TaxonomyTerm(['canonical_term' => $canonical, 'term' => $canonical, 'oil_gas_exclusivity' => $exclusivity]);
        $term->id = random_int(1, 1_000_000);

        $term->setRelation('aliases', new Collection(array_map(
            fn ($alias) => new TaxonomyTermAlias(['alias' => $alias]),
            $aliases
        )));

        $relation = new TaxonomyTermCpvRelation(['cpv_code' => $cpvCode, 'weight' => $weight, 'status' => 'approved']);
        $term->setRelation('cpvRelations', new Collection([$relation]));

        return $term;
    }

    #[Test]
    public function matches_canonical_term_case_insensitively(): void
    {
        $wellhead = $this->makeTerm('wellhead', ['well head'], 0.9);

        $matches = (new PageTermMatcher())->match(
            'Our company specializes in Wellhead equipment and services.',
            new Collection([$wellhead])
        );

        $this->assertCount(1, $matches);
        $this->assertSame('Wellhead', $matches[0]['matched_text']);
        $this->assertSame($wellhead, $matches[0]['term']);
    }

    #[Test]
    public function matches_via_alias_when_canonical_term_is_absent(): void
    {
        $wellhead = $this->makeTerm('wellhead', ['arbolito'], 0.9);

        $matches = (new PageTermMatcher())->match('Servicio de mantenimiento de arbolito en campo.', new Collection([$wellhead]));

        $this->assertCount(1, $matches);
        $this->assertSame('arbolito', mb_strtolower($matches[0]['matched_text']));
    }

    #[Test]
    public function respects_word_boundaries_to_avoid_false_positives(): void
    {
        $oil = $this->makeTerm('oil', [], 0.5);

        $matches = (new PageTermMatcher())->match('Nuestro equipo de boilermakers es experto en soldadura.', new Collection([$oil]));

        $this->assertCount(0, $matches);
    }

    #[Test]
    public function skips_terms_with_no_needle_at_least_3_chars(): void
    {
        $short = $this->makeTerm('a', [], 0.5);

        $matches = (new PageTermMatcher())->match('Texto que contiene la letra a varias veces.', new Collection([$short]));

        $this->assertCount(0, $matches);
    }

    #[Test]
    public function returns_at_most_one_match_per_term(): void
    {
        $wellhead = $this->makeTerm('wellhead', [], 0.9);

        $matches = (new PageTermMatcher())->match('wellhead wellhead wellhead', new Collection([$wellhead]));

        $this->assertCount(1, $matches);
    }
}
