<?php

namespace Tests\Feature\Taxonomy;

use App\Filament\Resources\TaxonomyCategoryResource;
use App\Filament\Resources\TaxonomyCategoryResource\Pages\ListTaxonomyCategories;
use App\Filament\Resources\TaxonomyFamilyResource\Pages\ListTaxonomyFamilies;
use App\Filament\Resources\TaxonomyGroupResource\Pages\ListTaxonomyGroups;
use App\Models\TaxonomyCategory;
use App\Models\User;
use App\Services\Taxonomy\TaxonomyLookup;
use App\Services\TaxonomyCategorySearch;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Taxonomy\Concerns\TaxonomySearchFixtures;
use Tests\TestCase;

/**
 * TASK-0014 (Issue #2 `6736283026`): la búsqueda de taxonomía es coherente entre el selector de la
 * empresa y los listados de administración.
 *
 * Caso reproducido en staging (`90044f3`):
 * - con «balanc» la empresa veía dos opciones, «Balancín» y «Perforación Bajobalanceada (UBD)»;
 * - en administración, «balancin» no encontraba nada, porque `like` distingue tildes y mayúsculas.
 *
 * Estas pruebas fijan el criterio común (`TaxonomyLookup`) y sus diferencias de alcance:
 * - la empresa ve solo lo activo y elegible, y del diccionario solo lo aprobado;
 * - la administración ve todos los estados.
 */
class TaxonomySearchCoherenceTest extends TestCase
{
    use DatabaseTransactions;
    use TaxonomySearchFixtures;

