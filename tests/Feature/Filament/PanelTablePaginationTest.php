<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\CompletionView;
use App\Filament\Resources\TaxonomyCategoryResource\Pages\ListTaxonomyCategories;
use App\Filament\Tables\PanelTable;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Staging 2026-11-11: con «Todos» elegido, el listado de categorías (~3.100 filas) superaba los
 * 30 s de PHP y se quedaba cargando; la elección queda en la sesión, así que no salía de ahí.
 * La regla es global (AdminPanelProvider): ninguna tabla del panel ofrece «Todos».
 */
class PanelTablePaginationTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create();
        $admin->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p, 'web'), [
            'view_any_taxonomy::category', 'view_taxonomy::category', 'page_CompletionView',
        ]));
        $this->actingAs($admin);
    }

    #[Test]
    public function no_panel_table_offers_all_records(): void
    {
        $this->assertNotContains('all', AdminPanelProvider::TABLE_PAGE_OPTIONS);

        foreach ([ListTaxonomyCategories::class, CompletionView::class] as $component) {
            $options = Livewire::test($component)->instance()->getTable()->getPaginationPageOptions();

            $this->assertSame(AdminPanelProvider::TABLE_PAGE_OPTIONS, $options, $component);
        }
    }

    #[Test]
    public function an_explicit_all_option_is_dropped_and_other_options_are_kept(): void
    {
        $table = Livewire::test(ListTaxonomyCategories::class)->instance()->getTable();
        $this->assertInstanceOf(PanelTable::class, $table);

        $this->assertSame([10, 25], $table->paginationPageOptions([10, 25, 'all'])->getPaginationPageOptions());
        $this->assertSame(AdminPanelProvider::TABLE_PAGE_OPTIONS, $table->paginationPageOptions(['all'])->getPaginationPageOptions());
    }

    #[Test]
    public function a_stored_all_selection_falls_back_to_a_bounded_page(): void
    {
        $key = 'tables.'.md5(ListTaxonomyCategories::class).'_per_page';
        session()->put($key, 'all');

        Livewire::test(ListTaxonomyCategories::class)
            ->loadTable()
            ->assertSet('tableRecordsPerPage', AdminPanelProvider::TABLE_PAGE_OPTIONS[0])
            ->assertSuccessful();

        $this->assertFalse(session()->has($key));
    }
}
