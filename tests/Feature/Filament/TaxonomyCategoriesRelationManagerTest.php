<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\EmpresaResource\Pages\EditEmpresa;
use App\Filament\Resources\EmpresaResource\RelationManagers\TaxonomyCategoriesRelationManager;
use App\Models\Empresa;
use App\Models\EmpresaTaxonomyCategory;
use App\Models\TaxonomyCategory;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 2026-09-25 (pedido del cliente en reunión): una empresa ya no puede vincularse a una Familia CPV
 * completa desde el buscador libre de esta pestaña - solo a categorías específicas (nivel hoja).
 * Antes, elegir una Familia la agregaba de un solo click, sin obligar a marcar cuáles categorías
 * hijas realmente cubre - el mecanismo real que preocupaba al cliente para "inflar" resultados de
 * búsqueda.
 *
 * `Empresa`/`User`/`TaxonomyCategory` no tienen `$connection` propio - todos resuelven al default de
 * esta máquina (`DB_CONNECTION=pgsql` en `.env`, confirmado antes de escribir este test), así que
 * `DatabaseTransactions` sobre `pgsql` alcanza para revertir todo, igual que el resto de la suite.
 */
class TaxonomyCategoriesRelationManagerTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * @return array{0: Empresa, 1: User}
     *
     * `Empresa::created()` (app\Models\Empresa.php:97-104) vincula automáticamente la empresa nueva
     * a `Auth::User()` en `empresa_user` - por eso el usuario tiene que estar autenticado ANTES de
     * crear la empresa, no al revés (si no, ese hook revienta con "Auth::User() es null").
     */
    private function empresaConUsuario(): array
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $empresa = Empresa::create([
            'rif' => 'J'.random_int(10000000, 99999999).'-'.random_int(0, 9),
            'name' => 'zzz_test_empresa_'.uniqid('', true),
        ]);

        return [$empresa, $user];
    }

    /** @return array{0: TaxonomyCategory, 1: TaxonomyCategory} Grupo->Familia y una Categoría hija de esa Familia. */
    private function familiaConHija(): array
    {
        $grupo = TaxonomyCategory::create([
            'code' => 'CPV-'.random_int(10, 98),
            'parent_id' => null,
            'is_active' => true,
            'source_version' => 'test',
        ]);
        $familia = TaxonomyCategory::create([
            'code' => $grupo->code.'.'.random_int(10, 98),
            'parent_id' => $grupo->id,
            'is_active' => true,
            'source_version' => 'test',
        ]);
        $categoria = TaxonomyCategory::create([
            'code' => $familia->code.'.'.random_int(10, 98).'G',
            'parent_id' => $familia->id,
            'is_active' => true,
            'source_version' => 'test',
        ]);

        return [$familia, $categoria];
    }

    #[Test]
    public function selecting_a_family_directly_never_creates_a_row_for_it(): void
    {
        [$empresa, $user] = $this->empresaConUsuario();
        [$familia, $categoria] = $this->familiaConHija();

        Livewire::actingAs($user)
            ->test(TaxonomyCategoriesRelationManager::class, [
                'ownerRecord' => $empresa,
                'pageClass' => EditEmpresa::class,
            ])
            ->callTableAction('buscarYAgregar', data: [
                'categorias' => [$familia->id],
                'familia_a_explorar' => null,
                'categorias_de_familia' => [],
                'tipo' => 'secundaria',
            ]);

        $this->assertSame(0, EmpresaTaxonomyCategory::query()
            ->where('empresa_id', $empresa->id)
            ->where('category_id', $familia->id)
            ->count(), 'Nunca debe crearse una fila para el id de una Familia completa.');
    }

    #[Test]
    public function selecting_a_specific_leaf_category_creates_the_row(): void
    {
        [$empresa, $user] = $this->empresaConUsuario();
        [$familia, $categoria] = $this->familiaConHija();

        Livewire::actingAs($user)
            ->test(TaxonomyCategoriesRelationManager::class, [
                'ownerRecord' => $empresa,
                'pageClass' => EditEmpresa::class,
            ])
            ->callTableAction('buscarYAgregar', data: [
                'categorias' => [$categoria->id],
                'familia_a_explorar' => null,
                'categorias_de_familia' => [],
                'tipo' => 'principal',
            ]);

        $row = EmpresaTaxonomyCategory::query()
            ->where('empresa_id', $empresa->id)
            ->where('category_id', $categoria->id)
            ->first();

        $this->assertNotNull($row, 'La categoría hoja específica sí debe guardarse.');
        $this->assertSame(EmpresaTaxonomyCategory::ORIGEN_SELF_DECLARED, $row->origen);
        $this->assertTrue((bool) $row->es_principal);
    }

    #[Test]
    public function the_family_explorer_checkbox_list_still_saves_its_children_normally(): void
    {
        [$empresa, $user] = $this->empresaConUsuario();
        [$familia, $categoria] = $this->familiaConHija();

        Livewire::actingAs($user)
            ->test(TaxonomyCategoriesRelationManager::class, [
                'ownerRecord' => $empresa,
                'pageClass' => EditEmpresa::class,
            ])
            ->callTableAction('buscarYAgregar', data: [
                'categorias' => [],
                'familia_a_explorar' => (string) $familia->id,
                'categorias_de_familia' => [$categoria->id],
                'tipo' => 'secundaria',
            ]);

        $this->assertSame(1, EmpresaTaxonomyCategory::query()
            ->where('empresa_id', $empresa->id)
            ->where('category_id', $categoria->id)
            ->count());
    }

    #[Test]
    public function mixing_a_family_id_and_a_valid_leaf_id_keeps_only_the_leaf(): void
    {
        [$empresa, $user] = $this->empresaConUsuario();
        [$familia, $categoria] = $this->familiaConHija();

        Livewire::actingAs($user)
            ->test(TaxonomyCategoriesRelationManager::class, [
                'ownerRecord' => $empresa,
                'pageClass' => EditEmpresa::class,
            ])
            ->callTableAction('buscarYAgregar', data: [
                'categorias' => [$familia->id, $categoria->id],
                'familia_a_explorar' => null,
                'categorias_de_familia' => [],
                'tipo' => 'secundaria',
            ]);

        $this->assertSame(0, EmpresaTaxonomyCategory::query()->where('empresa_id', $empresa->id)->where('category_id', $familia->id)->count());
        $this->assertSame(1, EmpresaTaxonomyCategory::query()->where('empresa_id', $empresa->id)->where('category_id', $categoria->id)->count());
    }
}
