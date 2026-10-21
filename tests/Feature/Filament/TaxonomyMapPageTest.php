<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\TaxonomyMapPage;
use App\Filament\Pages\UserManual;
use App\Filament\Resources\EmpresaResource\Pages\EditEmpresa;
use App\Filament\Resources\EmpresaResource\RelationManagers\TaxonomyCategoriesRelationManager;
use App\Models\Empresa;
use App\Models\TaxonomyCategory;
use App\Models\TaxonomyCategoryTranslation;
use App\Models\User;
use App\Support\Taxonomy\TaxonomyMapBuilder;
use Filament\Facades\Filament;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * CPV-TAXONOMY-MAP: «Mapa de la taxonomía» (instrucción `6191513374`, correcciones `6191485610`).
 *
 * Corre en el PostgreSQL desechable del entorno aislado, nunca contra Supabase compartida.
 * `DatabaseTransactions` revierte cada fixture. La tabla puede traer filas de la fixture sintética
 * del arnés, así que los tests ubican SUS nodos por nombres únicos y no asumen el total global.
 */
class TaxonomyMapPageTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private string $u;

    private string $groupCode;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->u = substr(md5(uniqid('', true)), 0, 8);

        do {
            $this->groupCode = 'CPV-'.random_int(700, 899);
        } while (TaxonomyCategory::where('code', 'like', $this->groupCode.'%')->exists());
    }

    // ---------------------------------------------------------------- fixtures

    private function node(string $code, ?TaxonomyCategory $parent, string $name, bool $active = true, ?string $tipo = null, array $extra = []): TaxonomyCategory
    {
        $node = TaxonomyCategory::create([
            'code' => $code,
            'parent_id' => $parent?->id,
            'is_active' => $active,
            'tipo_oferta' => $tipo,
            'source_version' => 'test',
        ] + $extra);
        TaxonomyCategoryTranslation::create(['category_id' => $node->id, 'locale' => 'es', 'name' => $name]);

        return $node;
    }

    /** Fila con nivel/padre inconsistentes: el observer no deja crearla, así que va directo a la tabla. */
    private function malformed(string $code, ?int $parentId, int $level, string $name): void
    {
        $id = DB::connection('pgsql')->table('taxonomy_categories')->insertGetId([
            'code' => $code, 'parent_id' => $parentId, 'level' => $level, 'path' => $code,
            'is_active' => true, 'source_version' => 'test', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('pgsql')->table('taxonomy_category_translations')->insert([
            'category_id' => $id, 'locale' => 'es', 'name' => $name, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function n(string $label): string
    {
        return "ZZZ Mapa {$this->u} {$label}";
    }

    /**
     * Árbol de prueba:
     *   G1 (activo)
     *     F1 (activa): árbol(bien), Avión(servicio), bomba(sin tipo), [inactiva], Ñandú(bien), nube(servicio), zeta(bien)
     *     F2 (activa): solo una categoría inactiva -> «sin categorías todavía»
     *     F3 (INACTIVA): una categoría activa -> ambas fuera
     *   G2 (INACTIVO): familia y categoría activas -> fuera
     *   G3 (activo): una sola familia vacía -> Grupo «sin categorías todavía»
     *   Malformados: categoría colgada de un Grupo, familia sin padre, grupo con padre -> fuera
     */
    private function seedTree(): void
    {
        $c = $this->groupCode;
        $g1 = $this->node($c, null, $this->n('Grupo Uno'));
        $f1 = $this->node("{$c}.01", $g1, 'familia uno '.$this->u);
        $this->node("{$c}.01.01G", $f1, 'árbol '.$this->u, tipo: 'bien', extra: ['branch' => 'ZZZBRANCH'.$this->u, 'subbranch' => 'ZZZSUB'.$this->u, 'relevance_basis' => 'ZZZBASIS'.$this->u]);
        $this->node("{$c}.01.02S", $f1, 'Avión '.$this->u, tipo: 'servicio');
        $this->node("{$c}.01.03G", $f1, 'bomba '.$this->u, tipo: null);
        $this->node("{$c}.01.04G", $f1, $this->n('categoria inactiva'), active: false, tipo: 'bien');
        $this->node("{$c}.01.05G", $f1, 'Ñandú '.$this->u, tipo: 'bien');
        $this->node("{$c}.01.06S", $f1, 'nube '.$this->u, tipo: 'servicio');
        $this->node("{$c}.01.07G", $f1, 'zeta '.$this->u, tipo: 'bien');
        $f2 = $this->node("{$c}.02", $g1, 'familia dos '.$this->u);
        $this->node("{$c}.02.01G", $f2, $this->n('solo inactiva'), active: false, tipo: 'bien');
        $f3 = $this->node("{$c}.03", $g1, $this->n('familia inactiva'), active: false);
        $this->node("{$c}.03.01G", $f3, $this->n('bajo familia inactiva'), tipo: 'bien');

        $g2 = $this->node("{$c}9", null, $this->n('Grupo inactivo'), active: false);
        $f9 = $this->node("{$c}9.01", $g2, $this->n('familia bajo grupo inactivo'));
        $this->node("{$c}9.01.01G", $f9, $this->n('categoria bajo grupo inactivo'), tipo: 'bien');

        $g3 = $this->node("{$c}8", null, $this->n('Grupo vacio'));
        $this->node("{$c}8.01", $g3, $this->n('familia vacia del grupo vacio'));

        $this->malformed("{$c}.98.01G", $g1->id, TaxonomyCategory::LEVEL_CATEGORY, $this->n('categoria colgada del grupo'));
        $this->malformed("{$c}7.01", null, TaxonomyCategory::LEVEL_FAMILY, $this->n('familia sin padre'));
        $this->malformed("{$c}6", $g1->id, TaxonomyCategory::LEVEL_GROUP, $this->n('grupo con padre'));
    }

    private function findGroup(array $map, string $name): ?array
    {
        return collect($map['groups'])->firstWhere('name', $name);
    }

    private function affiliate(): User
    {
        return User::factory()->create();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate(config('filament-shield.super_admin.name'), 'web'));

        return $user;
    }

    // ---------------------------------------------------------------- acceso

    #[Test]
    public function an_affiliate_and_an_admin_can_open_the_map_and_a_guest_is_sent_to_login(): void
    {
        $this->actingAs($this->affiliate())->get(TaxonomyMapPage::getUrl())
            ->assertOk()->assertSee('Mapa de la taxonomía')->assertSee('desde aquí no se agregan categorías', false);

        $this->actingAs($this->superAdmin())->get(TaxonomyMapPage::getUrl())->assertOk();

        auth()->logout();
        $this->get(TaxonomyMapPage::getUrl())->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
    }

    #[Test]
    public function access_is_exactly_the_manuals_including_unverified_users(): void
    {
        $unverified = User::factory()->unverified()->create();

        $manual = $this->actingAs($unverified)->get(UserManual::getUrl());
        $map = $this->actingAs($unverified)->get(TaxonomyMapPage::getUrl());

        $this->assertSame($manual->getStatusCode(), $map->getStatusCode());
        $this->assertSame($manual->headers->get('Location'), $map->headers->get('Location'));
        $this->assertNotSame(200, $map->getStatusCode(), 'Un usuario sin correo verificado no debe ver el mapa.');

        $this->actingAs($this->affiliate());
        $this->assertSame(UserManual::canAccess(), TaxonomyMapPage::canAccess());
    }

    #[Test]
    public function the_map_has_no_sidebar_item_and_is_linked_from_the_manual_and_back(): void
    {
        $this->actingAs($this->affiliate());
        $this->assertFalse(TaxonomyMapPage::shouldRegisterNavigation());

        // Solo el MENÚ LATERAL: desde CPV-HOME-USEFUL-HELP la tarjeta de ayuda del Escritorio enlaza
        // el mapa a propósito, así que la página completa sí contiene su URL.
        $dashboard = $this->get(Filament::getPanel('admin')->getUrl())->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<nav[^>]*fi-sidebar-nav.*?<\/nav>/s', $dashboard, 'No se encontró el menú lateral.');
        preg_match('/<nav[^>]*fi-sidebar-nav.*?<\/nav>/s', $dashboard, $sidebar);
        $this->assertStringContainsString('manual-de-usuario', $sidebar[0], 'El menú lateral leído no es el del panel.');
        $this->assertStringNotContainsString('mapa-de-la-taxonomia', $sidebar[0], 'El mapa no debe tener ítem en el menú lateral.');

        $this->get(UserManual::getUrl())->assertOk()
            ->assertSee(TaxonomyMapPage::getUrl(), false)
            ->assertSee('Ver mapa de la taxonomía');

        $this->get(TaxonomyMapPage::getUrl())->assertOk()
            ->assertSee(UserManual::getUrl().'#taxonomia-cpv', false)
            ->assertSee('Volver al manual');
    }

    #[Test]
    public function the_categories_tab_links_to_the_map_in_a_new_tab_without_touching_the_company(): void
    {
        $user = User::factory()->create(['phone' => '0414-5550000']);
        // CPV-SEC-01-R3: the tab lives on the company edit page (company edit permission, re-checked per request).
        $user->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate('update_empresa', 'web'));
        $this->actingAs($user);
        $empresa = Empresa::create([
            'rif' => 'J'.random_int(10000000, 99999999).'-'.random_int(0, 9),
            'name' => 'zzz_test_empresa_'.uniqid('', true),
            'phone' => '0212-5551234',
            'website' => 'https://ejemplo.test',
        ]);
        $before = DB::connection('pgsql')->table('empresa_taxonomy_category')->where('empresa_id', $empresa->id)->count();

        Livewire::actingAs($user)->test(TaxonomyCategoriesRelationManager::class, ['ownerRecord' => $empresa, 'pageClass' => EditEmpresa::class])
            ->assertTableActionExists('verMapaTaxonomia')
            ->assertTableActionVisible('verMapaTaxonomia')
            ->assertTableActionHasLabel('verMapaTaxonomia', 'Ver mapa de la taxonomía')
            ->assertTableActionHasUrl('verMapaTaxonomia', TaxonomyMapPage::getUrl())
            ->assertTableActionShouldOpenUrlInNewTab('verMapaTaxonomia')
            // Las acciones que ya existían siguen ahí, en el mismo orden.
            ->assertTableActionExists('buscarYAgregar')
            ->assertTableActionExists('solicitarRevisionCategoria');

        $this->assertSame($before, DB::connection('pgsql')->table('empresa_taxonomy_category')->where('empresa_id', $empresa->id)->count());
    }

    // ---------------------------------------------------------------- árbol publicado

    #[Test]
    public function only_published_nodes_with_a_visible_well_formed_ancestor_chain_are_in_the_tree(): void
    {
        $this->seedTree();
        $map = app(TaxonomyMapBuilder::class)->build();
        $flat = json_encode($map, JSON_UNESCAPED_UNICODE);

        $g1 = $this->findGroup($map, $this->n('Grupo Uno'));
        $this->assertNotNull($g1);
        $this->assertSame(['familia dos '.$this->u, 'familia uno '.$this->u], array_column($g1['families'], 'name'));

        foreach (['categoria inactiva', 'solo inactiva', 'familia inactiva', 'bajo familia inactiva', 'Grupo inactivo', 'familia bajo grupo inactivo',
            'categoria bajo grupo inactivo', 'categoria colgada del grupo', 'familia sin padre', 'grupo con padre'] as $excluded) {
            $this->assertStringNotContainsString($this->n($excluded), $flat, "«{$excluded}» no debe estar en el mapa.");
        }

        // Nada malformado se promueve a raíz.
        $this->assertNull($this->findGroup($map, $this->n('familia sin padre')));
        $this->assertNull($this->findGroup($map, $this->n('grupo con padre')));
    }

    #[Test]
    public function counts_and_goods_services_split_come_from_the_visible_tree_and_unknown_types_are_kept_apart(): void
    {
        $this->seedTree();
        $map = app(TaxonomyMapBuilder::class)->build();
        $g1 = $this->findGroup($map, $this->n('Grupo Uno'));
        $f1 = collect($g1['families'])->firstWhere('name', 'familia uno '.$this->u);

        $this->assertSame(['categories' => 6, 'bien' => 3, 'servicio' => 2, 'otro' => 1], $f1['counts']);
        $this->assertSame(['families' => 2, 'categories' => 6, 'bien' => 3, 'servicio' => 2, 'otro' => 1], $g1['counts']);
        $this->assertSame('otro', collect($f1['categories'])->firstWhere('name', 'bomba '.$this->u)['tipo']);

        // Los totales son la suma exacta del árbol visible.
        $sum = ['groups' => 0, 'families' => 0, 'categories' => 0, 'bien' => 0, 'servicio' => 0, 'otro' => 0, 'empty_groups' => 0, 'empty_families' => 0];
        foreach ($map['groups'] as $group) {
            $sum['groups']++;
            $sum['empty_groups'] += $group['counts']['categories'] === 0 ? 1 : 0;
            foreach ($group['families'] as $family) {
                $sum['families']++;
                $sum['empty_families'] += $family['counts']['categories'] === 0 ? 1 : 0;
                foreach ($family['categories'] as $category) {
                    $sum['categories']++;
                    $sum[$category['tipo']]++;
                }
            }
        }
        $this->assertEquals($sum, $map['totals']);
    }

    #[Test]
    public function published_empty_families_and_groups_stay_visible_with_the_empty_label(): void
    {
        $this->seedTree();
        $map = app(TaxonomyMapBuilder::class)->build();

        $f2 = collect($this->findGroup($map, $this->n('Grupo Uno'))['families'])->firstWhere('name', 'familia dos '.$this->u);
        $this->assertSame(0, $f2['counts']['categories']);
        $g3 = $this->findGroup($map, $this->n('Grupo vacio'));
        $this->assertSame(['families' => 1, 'categories' => 0, 'bien' => 0, 'servicio' => 0, 'otro' => 0], $g3['counts']);

        $html = $this->actingAs($this->affiliate())->get(TaxonomyMapPage::getUrl())->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/familia dos '.$this->u.'<\/span>\s*<span class="cpv-map__empty">— sin categorías todavía/u', $html);
        $this->assertMatchesRegularExpression('/'.preg_quote($this->n('Grupo vacio'), '/').'<\/span>.*?sin categorías todavía/su', $html);
        // Una Familia vacía no se presenta como algo que se pueda abrir ni elegir.
        $this->assertDoesNotMatchRegularExpression('/<summary>\s*<span class="cpv-map__n">familia dos '.$this->u.'/u', $html);
        $this->assertStringContainsString('<div class="cpv-map__fam-empty"><span class="cpv-map__n">familia dos '.$this->u.'</span>', $html);
    }

    #[Test]
    public function every_level_is_in_spanish_alphabetical_order_ignoring_case_and_accents_and_ties_are_deterministic(): void
    {
        $this->seedTree();
        $f1 = $this->node("{$this->groupCode}.04", TaxonomyCategory::where('code', $this->groupCode)->first(), 'familia cuatro '.$this->u);
        $this->node("{$this->groupCode}.04.01G", $f1, 'duplicado '.$this->u, tipo: 'bien');
        $this->node("{$this->groupCode}.04.02G", $f1, 'Duplicado '.$this->u, tipo: 'bien');
        $this->node("{$this->groupCode}.04.03G", $f1, 'duplicado '.$this->u, tipo: 'servicio');

        $map = app(TaxonomyMapBuilder::class)->build();
        $g1 = $this->findGroup($map, $this->n('Grupo Uno'));
        $uno = collect($g1['families'])->firstWhere('name', 'familia uno '.$this->u);

        $this->assertSame(
            ['árbol', 'Avión', 'bomba', 'nube', 'Ñandú', 'zeta'],
            array_map(fn ($c) => trim(str_replace($this->u, '', $c['name'])), $uno['categories'])
        );
        $this->assertSame(['familia cuatro', 'familia dos', 'familia uno'], array_map(fn ($f) => trim(str_replace($this->u, '', $f['name'])), $g1['families']));

        $cuatro = collect($g1['families'])->firstWhere('name', 'familia cuatro '.$this->u);
        // Empate sin mayúsculas/tildes -> desempata el nombre exacto («D» antes que «d») -> luego el id.
        $this->assertSame([['Duplicado', 'bien'], ['duplicado', 'bien'], ['duplicado', 'servicio']],
            array_map(fn ($c) => [trim(str_replace($this->u, '', $c['name'])), $c['tipo']], $cuatro['categories']));
        $this->assertSame($map, app(TaxonomyMapBuilder::class)->build(), 'Dos construcciones seguidas deben dar el mismo orden.');
    }

    // ---------------------------------------------------------------- lo que NO sale

    #[Test]
    public function the_page_exposes_no_codes_internal_fields_inactive_nodes_or_livewire_state(): void
    {
        $this->seedTree();
        $html = $this->actingAs($this->affiliate())->get(TaxonomyMapPage::getUrl())->assertOk()->getContent();

        $this->assertStringContainsString($this->n('Grupo Uno'), $html);
        foreach ([$this->groupCode, 'ZZZBRANCH'.$this->u, 'ZZZSUB'.$this->u, 'ZZZBASIS'.$this->u, $this->n('categoria inactiva'),
            $this->n('bajo familia inactiva'), $this->n('categoria colgada del grupo'), 'chamber_relevance', 'is_active', 'parent_id'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html, "La página expone «{$hidden}».");
        }

        preg_match_all('/wire:snapshot="([^"]*)"/', $html, $snapshots);
        $this->assertNotEmpty($snapshots[1]);
        foreach ($snapshots[1] as $snapshot) {
            $state = html_entity_decode($snapshot, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertStringNotContainsString($this->n('Grupo Uno'), $state, 'El árbol no debe viajar en el estado de Livewire.');
            $this->assertStringNotContainsString($this->groupCode, $state);
        }
    }

    #[Test]
    public function taxonomy_names_are_escaped(): void
    {
        $g = $this->node($this->groupCode, null, $this->n('Grupo escape'));
        $f = $this->node("{$this->groupCode}.01", $g, 'familia escape '.$this->u);
        $this->node("{$this->groupCode}.01.01G", $f, '<img src=x onerror=alert(1)> & "comillas" '.$this->u, tipo: 'bien');

        $html = $this->actingAs($this->affiliate())->get(TaxonomyMapPage::getUrl())->assertOk()->getContent();

        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt; &amp; &quot;comillas&quot; '.$this->u, $html);
    }

    // ---------------------------------------------------------------- sin escrituras

    #[Test]
    public function rendering_the_map_issues_no_write_queries_and_writes_no_taxonomy_cache(): void
    {
        $this->seedTree();
        $user = $this->affiliate();
        $this->actingAs($user);

        $tables = ['taxonomy_categories', 'taxonomy_category_translations', 'empresa_taxonomy_category'];
        $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::connection('pgsql')->table($t)->count()]);

        $statements = [];
        foreach (array_keys(config('database.connections')) as $connection) {
            try {
                DB::connection($connection)->listen(function ($query) use (&$statements) {
                    $statements[] = $query->sql;
                });
            } catch (\Throwable) {
                // Conexión configurada pero no disponible en este entorno: no puede recibir escrituras.
            }
        }
        $cacheWrites = [];
        Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$cacheWrites) {
            $cacheWrites[] = $event->key;
        });

        $this->get(TaxonomyMapPage::getUrl())->assertOk();

        $this->assertNotEmpty($statements, 'Se esperaba al menos la consulta de lectura del árbol.');
        $writes = array_values(array_filter($statements, fn ($sql) => preg_match('/^\s*(insert|update|delete|truncate|alter|create|drop|merge|upsert|grant|revoke|copy|lock|comment|vacuum)\b/i', $sql)));
        $this->assertSame([], $writes, 'Abrir el mapa no debe escribir en la base.');
        $this->assertSame([], array_values(array_filter($cacheWrites, fn ($k) => stripos($k, 'taxonom') !== false)), 'La v1 no cachea el árbol.');
        $this->assertEquals($before, collect($tables)->mapWithKeys(fn ($t) => [$t => DB::connection('pgsql')->table($t)->count()]));
    }
}