    protected $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.mcp.embed_url' => null, 'services.mcp.embed_token' => null]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->buildBalancinTree();
    }

    private function lookup(): TaxonomyLookup
    {
        return app(TaxonomyLookup::class);
    }

    /** @return list<int> */
    private function companyIds(string $q): array
    {
        return app(TaxonomyCategorySearch::class)->search($q, 50)->pluck('category_id')->all();
    }

    public static function accentAndCaseVariants(): array
    {
        return [['balancín'], ['balancin'], ['Balancín'], ['BALANCIN'], ['BaLaNcÍn']];
    }

    #[Test]
    #[DataProvider('accentAndCaseVariants')]
    public function company_and_admin_find_the_same_category_regardless_of_accents_and_case(string $variant): void
    {
        $q = "{$variant} {$this->tok}";
        $id = $this->nodes['balancin']->id;

        $this->assertContains($id, $this->companyIds($q), 'El selector de la empresa debe encontrarla.');
        $this->assertContains($id, TaxonomyCategory::query()
            ->tap(fn ($query) => $this->lookup()->constrainAdminQuery($query, $q))
            ->pluck('id')->all(), 'La administración debe encontrar la misma categoría.');
    }

    #[Test]
    public function a_partial_word_ranks_word_starts_first_and_labels_the_inside_word_match(): void
    {
        $results = app(TaxonomyCategorySearch::class)->search('balanc', 50)
            ->filter(fn ($r) => in_array($r['category_id'], [$this->nodes['balancin']->id, $this->nodes['bajo']->id], true))
            ->values();

        $this->assertSame([$this->nodes['balancin']->id, $this->nodes['bajo']->id], $results->pluck('category_id')->all(),
            '«Balancín» (empieza la palabra) va antes que «Bajobalanceada» (dentro de la palabra).');
        $this->assertStringContainsString('parte de una palabra', TaxonomyCategorySearch::optionLabel($results[1]));
        $this->assertStringNotContainsString('parte de una palabra', TaxonomyCategorySearch::optionLabel($results[0]));
    }

    #[Test]
    public function each_company_option_shows_route_code_and_reason(): void
    {
        $result = app(TaxonomyCategorySearch::class)->search("balancín {$this->tok}", 15)
            ->firstWhere('category_id', $this->nodes['balancin']->id);
        $label = TaxonomyCategorySearch::optionLabel($result);

        $this->assertStringContainsString("Terminación de Pozos {$this->tok} > Equipos {$this->tok} > Balancín {$this->tok}", $label);
        $this->assertStringContainsString($this->nodes['balancin']->code, $label);
        $this->assertStringContainsString('por nombre', $label);
        $this->assertStringContainsString("término aprobado del diccionario «balancín {$this->tok}»", $label,
            'Los motivos de todas las fuentes se juntan, no solo el del primer nivel.');
    }

    #[Test]
    public function english_names_codes_aliases_and_canonical_terms_find_the_category(): void
    {
        $id = $this->nodes['balancin']->id;

        foreach (["pumping unit {$this->tok}", $this->nodes['balancin']->code, "pump jack {$this->tok}", "pumpjack{$this->tok}"] as $q) {
            $this->assertContains($id, $this->companyIds($q), "Debe encontrarla con «{$q}».");
        }

        $kinds = collect($this->lookup()->matches("pumping unit {$this->tok}", TaxonomyLookup::SCOPE_COMPANY)
            ->firstWhere('category_id', $id)['reasons'])->pluck('kind');
        $this->assertContains(TaxonomyLookup::KIND_NAME_EN, $kinds);

        $byCode = $this->lookup()->matches($this->nodes['balancin']->code, TaxonomyLookup::SCOPE_ADMIN)->firstWhere('category_id', $id);
        $this->assertSame(TaxonomyLookup::QUALITY_EXACT, $byCode['quality']);
        $this->assertSame(TaxonomyLookup::KIND_CODE, $byCode['reasons'][0]['kind']);
    }

    #[Test]
    public function synonym_and_dictionary_term_are_distinct_reasons(): void
    {
        $synonym = $this->lookup()->matches("arbolito{$this->tok}", TaxonomyLookup::SCOPE_ADMIN)->firstWhere('category_id', $this->nodes['cabezal']->id);
        $this->assertSame(TaxonomyLookup::KIND_SYNONYM, $synonym['reasons'][0]['kind']);
        $this->assertStringStartsWith('Sinónimo de la categoría', TaxonomyLookup::describeForAdmin($synonym['reasons'][0]));

        $term = $this->lookup()->matches("wellhead{$this->tok}", TaxonomyLookup::SCOPE_ADMIN)->firstWhere('category_id', $this->nodes['cabezal']->id);
        $this->assertContains(TaxonomyLookup::KIND_TERM, collect($term['reasons'])->pluck('kind'));
        $this->assertStringContainsString('relación en revisión', TaxonomyLookup::describeForAdmin($term['reasons'][0]));
    }

    #[Test]
    public function company_scope_hides_inactive_groups_and_unapproved_relations_while_admin_shows_them(): void
    {
        $this->assertNotContains($this->nodes['inactiva']->id, $this->companyIds("Balanceo del Timón {$this->tok}"), 'Una categoría inactiva no se ofrece a la empresa.');
        $this->assertContains($this->nodes['inactiva']->id, $this->lookup()->matches("Balanceo del Timón {$this->tok}", TaxonomyLookup::SCOPE_ADMIN)->pluck('category_id'));

        $this->assertNotContains($this->nodes['grupo']->id, $this->companyIds("Terminación de Pozos {$this->tok}"), 'Un Grupo no se ofrece a la empresa.');
        $this->assertContains($this->nodes['familia']->id, $this->companyIds("Equipos {$this->tok}"), 'Una Familia sí: el selector la abre en el explorador.');

        $this->assertNotContains($this->nodes['cabezal']->id, $this->companyIds("wellhead{$this->tok}"), 'Una relación en revisión no lleva a la empresa a la categoría.');
        $this->assertContains($this->nodes['cabezal']->id, $this->lookup()->matches("wellhead{$this->tok}", TaxonomyLookup::SCOPE_ADMIN)->pluck('category_id'));
    }

    #[Test]
    public function a_category_match_locates_its_family_and_group_in_admin(): void
    {
        $q = "balancin {$this->tok}";
        $byLevel = fn (int $level) => TaxonomyCategory::query()->where('level', $level)
            ->tap(fn ($query) => $this->lookup()->constrainAdminQuery($query, $q))
            ->pluck('id')->all();

        $this->assertSame([$this->nodes['balancin']->id], $byLevel(TaxonomyCategory::LEVEL_CATEGORY));
        $this->assertSame([$this->nodes['familia']->id], $byLevel(TaxonomyCategory::LEVEL_FAMILY));
        $this->assertSame([$this->nodes['grupo']->id], $byLevel(TaxonomyCategory::LEVEL_GROUP));

        $reasons = $this->lookup()->adminReasonsFor($this->nodes['familia']->id, $q, [$this->nodes['familia']->id]);
        $this->assertNotEmpty(
            array_filter($reasons, fn (string $line) => str_starts_with($line, 'Contiene '.$this->nodes['balancin']->code.': Nombre ES «Balancín')),
            'La familia dice qué categoría hija coincidió: '.implode(' | ', $reasons)
        );
    }

    #[Test]
    public function like_wildcards_in_the_query_are_literal(): void
    {
        $this->assertNotContains($this->nodes['balancin']->id, $this->lookup()->matches('%', TaxonomyLookup::SCOPE_ADMIN)->pluck('category_id'));
        $this->assertNotContains($this->nodes['balancin']->id, $this->lookup()->matches('_', TaxonomyLookup::SCOPE_ADMIN)->pluck('category_id'));
        $this->assertCount(0, $this->lookup()->matches('(', TaxonomyLookup::SCOPE_ADMIN)->where('category_id', $this->nodes['balancin']->id));
    }

    #[Test]
    public function semantic_suggestions_are_labelled_as_unconfirmed(): void
    {
        $label = TaxonomyCategorySearch::optionLabel([
            'breadcrumb' => 'A > B', 'code' => 'CPV-X',
            'reasons' => [['kind' => TaxonomyLookup::KIND_SEMANTIC, 'matched' => '', 'quality' => 0, 'relation_status' => null]],
        ]);

        $this->assertStringContainsString('sugerencia por similitud, sin confirmar', $label);
        $this->assertStringContainsString('no es un vínculo aprobado', TaxonomyLookup::describeForAdmin(['kind' => TaxonomyLookup::KIND_SEMANTIC]));
    }

    #[Test]
    public function the_id_the_company_picks_is_the_category_the_admin_sees(): void
    {
        $picked = app(TaxonomyCategorySearch::class)->search("balancín {$this->tok}", 15)->first();
        $adminRow = TaxonomyCategory::query()->where('level', TaxonomyCategory::LEVEL_CATEGORY)
            ->tap(fn ($query) => $this->lookup()->constrainAdminQuery($query, "balancin {$this->tok}"))
            ->sole();

        $this->assertSame($picked['category_id'], $adminRow->id);
        $this->assertSame($picked['code'], $adminRow->code);
        $this->assertSame($picked['breadcrumb'], $adminRow->breadcrumb('es'));
    }

    #[Test]
    public function the_admin_lists_search_and_explain_through_the_shared_criterion(): void
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p, 'web'), ['view_any_taxonomy::category', 'view_taxonomy::category']));
        $this->actingAs($admin);

        Livewire::test(ListTaxonomyCategories::class)
            ->loadTable()
            ->searchTable("balancin {$this->tok}")
            ->assertCanSeeTableRecords([$this->nodes['balancin']])
            ->assertCanNotSeeTableRecords([$this->nodes['bajo'], $this->nodes['cabezal']])
            ->assertTableColumnVisible('coincidencia')
            ->assertSee('Nombre ES «Balancín '.$this->tok.'»')
            ->assertSee('relación aprobada');

        Livewire::test(ListTaxonomyFamilies::class)
            ->loadTable()
            ->searchTable("balancin {$this->tok}")
            ->assertCanSeeTableRecords([$this->nodes['familia']])
            ->assertSee('Contiene '.$this->nodes['balancin']->code);

        Livewire::test(ListTaxonomyGroups::class)
            ->loadTable()
            ->searchTable("balancin {$this->tok}")
            ->assertCanSeeTableRecords([$this->nodes['grupo']]);

        Livewire::test(ListTaxonomyCategories::class)
            ->loadTable()
            ->filterTable('termino_diccionario', ['termino' => "wellhead{$this->tok}", 'estado' => 'needs_review'])
            ->assertCanSeeTableRecords([$this->nodes['cabezal']])
            ->assertCanNotSeeTableRecords([$this->nodes['balancin']]);

        $this->assertSame('Código, nombre ES/EN, sinónimo o término del diccionario', TaxonomyCategoryResource::SEARCH_PLACEHOLDER);
    }
}
