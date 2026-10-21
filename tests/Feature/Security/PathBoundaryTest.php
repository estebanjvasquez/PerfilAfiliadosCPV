<?php

namespace Tests\Feature\Security;

use App\Models\Empresa;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * CPV-SEC-01-R2-ACCOUNT-PATH (Issue #2 `6310273754`), section C: directory-traversal / file boundaries.
 *
 * Directed sink inventory of request-controlled values that could reach a filesystem path (`tests` evidence
 * `sec01r2_path_sinks.json`):
 * - the company download route segment `{empresa}` — validated with `ctype_digit` and a length bound, not a path;
 * - the download query `secciones` — allowlisted keys (`CompanyDossier::sectionsFrom`), not a path;
 * - the manual / help sources — fixed `resource_path(...)`, with no request input;
 * - the PDF renderer — logos are a fixed `public_path(...)`; no request-controlled value is placed as an image src or
 *   `url()` in the template, so no user value reaches dompdf's resource loader (dompdf has `enable_remote=true` and
 *   `chroot=base_path`; hardening that is a proposed SEC-02/04 follow-up, not a reachable traversal here).
 *
 * This test drives the two request-controlled values with traversal payloads and a CANARY file placed outside the
 * allowed root, and asserts nothing outside the root is read, no 5xx, and no filesystem path leaks. It is a negative
 * result for exactly these surfaces, not a claim about every file surface.
 */
class PathBoundaryTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private string $canary;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach (glob(base_path('tests/Fixtures/pgsql_report_views/*.php')) as $file) {
            (require $file)->up();
        }
        // A harmless canary in a disposable directory OUTSIDE the app, whose content must never appear in a response.
        $dir = sys_get_temp_dir().'/cpv_sec01r2_'.uniqid();
        @mkdir($dir, 0775, true);
        $this->canary = $dir.'/canary_outside_root.txt';
        file_put_contents($this->canary, 'ZZZCANARYSECRET-'.uniqid());
    }

    protected function tearDown(): void
    {
        @unlink($this->canary);
        @rmdir(dirname($this->canary));
        parent::tearDown();
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p, 'web'), ['view_any_empresa', 'view_empresa']));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function company(User $owner): Empresa
    {
        $this->actingAs($owner);
        $empresa = Empresa::create(['rif' => 'J'.random_int(100000000, 999999999), 'name' => 'ZZZPATH', 'phone' => '+58-212-5550000']);
        auth()->logout();

        return $empresa;
    }

    /** Traversal payloads in several encodings and separator forms, pointing at the outside-root canary. */
    private function payloads(): array
    {
        $abs = $this->canary;

        return [
            '../canary_outside_root.txt',
            '../../canary_outside_root.txt',
            '../../../../../../etc/passwd',
            '..\\..\\canary_outside_root.txt',
            '....//....//canary_outside_root.txt',
            '%2e%2e%2fcanary_outside_root.txt',
            '%2e%2e%2f%2e%2e%2fcanary_outside_root.txt',
            '%252e%252e%252fcanary_outside_root.txt',
            '..%5c..%5ccanary_outside_root.txt',
            $abs,
            'file://'.$abs,
            rawurlencode($abs),
        ];
    }

    private function assertSafe(\Illuminate\Testing\TestResponse $r, string $label): void
    {
        $status = $r->getStatusCode();
        $this->assertLessThan(500, $status, "{$label}: no 5xx (got {$status})");
        $body = (string) $r->getContent();
        $this->assertStringNotContainsString('ZZZCANARYSECRET', $body, "{$label}: canary content not disclosed");
        $this->assertStringNotContainsString('root:x:0:0', $body, "{$label}: no system file content");
        // No absolute filesystem path of the canary directory is echoed back in an error.
        $this->assertStringNotContainsString(str_replace('\\', '/', dirname($this->canary)), str_replace('\\', '/', $body), "{$label}: no filesystem path leak");
    }

    #[Test]
    public function the_company_id_segment_is_numeric_and_never_a_path(): void
    {
        $owner = $this->owner();
        $company = $this->company($owner);
        $this->actingAs($owner);

        $cases = [];
        foreach ($this->payloads() as $payload) {
            // As the {empresa} route segment (encoded so routing delivers it to the controller).
            $r = $this->get('/admin/descargas/empresas/'.rawurlencode($payload).'/pdf');
            $this->assertSafe($r, "empresa segment «{$payload}»");
            $this->assertNotSame('application/pdf', $r->headers->get('Content-Type'), "«{$payload}»: no PDF generated");
            $cases[$payload] = $r->getStatusCode();
        }

        // The legitimate numeric id still downloads for its owner.
        $ok = $this->get('/admin/descargas/empresas/'.$company->getKey().'/pdf');
        $ok->assertOk();
        $this->assertSame('application/pdf', $ok->headers->get('Content-Type'));

        $this->writeEvidence('empresa_segment', $cases);
    }

    #[Test]
    public function the_secciones_query_is_an_allowlist_and_never_a_path(): void
    {
        $owner = $this->owner();
        $company = $this->company($owner);
        $this->actingAs($owner);

        $cases = [];
        foreach ($this->payloads() as $payload) {
            $r = $this->get('/admin/descargas/empresas/'.$company->getKey().'/pdf?secciones='.rawurlencode($payload));
            $r->assertOk();
            $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
            $this->assertSafe($r, "secciones «{$payload}»");
            // An unrecognised value falls back to the complete profile (no section is a filename).
            $this->assertStringContainsString('perfil-'.strtolower($company->rif).'.pdf', (string) $r->headers->get('Content-Disposition'), "«{$payload}»: full profile");
            $cases[$payload] = 'full-profile';
        }
        $this->writeEvidence('secciones_query', $cases);
    }

    private function writeEvidence(string $name, array $cases): void
    {
        $dir = storage_path('app/pdf_evidence');
        @mkdir($dir, 0775, true);
        file_put_contents("{$dir}/sec01r2_path_{$name}.json", json_encode([
            'surface' => $name,
            'canary_outside_root' => basename($this->canary),
            'results' => $cases,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
