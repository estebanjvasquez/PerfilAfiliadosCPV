<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\TaxonomyCandidateTermResource;
use App\Filament\Resources\UserResource;
use App\Models\User;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use App\Filament\Resources\Shield\RoleResource;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * CPV-SEC-01-R3 (Issue #2 `6316775771`): panel global search on three previously untested resources, chosen from the
 * 13-resource inventory by sensitive-data / privilege impact:
 * 1. CustomerResource — names and e-mail addresses of people (personal data). A global admin catalog, not
 *    company-owned: visibility is by permission, not by company.
 * 2. TaxonomyCandidateTermResource — unreviewed crawler candidates and reviewer notes (internal governance work that
 *    affiliates must not see before publication). Global, not company-owned.
 * 3. RoleResource (Shield) — role names are privilege data. The inventory counted it as searchable from its title
 *    attribute, but Shield's config disables global search for it; the expectation is that no actor ever finds a role.
 * UserResource is not globally searchable at all (no title attribute); that is asserted too, but not counted as a new
 * search resource. Empresa search was covered in R1 and is not counted again.
 *
 * What is checked is the search OUTPUT itself — categories, titles, details, counts and links — not only the page
 * reached after a click. Expectations are literals (independent of the gate): who may see each catalog.
 * Synthetic rows only; the query string is a synthetic marker, never real profile data.
 */
class PriorityGlobalSearchTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private const MARK = 'ZZZRTRESBUSCA';

    /** @var array<string, User> */
    private array $actors = [];

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $staff = [];
        foreach (array_keys(FilamentShield::getResources() ?? []) as $identifier) {
            $staff[] = "view_any_{$identifier}";
            $staff[] = "view_{$identifier}";
        }
        $this->actors = [
            'affiliate' => $this->user(['view_any_empresa', 'view_empresa', 'update_empresa', 'view_any_service', 'view_any_sector', 'view_dashboard']),
            'staff_viewer' => $this->user(array_values(array_unique($staff))),
            'customer_viewer' => $this->user(['view_any_customer', 'view_customer']),
            'no_permission' => $this->user([]),
        ];

        DB::table('customers')->insert(['name' => self::MARK.' CLIENTE', 'email' => 'zzzrtres.cliente@zzz.test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('taxonomy_sources')->insert(['source_id' => 'zzz-rtres', 'name' => 'ZZZ fuente', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('taxonomy_candidate_terms')->insert([
            'source_id' => 'zzz-rtres', 'term' => self::MARK.' TERMINO', 'source_url' => 'https://zzz.test/x', 'content_hash' => str_repeat('a', 64),
            'review_notes' => 'ZZZ nota interna', 'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Role::findOrCreate(self::MARK.' ROL', 'web');
    }

    private function user(array $permissions): User
    {
        $user = User::factory()->create();
        if ($permissions) {
            $user->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p, 'web'), $permissions));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /** @return array<string, list<array{title: string, url: ?string, details: array}>> category label => results */
    private function search(User $actor, string $query): array
    {
        $this->actingAs($actor);
        $results = Filament::getGlobalSearchProvider()->getResults($query);
        $out = [];
        foreach ($results?->getCategories() ?? [] as $category => $items) {
            foreach ($items as $item) {
                $out[(string) $category][] = ['title' => strip_tags((string) $item->title), 'url' => $item->url, 'details' => $item->details];
            }
        }

        return $out;
    }

    private function flat(array $categories): string
    {
        return json_encode($categories, JSON_UNESCAPED_UNICODE);
    }

    #[Test]
    public function customers_are_found_only_by_customer_readers_and_never_expose_the_e_mail(): void
    {
        $label = CustomerResource::getPluralModelLabel();
        $expected = ['affiliate' => false, 'staff_viewer' => true, 'customer_viewer' => true, 'no_permission' => false];

        foreach ($expected as $actor => $sees) {
            $out = $this->search($this->actors[$actor], self::MARK);
            if ($sees) {
                $this->assertArrayHasKey($label, $out, "{$actor}: allowed to find customers");
                $this->assertCount(1, $out[$label], "{$actor}: exactly the matching customer");
                $this->assertStringContainsString(self::MARK.' CLIENTE', $out[$label][0]['title']);
                // Link: either none, or a page this actor can open.
                if ($url = $out[$label][0]['url']) {
                    $this->get($url)->assertOk();
                }
            } else {
                $this->assertArrayNotHasKey($label, $out, "{$actor}: no customer category");
            }
            $this->assertStringNotContainsString('zzzrtres.cliente@zzz.test', $this->flat($out), "{$actor}: e-mail never in titles, details or links");
            // The e-mail is not a search key either: a lookup by address finds no person.
            $this->assertArrayNotHasKey($label, $this->search($this->actors[$actor], 'zzzrtres.cliente@zzz.test'), "{$actor}: no lookup by e-mail");
        }
    }

    #[Test]
    public function unreviewed_candidate_terms_are_found_only_by_taxonomy_readers(): void
    {
        $label = TaxonomyCandidateTermResource::getPluralModelLabel();
        $expected = ['affiliate' => false, 'staff_viewer' => true, 'customer_viewer' => false, 'no_permission' => false];

        foreach ($expected as $actor => $sees) {
            $out = $this->search($this->actors[$actor], self::MARK);
            if ($sees) {
                $this->assertArrayHasKey($label, $out, "{$actor}: allowed to find candidate terms");
                $this->assertCount(1, $out[$label]);
                if ($url = $out[$label][0]['url']) {
                    $this->get($url)->assertOk();
                }
            } else {
                $this->assertArrayNotHasKey($label, $out, "{$actor}: unreviewed candidates are not discoverable");
                $this->assertStringNotContainsString(self::MARK.' TERMINO', $this->flat($out));
            }
            $this->assertStringNotContainsString('ZZZ nota interna', $this->flat($out), "{$actor}: reviewer notes never in the search output");
        }
    }

    #[Test]
    public function roles_and_users_are_never_discoverable_through_global_search(): void
    {
        // Even for a reader of roles, the resource refuses global search (Shield configuration).
        $this->actingAs($this->actors['staff_viewer']);
        $this->assertFalse(RoleResource::canGloballySearch(), 'role search disabled by configuration, even for a role reader');
        $this->assertSame([], UserResource::getGloballySearchableAttributes(), 'the user resource is not searchable');

        foreach ($this->actors as $actor => $user) {
            $out = $this->search($user, self::MARK);
            $this->assertArrayNotHasKey(RoleResource::getPluralModelLabel(), $out, "{$actor}: no role category");
            $this->assertStringNotContainsString(self::MARK.' ROL', $this->flat($out), "{$actor}: no role name in the output");
            $this->assertArrayNotHasKey(UserResource::getPluralModelLabel(), $out, "{$actor}: no user category");
        }
    }
}
