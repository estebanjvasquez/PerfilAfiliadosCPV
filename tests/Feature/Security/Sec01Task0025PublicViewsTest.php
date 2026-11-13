<?php

namespace Tests\Feature\Security;

use App\Models\Contact;
use App\Models\Empresa;
use App\Models\GenCatalog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TASK-0025 / F-SEC01-T24-02 (Issue #2 `6850596236`): the 13 report views must not hand the Supabase Data API roles
 * (`anon`, `authenticated`) rows that RLS denies them on the base tables.
 *
 * The isolated database is made to look like the shared Supabase database, inside the test transaction (everything,
 * including the roles, is rolled back):
 * - the 13 views, created from their live definitions (tests/Fixtures/Sec01PublicReportViews.php), owned by the
 *   connection user;
 * - the API roles `anon` and `authenticated` (no BYPASSRLS) and `service_role` (BYPASSRLS), with Supabase's default
 *   privileges: ALL on every `public` relation, and the same default for relations created later;
 * - RLS enabled with no policy on every base table the views read;
 * - an application role (`zzz_t25_app`: not superuser, not owner, BYPASSRLS, SELECT on the tables), which is how
 *   Laravel reads in Supabase as `postgres`;
 * - one synthetic company with a synthetic contact (marker ZZZT25).
 * Then the hardening migration is applied when it exists. Before the fix it does not, so the exposure shows.
 */
class Sec01Task0025PublicViewsTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private const MIGRATION = '2026_11_13_000001_harden_public_report_views';

    private const MARKER = 'ZZZT25';

    /** @var array<string, string> view name => definition */
    private array $views;

    protected function setUp(): void
    {
        parent::setUp();
        $db = DB::connection('pgsql');
        $this->views = require base_path('tests/Fixtures/Sec01PublicReportViews.php');

        $db->statement('CREATE EXTENSION IF NOT EXISTS unaccent');
        foreach (['anon' => 'NOLOGIN', 'authenticated' => 'NOLOGIN', 'service_role' => 'NOLOGIN BYPASSRLS', 'zzz_t25_app' => 'NOLOGIN BYPASSRLS'] as $role => $options) {
            if ($db->selectOne('select 1 as x from pg_roles where rolname = ?', [$role]) === null) {
                $db->statement("CREATE ROLE {$role} {$options}");
            }
        }
        $db->statement('GRANT USAGE ON SCHEMA public TO anon, authenticated, service_role, zzz_t25_app');
        $db->statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO anon, authenticated, service_role');

        foreach ($this->views as $name => $definition) {
            $db->statement(sprintf('DROP VIEW IF EXISTS public.%s', $this->ident($name)));
            $db->statement(sprintf('CREATE VIEW public.%s AS %s', $this->ident($name), $definition));
        }
        $db->statement('GRANT ALL ON ALL TABLES IN SCHEMA public TO anon, authenticated, service_role');
        $db->statement('GRANT SELECT ON ALL TABLES IN SCHEMA public TO zzz_t25_app');
        foreach ($this->baseTables() as $table) {
            $db->statement(sprintf('ALTER TABLE public.%s ENABLE ROW LEVEL SECURITY', $this->ident($table)));
        }

        $this->syntheticCompany();
    }

    private function ident(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    /** @return list<string> */
    private function baseTables(): array
    {
        return array_map(fn ($r) => $r->t, DB::connection('pgsql')->select(
            "select distinct table_name t from information_schema.view_table_usage where view_schema = 'public' and table_schema = 'public' order by 1"));
    }

    private function syntheticCompany(): void
    {
        $owner = User::factory()->create();
        $sector = DB::table('sectors')->insertGetId(['name' => self::MARKER.'SEC', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner); // same as AccessMatrixTest::company(): the model's create path reads the session user
        $empresa = Empresa::create(['rif' => 'J'.random_int(100000000, 999999999), 'name' => self::MARKER.'EMPRESA',
            'phone' => '+58-212-5550000', 'sector_principal_id' => $sector, 'status_id' => 1, 'billing_id' => 1, 'employees_id' => 1,
            'property_id' => 1, 'origin_id' => 0]);
        auth()->logout();
        DB::table('empresa_user')->insertOrIgnore(['empresa_id' => $empresa->id, 'user_id' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $contact = Contact::create(['name' => self::MARKER.'CONTACTO', 'email' => 'zzzt25@zzz.test', 'phone' => '+58-000-0000000']);
        DB::table('contact_empresa')->insert(['contact_id' => $contact->id, 'empresa_id' => $empresa->id, 'is_principal' => false,
            'created_at' => now(), 'updated_at' => now()]);
    }

    /** Applies (or reverts) the hardening migration if it exists at this SHA. */
    private function migration(string $direction = 'up'): bool
    {
        $file = database_path('migrations/'.self::MIGRATION.'.php');
        if (! is_file($file)) {
            return false;
        }
        (require $file)->{$direction}();

        return true;
    }

    /**
     * Runs a query as `$role` inside a savepoint. Returns ['rows' => list] or ['denied' => SQLSTATE]; a denial rolls
     * back to the savepoint (which also undoes SET LOCAL ROLE), so the outer test transaction stays usable.
     */
    private function asRole(string $role, string $sql): array
    {
        $db = DB::connection('pgsql');
        $db->beginTransaction();
        try {
            $db->statement("SET LOCAL ROLE {$role}");
            $rows = $db->select($sql);
            $db->statement('RESET ROLE');
            $db->commit();

            return ['rows' => $rows];
        } catch (QueryException $e) {
            $db->rollBack();

            return ['denied' => (string) ($e->errorInfo[0] ?? $e->getCode())];
        }
    }

    /** What `$role` reads from every view: 'denied:SQLSTATE' or the row count. */
    private function readsAs(string $role): array
    {
        $out = [];
        foreach (array_keys($this->views) as $view) {
            $r = $this->asRole($role, sprintf('select count(*) as n from public.%s', $this->ident($view)));
            $out[$view] = isset($r['denied']) ? 'denied:'.$r['denied'] : (int) $r['rows'][0]->n;
        }

        return $out;
    }

    /** md5 of each view's full output, read as `$role` (or as the connection user when null). */
    private function fingerprints(?string $role = null): array
    {
        $out = [];
        foreach (array_keys($this->views) as $view) {
            $sql = sprintf("select count(*) as n, md5(coalesce(string_agg(x::text, '|' order by x::text collate \"C\"), '')) as h from public.%s x", $this->ident($view));
            $r = $role === null ? ['rows' => DB::connection('pgsql')->select($sql)] : $this->asRole($role, $sql);
            $out[$view] = isset($r['denied']) ? 'denied:'.$r['denied'] : $r['rows'][0]->n.':'.$r['rows'][0]->h;
        }

        return $out;
    }

    /** Public views an API role can SELECT while running with the owner's rights: the invariant this task enforces. */
    private function ownerRightsViewsReadableByApiRoles(): array
    {
        return array_map(fn ($r) => $r->v, DB::connection('pgsql')->select("
            select c.relname v from pg_class c
            where c.relnamespace = 'public'::regnamespace and c.relkind = 'v'
              and not coalesce((select option_value::boolean from pg_options_to_table(c.reloptions) where option_name = 'security_invoker'), false)
              and (has_table_privilege('anon', c.oid, 'SELECT') or has_table_privilege('authenticated', c.oid, 'SELECT'))
            order by 1"));
    }

    // ------------------------------------------------------------------ fails before the fix

    #[Test]
    public function api_roles_read_nothing_through_the_report_views(): void
    {
        $this->migration('up');

        // Baseline that already holds: RLS with no policy denies the API roles the base tables themselves.
        $this->assertSame(0, (int) $this->asRole('anon', 'select count(*) as n from public.contacts')['rows'][0]->n, 'anon reads no contacts directly');
        $this->assertSame(0, (int) $this->asRole('anon', 'select count(*) as n from public.empresas')['rows'][0]->n, 'anon reads no companies directly');

        $leaks = [];
        foreach (['anon', 'authenticated'] as $role) {
            foreach ($this->readsAs($role) as $view => $result) {
                if (is_int($result) && $result > 0) {
                    $leaks[] = "{$role} reads {$result} row(s) from {$view}";
                }
            }
        }
        $this->assertSame([], $leaks, "the views hand the API roles rows RLS denies them:\n".implode("\n", $leaks));

        $contactLeak = $this->asRole('anon', "select count(*) as n from public.\"catalogoView\" where \"CONTACTOS\" like '%".self::MARKER."%'");
        $this->assertTrue(isset($contactLeak['denied']) || (int) $contactLeak['rows'][0]->n === 0, 'no contact data reaches anon');
    }

    #[Test]
    public function no_public_view_runs_with_owner_rights_for_an_api_role(): void
    {
        $this->migration('up');

        $this->assertSame([], $this->ownerRightsViewsReadableByApiRoles());
    }

    #[Test]
    public function the_hardening_is_idempotent_and_reversible(): void
    {
        $this->assertTrue($this->migration('up'), 'the hardening migration exists');
        $this->migration('up'); // idempotent
        $this->assertSame([], $this->ownerRightsViewsReadableByApiRoles());
        foreach (array_keys($this->views) as $view) {
            $this->assertSame([true, false, false, true], $this->state($view), "{$view} hardened");
        }

        $this->migration('down');
        foreach (array_keys($this->views) as $view) {
            $this->assertSame([false, true, true, true], $this->state($view), "{$view} back to the observed live state");
        }
        $this->assertCount(13, $this->ownerRightsViewsReadableByApiRoles(), 'down() restores the exposure exactly');

        $this->migration('up');
        $this->assertSame([], $this->ownerRightsViewsReadableByApiRoles());
    }

    /** [security_invoker, anon SELECT, authenticated SELECT, service_role SELECT] */
    private function state(string $view): array
    {
        $r = DB::connection('pgsql')->selectOne("
            select coalesce((select option_value::boolean from pg_options_to_table(c.reloptions) where option_name = 'security_invoker'), false) inv,
                   has_table_privilege('anon', c.oid, 'SELECT') a, has_table_privilege('authenticated', c.oid, 'SELECT') u,
                   has_table_privilege('service_role', c.oid, 'SELECT') s
            from pg_class c where c.relnamespace = 'public'::regnamespace and c.relname = ?", [$view]);

        return [(bool) $r->inv, (bool) $r->a, (bool) $r->u, (bool) $r->s];
    }

    // ------------------------------------------------------------------ legitimate readers keep working

    #[Test]
    public function the_application_and_the_service_role_read_exactly_the_same_rows(): void
    {
        $owner = $this->fingerprints();
        $app = $this->fingerprints('zzz_t25_app');
        $service = $this->fingerprints('service_role');
        $this->assertSame($owner, $app, 'before: the application role reads what the owner reads');

        $this->assertTrue($this->migration('up'), 'the hardening migration exists');

        $this->assertSame($owner, $this->fingerprints(), 'owner: identical output after hardening');
        $this->assertSame($app, $this->fingerprints('zzz_t25_app'), 'application role (BYPASSRLS, not owner): identical output');
        $this->assertSame($service, $this->fingerprints('service_role'), 'service_role (BYPASSRLS): identical output');

        $this->assertSame(1, GenCatalog::query()->where('name', 'like', self::MARKER.'%')->count(), 'the Laravel model on catalogoView still sees the company');
        $row = DB::connection('pgsql')->selectOne('select "CONTACTOS" c from public."catalogoView" where name like ?', [self::MARKER.'%']);
        $this->assertStringContainsString(self::MARKER.'CONTACTO', (string) $row->c, 'and its contact');
    }

    // ------------------------------------------------------------------ documented traps for future view migrations

    #[Test]
    public function a_recreated_view_loses_the_hardening_and_the_invariant_notices(): void
    {
        $this->assertTrue($this->migration('up'), 'the hardening migration exists');

        // CREATE OR REPLACE without the option resets security_invoker, but the REVOKE survives: still not readable.
        DB::connection('pgsql')->statement(sprintf('CREATE OR REPLACE VIEW public."FinanceView" AS %s', $this->views['FinanceView']));
        $this->assertSame([false, false, false, true], $this->state('FinanceView'), 'OR REPLACE: option reset, grants kept');
        $this->assertSame([], $this->ownerRightsViewsReadableByApiRoles(), 'the revoke still protects it');

        // DROP + CREATE (the pattern of earlier view migrations) loses both: Supabase default privileges grant it again.
        DB::connection('pgsql')->statement('DROP VIEW public."InventoryView"');
        DB::connection('pgsql')->statement(sprintf('CREATE VIEW public."InventoryView" AS %s', $this->views['InventoryView']));
        $this->assertSame(['InventoryView'], $this->ownerRightsViewsReadableByApiRoles(), 'the invariant catches a recreated view');

        // Creating it WITH (security_invoker = true) keeps it safe even with the default grants.
        DB::connection('pgsql')->statement('DROP VIEW public."InventoryView"');
        DB::connection('pgsql')->statement(sprintf('CREATE VIEW public."InventoryView" WITH (security_invoker = true) AS %s', $this->views['InventoryView']));
        $this->assertSame([], $this->ownerRightsViewsReadableByApiRoles());
        $this->assertSame(0, $this->readsAs('anon')['InventoryView'], 'invoker rights: RLS gives anon nothing');
    }
}
