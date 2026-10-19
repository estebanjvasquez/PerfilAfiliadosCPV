<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * CPV-EXPORT-ACCESS-REVIEW (Issue #2 `6262799754`): CARACTERIZACIÓN del comportamiento ACTUAL de
 * `GET pdf/{empresa}` y `GET xls/{empresa}` (routes/web.php). Revisión, no reparación: este test afirma lo
 * que el código hace hoy. Cuando se autorice la reparación, estas afirmaciones se invierten (ver
 * audit/export_access_review_2026-10-19.md §6).
 *
 * Datos SINTÉTICOS con marcas distintas por empresa, en el PostgreSQL desechable del entorno aislado.
 *
 * Dobles declarados:
 * - **Vistas de reporte:** este branch no trae las vistas PostgreSQL (`capacityView`, `catalogoView`, …) que
 *   staging sí tiene. Se crean DENTRO de la transacción del test desde tests/Fixtures/pgsql_report_views/,
 *   copiadas de `feature/supplhi-postgres-buscador` @ `5df23fa`. El test comprueba que el md5 de cada
 *   `pg_get_viewdef` es el mismo que tienen esas vistas en staging (medido de solo lectura, sin datos).
 * - **`$_SERVER['REQUEST_URI']`:** JoinViewExport lee la variable GLOBAL, que php-fpm llena con la ruta pedida
 *   y el cliente de pruebas de Laravel no. El test la fija con la misma ruta que pide.
 * - **Espía de vistas:** un composer de la vista `pdf` registra qué empresa se renderizó; DB::listen registra
 *   qué consultas a las vistas de reporte corrieron y con qué id.
 */
class ExportAccessCharacterizationTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    /** md5(pg_get_viewdef) en staging, 2026-10-19, consulta de solo lectura a pg_class (sin filas de datos). */
    private const STAGING_VIEW_HASHES = [
        'ClientsView' => '653f93e344c707f25569151e1bf2ffc9',
        'ExperienceView' => 'ce84ec88412a0dc2ecc0deb8a201d540',
        'FacilityView' => 'cdb7483590acdf581ed3092964a65c21',
        'FinanceView' => 'c359fd16a52c65da847a2eb825eb3d62',
        'InventoryView' => 'a110d5f1ecd7621d5f9544c4aed4b4a5',
        'MachineryView' => '1e964db6da21a731ae124dcbb0f78efe',
        'ManagementDetView' => '603c4a19af8b4ccaf0808e1ba82f08c4',
        'ManagementView' => '5342807d132c05f83b38cb53d829d897',
        'PresenceView' => 'cc22c1b3bb17081a84f034464f70dfe6',
        'ResourceView' => 'e293815006b63b0f850ab5cdc9af8a88',
        'SustainabilityView' => 'adccaf9be181b916c67f5709f130cafd',
        'capacityView' => 'b6d5c2ee71616e92b197b31bea259bf3',
        'catalogoView' => 'fb67217d21bc7dd9673f5771d457458d',
    ];

    private const VIEW_PATTERN = '/"?(capacityView|catalogoView|ClientsView|FinanceView|ManagementDetView|ExperienceView|PresenceView|SustainabilityView|ResourceView|MachineryView|FacilityView|InventoryView|ManagementView)"?/';

    private ?int $renderedPdfFor = null;

    /** @var list<array{sql: string, bindings: array}> */
    private array $viewQueries = [];

    protected function setUp(): void
    {
        parent::setUp();

        $db = DB::connection('pgsql');
        foreach (glob(base_path('tests/Fixtures/pgsql_report_views/*.php')) as $file) {
            $exists = $db->selectOne("select 1 as x from pg_class c join pg_namespace n on n.oid=c.relnamespace where n.nspname='public' and c.relkind='v' and c.relname = ?", [$this->viewNameIn($file)]);
            if (! $exists) {
                (require $file)->up();
            }
        }

        View::composer('pdf', function ($view) {
            $this->renderedPdfFor = $view->getData()['record']->id ?? null;
        });
        DB::listen(function ($query) {
            if (preg_match(self::VIEW_PATTERN, $query->sql)) {
                $this->viewQueries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
            }
        });
    }

    private function viewNameIn(string $file): string
    {
        preg_match('/CREATE VIEW\s+"?([A-Za-z]+)"?/', file_get_contents($file), $m);

        return $m[1];
    }

    private function user(bool $verified = true, array $permissions = [], ?string $role = null): User
    {
        $user = $verified ? User::factory()->create() : User::factory()->unverified()->create();
        if ($permissions) {
            $user->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p, 'web'), $permissions));
        }
        if ($role) {
            $r = Role::findOrCreate($role, 'web');
            $r->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p, 'web'), $permissions ?: ['view_any_empresa', 'view_empresa']));
            $user->assignRole($r);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function company(string $marker, ?User $owner): Empresa
    {
        if ($owner) {
            $this->actingAs($owner);
        }
        $empresa = Empresa::create(['rif' => 'J'.random_int(100000000, 999999999), 'name' => $marker, 'phone' => '+58-212-5550000', 'website' => 'https://'.strtolower(str_replace(' ', '-', $marker)).'.test']);
        if (! $owner) {
            // Empresa sin usuarios: Empresa::created la vincula a la sesión; se desvincula para el caso «sin asociar».
            $empresa->users()->detach();
        }

        return $empresa;
    }

    /** @return array{status:int, owner:?string, queried:list<int>, cache:?string, disposition:?string, type:?string} */
    private function fetch(?User $as, string $kind, string $id, array $companies): array
    {
        $this->renderedPdfFor = null;
        $this->viewQueries = [];
        auth()->logout();
        $this->flushSession();
        $path = "/{$kind}/{$id}";
        $_SERVER['REQUEST_URI'] = $path;
        $response = $as ? $this->actingAs($as)->get($path) : $this->get($path);

        $owner = null;
        if ($response->getStatusCode() === 200) {
            if ($kind === 'pdf') {
                $this->assertStringStartsWith('%PDF', $response->getContent(), 'A real PDF is streamed.');
                $owner = $this->markerOf($this->renderedPdfFor, $companies);
            } else {
                $file = $response->baseResponse->getFile()->getPathname();
                $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file)->getActiveSheet()->toArray();
                $flat = implode(' | ', array_map(fn ($row) => implode(' ', array_map('strval', $row)), $sheet));
                $found = array_values(array_filter(array_keys($companies), fn ($m) => str_contains(mb_strtoupper($flat), mb_strtoupper($m))));
                $owner = $found ? implode('+', $found) : 'no data rows';
            }
        }

        $queried = [];
        foreach ($this->viewQueries as $q) {
            foreach ($q['bindings'] as $b) {
                if (is_numeric($b) && in_array((int) $b, array_map(fn ($e) => $e->id, $companies), true)) {
                    $queried[] = (int) $b;
                }
            }
        }

        return [
            'status' => $response->getStatusCode(),
            'owner' => $owner,
            'queried' => array_values(array_unique($queried)),
            'cache' => $response->headers->get('Cache-Control'),
            'disposition' => $response->headers->get('Content-Disposition'),
            'type' => $response->headers->get('Content-Type'),
        ];
    }

    private function markerOf(?int $id, array $companies): ?string
    {
        foreach ($companies as $marker => $empresa) {
            if ($empresa->id === $id) {
                return $marker;
            }
        }

        return $id === null ? null : "other id {$id}";
    }

    private function report(string $actor, string $request, array $r, array $companies): void
    {
        $names = array_map(fn ($id) => $this->markerOf($id, $companies), $r['queried']);
        fwrite(STDERR, sprintf("EXPORT | %-28s | %-22s | %d | owner=%s | queried=%s | cache=%s | disposition=%s\n",
            $actor, $request, $r['status'], $r['owner'] ?? '-', $names ? implode(',', $names) : '-', $r['cache'] ?? '-', $r['disposition'] ?? '-'));
    }

    #[Test]
    public function report_views_match_the_ones_installed_on_staging(): void
    {
        $rows = DB::connection('pgsql')->select("select c.relname, md5(pg_get_viewdef(c.oid)) as h from pg_class c join pg_namespace n on n.oid=c.relnamespace where n.nspname='public' and c.relkind='v' and c.relname ilike '%View'");
        $hashes = collect($rows)->mapWithKeys(fn ($r) => [$r->relname => $r->h])->all();
        foreach (self::STAGING_VIEW_HASHES as $view => $hash) {
            $this->assertSame($hash, $hashes[$view] ?? null, "{$view}: same definition as on staging");
        }
    }

    /**
     * Para la propuesta de reparación: el alias genérico `auth` (App\Http\Middleware\Authenticate) manda a un
     * invitado a route('login'), que esta app no define (el login es el del panel). Usarlo tal cual daría 500.
     */
    #[Test]
    public function the_generic_auth_alias_would_redirect_guests_to_a_missing_route(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('login'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('filament.admin.auth.login'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('filament.admin.auth.email-verification.prompt'));
    }

    #[Test]
    public function current_behaviour_of_both_exports_for_every_actor(): void
    {
        $perms = ['view_any_empresa', 'view_empresa', 'update_empresa'];
        $ownerA = $this->user(true, $perms);
        $ownerB = $this->user(true, $perms);
        $companies = [
            'ZZZ MARCA ALFA' => $this->company('ZZZ MARCA ALFA', $ownerA),
            'ZZZ MARCA BETA' => $this->company('ZZZ MARCA BETA', $ownerB),
            'ZZZ MARCA GAMMA' => $this->company('ZZZ MARCA GAMMA', null),
        ];
        [$a, $b, $c] = array_values($companies);

        $unverifiedA = $this->user(false, $perms);
        $a->users()->attach($unverifiedA->id);
        $noCompany = $this->user(true, $perms);
        $noPermsA = $this->user(true, []);
        $a->users()->attach($noPermsA->id);
        $admin = $this->user(true, $perms, config('filament-shield.super_admin.name'));

        $actors = [
            'guest' => null,
            'unverified, linked to A' => $unverifiedA,
            'owner of A' => $ownerA,
            'user with no company' => $noCompany,
            'linked to A, no permission' => $noPermsA,
            'super_admin, not linked' => $admin,
        ];

        $results = [];
        foreach ($actors as $actor => $user) {
            foreach (['pdf', 'xls'] as $kind) {
                foreach (['A' => $a, 'B' => $b, 'C (no users)' => $c] as $label => $empresa) {
                    $r = $this->fetch($user, $kind, (string) $empresa->id, $companies);
                    $this->report($actor, "{$kind} {$label}", $r, $companies);
                    $results[$actor][$kind][$label] = $r;
                }
            }
        }

        // Ids inexistentes y manipulados (como invitado).
        $missing = (string) (Empresa::max('id') + 100000);
        foreach (['pdf' => [$missing], 'xls' => [$missing, $a->id.'?x=1', 'abc']] as $kind => $ids) {
            foreach ($ids as $id) {
                $r = $this->fetch(null, $kind, (string) $id, $companies);
                $this->report('guest', "{$kind} ".(str_contains($id, '?') ? 'A?x=1' : ($id === 'abc' ? 'abc' : 'missing id')), $r, $companies);
                $results['guest'][$kind][$id] = $r;
            }
        }

        // Lo que hace el código HOY (sin control de acceso). Estas afirmaciones se invierten con la reparación.
        foreach (['guest', 'unverified, linked to A', 'user with no company', 'linked to A, no permission'] as $actor) {
            $this->assertSame(200, $results[$actor]['pdf']['B']['status'], "{$actor}: PDF of B is served today");
            $this->assertSame('ZZZ MARCA BETA', $results[$actor]['pdf']['B']['owner'], "{$actor}: and it is B's profile");
            $this->assertSame(200, $results[$actor]['xls']['B']['status'], "{$actor}: XLS of B is served today");
            $this->assertSame('ZZZ MARCA BETA', $results[$actor]['xls']['B']['owner'], "{$actor}: and it contains only B");
        }
        $this->assertSame([$b->id], $results['guest']['xls']['B']['queried'], 'The XLS query is filtered to the requested id (one company), not every company.');
        $this->assertSame(404, $results['guest']['pdf'][$missing]['status'], 'PDF: route-model binding answers 404 for a missing id.');
        $this->assertSame('no data rows', $results['guest']['xls'][$missing]['owner'] ?? 'no data rows', 'XLS: a missing id gives a sheet with no data rows.');
    }
}
