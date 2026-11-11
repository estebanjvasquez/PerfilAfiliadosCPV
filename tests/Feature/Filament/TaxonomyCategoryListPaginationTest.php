<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\TaxonomyCategoryResource;
use App\Filament\Resources\TaxonomyCategoryResource\Pages\ListTaxonomyCategories;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Staging 2026-11-11: con «Todos» elegido, el listado de categorías (~3.100 filas) superaba los
 * 30 s de PHP y se quedaba cargando; la elección queda en la sesión, así que no salía de ahí.
 */
class TaxonomyCategoryListPaginationTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create();
        $admin->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p, 'web'), ['view_any_taxonomy::category', 'view_taxonomy::category']));
        $this->actingAs($admin);
    }

    #[Test]
    public function the_category_list_does_not_offer_all_records(): void
    {
        $table = Livewire::test(ListTaxonomyCategories::class)->instance()->getTable();

        $this->assertSame(TaxonomyCategoryResource::PAGE_OPTIONS, $table->getPaginationPageOptions());
        $this->assertNotContains('all', $table->getPaginationPageOptions());
    }

    #[Test]
    public function a_stored_all_selection_falls_back_to_a_bounded_page(): void
    {
        $key = 'tables.'.md5(ListTaxonomyCategories::class).'_per_page';
        session()->put($key, 'all');

        Livewire::test(ListTaxonomyCategories::class)
            ->loadTable()
            ->assertSet('tableRecordsPerPage', TaxonomyCategoryResource::PAGE_OPTIONS[0])
            ->assertSuccessful();

        $this->assertFalse(session()->has($key));
    }
}
