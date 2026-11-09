<?php

namespace Tests\Feature\Taxonomy\Concerns;

use App\Models\TaxonomyCategory;
use App\Models\TaxonomyCategorySynonym;
use App\Models\TaxonomyCategoryTranslation;
use App\Models\TaxonomyTerm;
use App\Models\TaxonomyTermAlias;
use App\Models\TaxonomyTermCpvRelation;

/**
 * TASK-0014: árbol sintético que reproduce el caso «balancín» sin depender de datos reales.
 *
 * Cada corrida lleva un sufijo propio (`$this->tok`) en los nombres y códigos de grupo de 3 dígitos
 * (`CPV-900`..`CPV-999`), fuera del rango de la taxonomía real, como el resto de la suite.
 *
 * Grupo «Terminación de Pozos {tok}» > Familia «Equipos {tok}» >
 * - `balancin`: «Balancín {tok}», EN «Pumping Unit {tok}», activa; término del diccionario
 *   «balancín {tok}» (canónico «pumpjack{tok}», alias «pump jack {tok}») con relación APROBADA;
 * - `bajo`: «Perforación Bajobalanceada {tok}», activa (subcadena «balanc» dentro de una palabra);
 * - `inactiva`: «Balanceo del Timón {tok}», INACTIVA;
 * - `cabezal`: «Cabezal de Pozo {tok}», con el sinónimo de categoría «arbolito{tok}» y el término
 *   «wellhead{tok}» cuya relación está EN REVISIÓN.
 */
trait TaxonomySearchFixtures
{
    protected string $tok;

    /** @var array<string, TaxonomyCategory> */
    protected array $nodes = [];

    protected TaxonomyTerm $balancinTerm;

    protected TaxonomyTerm $wellheadTerm;

    protected function buildBalancinTree(): void
    {
        $this->tok = 'zq'.strtolower(substr(str_replace('.', '', uniqid('', true)), -8));

        $group = $this->node('CPV-'.random_int(900, 999), null, ['es' => "Terminación de Pozos {$this->tok}"]);
        $family = $this->node($group->code.'.'.random_int(10, 98), $group, ['es' => "Equipos {$this->tok}"]);

        $this->nodes = [
            'grupo' => $group,
            'familia' => $family,
            'balancin' => $this->node($family->code.'.01G', $family, ['es' => "Balancín {$this->tok}", 'en' => "Pumping Unit {$this->tok}"]),
            'bajo' => $this->node($family->code.'.02S', $family, ['es' => "Perforación Bajobalanceada {$this->tok}"]),
            'inactiva' => $this->node($family->code.'.03G', $family, ['es' => "Balanceo del Timón {$this->tok}"], active: false),
            'cabezal' => $this->node($family->code.'.04G', $family, ['es' => "Cabezal de Pozo {$this->tok}"]),
        ];

        TaxonomyCategorySynonym::create([
            'category_id' => $this->nodes['cabezal']->id, 'locale' => 'es', 'term' => "arbolito{$this->tok}", 'origen' => 'test',
        ]);

        $this->balancinTerm = $this->term("balancín {$this->tok}", "pumpjack{$this->tok}");
        TaxonomyTermAlias::create(['term_id' => $this->balancinTerm->id, 'alias' => "pump jack {$this->tok}"]);
        $this->relation($this->balancinTerm, $this->nodes['balancin'], TaxonomyTermCpvRelation::STATUS_APPROVED);

        $this->wellheadTerm = $this->term("wellhead{$this->tok}", "wellhead{$this->tok}");
        $this->relation($this->wellheadTerm, $this->nodes['cabezal'], TaxonomyTermCpvRelation::STATUS_NEEDS_REVIEW);
    }

    protected function node(string $code, ?TaxonomyCategory $parent, array $names, bool $active = true): TaxonomyCategory
    {
        $node = TaxonomyCategory::create([
            'code' => $code,
            'parent_id' => $parent?->id,
            'is_active' => $active,
            'source_version' => 'test-task-0014',
        ]);

        foreach ($names as $locale => $name) {
            TaxonomyCategoryTranslation::create(['category_id' => $node->id, 'locale' => $locale, 'name' => $name]);
        }

        return $node->refresh();
    }

    protected function term(string $term, string $canonical): TaxonomyTerm
    {
        return TaxonomyTerm::create([
            'external_id' => 'task0014-'.uniqid('', true),
            'term' => $term,
            'language' => 'es',
            'canonical_term' => $canonical,
            'term_type' => TaxonomyTerm::TERM_TYPE_TECHNICAL,
            'oil_gas_exclusivity' => 0.9,
            'region' => [], 'negative_context' => [], 'positive_context' => [],
            'mapping_review_status' => TaxonomyTerm::MAPPING_AUTO_MAPPED,
        ]);
    }

    protected function relation(TaxonomyTerm $term, TaxonomyCategory $category, string $status, string $source = 'test'): TaxonomyTermCpvRelation
    {
        return TaxonomyTermCpvRelation::create([
            'term_id' => $term->id,
            'cpv_code' => $category->code,
            'category_id' => $category->id,
            'level' => TaxonomyTermCpvRelation::LEVEL_CATEGORY,
            'relation_type' => 'exact',
            'weight' => 0.99,
            'confidence' => 0.99,
            'source' => $source,
            'status' => $status,
        ]);
    }
}
