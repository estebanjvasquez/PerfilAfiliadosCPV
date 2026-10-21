<?php

namespace Tests\Feature\Security;

use App\Models\Empresa;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * CPV-SEC-01-R2-ACCOUNT-PATH (Issue #2 `6310273754`, review `6310331211` R2-REV-02): directory-traversal / file
 * boundaries.
 *
 * Directed sink inventory of request-controlled values that could reach a filesystem path (restricted evidence
 * `sec01r2_path_*.json`):
 * - the download route segment `{empresa}` — validated with `ctype_digit` and a length bound, not a path;
 * - the download query `secciones` — allowlisted keys (`CompanyDossier::sectionsFrom`), not a path;
 * - the manual / help sources — fixed `resource_path(...)`, no request input;
 * - the PDF renderer — logos are a fixed `public_path(...)`; no request-controlled value is placed as an image src or
 *   `url()` in the template, so no user value reaches dompdf's resource loader (dompdf has `enable_remote=true` and
 *   `chroot=base_path`; hardening that is a proposed SEC-02/04 follow-up, not a reachable traversal here).
 *
 * The two request-controlled values are driven with traversal payloads and a harmless CANARY file placed OUTSIDE the
 * app root (a disposable temp directory). No operating-system or credential file is ever targeted. Assertions check
 * CONTENT and side effects, not only status: for a rendered PDF the decoded text is searched (compressed bytes are not
 * proof); for a rejected identifier the exact safe denial is asserted, with no report-view query and no PDF render; and
 * the layer that denied (router vs controller) is recorded. This is a negative result for exactly these surfaces.
 */
class PathBoundaryTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private const VIEW_PATTERN = '/"?(capacityView|catalogoView|ClientsView|FinanceView|ManagementDetView|ExperienceView|PresenceView|SustainabilityView|ResourceView|MachineryView|FacilityView|InventoryView|ManagementView)"?/';

    private string $canary;

    private string $canaryContent;

    private int $pdfRenders = 0;

    private int $viewQueries = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach (glob(base_path('tests/Fixtures/pgsql_report_views/*.php')) as $file) {
            (require $file)->up();
        }
        View::composer('pdf', function () {
            $this->pdfRenders++;
        });
        DB::listen(function ($query) {
            if (preg_match(self::VIEW_PATTERN, $query->sql)) {
                $this->viewQueries++;
            }
        });

        $dir = sys_get_temp_dir().'/cpv_sec01r2_'.uniqid();
        @mkdir($dir, 0775, true);
        $this->canary = $dir.'/canary_outside_root.txt';
        $this->canaryContent = 'ZZZCANARYSECRET-'.uniqid();
        file_put_contents($this->canary, $this->canaryContent);
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

    /**
     * Traversal payloads in several encodings and separator forms. Relative ones are built to reference THE CANARY if
     * naive concatenation against a base directory were to occur; the absolute ones point straight at it. No OS file is
     * used as a target.
     */
    private function payloads(): array
    {
        $abs = $this->canary;
        $name = basename($this->canary);

        return [
            '..' => '..',
            'dotdot-slash-canary' => '../'.$name,
            'deep-dotdot-canary' => str_repeat('../', 8).ltrim(str_replace('\\', '/', $abs), '/'),
            'backslash-dotdot-canary' => '..\\'.$name,
            'collapsing-dotdot' => '....//'.$name,
            'enc-dotdot-slash' => '%2e%2e%2f'.$name,
            'enc-dotdot-deep' => '%2e%2e%2f%2e%2e%2f'.$name,
            'double-enc-dotdot' => '%252e%252e%252f'.$name,
            'enc-backslash' => '..%5c'.$name,
            'absolute-canary' => $abs,
            'file-scheme-canary' => 'file://'.$abs,
            'enc-absolute-canary' => rawurlencode($abs),
        ];
    }

    private function resetSpies(): void
    {
        $this->pdfRenders = 0;
        $this->viewQueries = 0;
    }

    private function assertNoCanaryInText(string $text, string $label): void
    {
        $this->assertStringNotContainsString($this->canaryContent, $text, "{$label}: canary content not disclosed");
        $this->assertStringNotContainsString(str_replace('\\', '/', dirname($this->canary)), str_replace('\\', '/', $text), "{$label}: no filesystem path leak");
    }

    #[Test]
    public function the_company_id_segment_is_numeric_and_never_a_path(): void
    {
        $owner = $this->owner();
        $company = $this->company($owner);
        $this->actingAs($owner);

        $cases = [];
        foreach ($this->payloads() as $key => $payload) {
            $this->resetSpies();
            $r = $this->get('/admin/descargas/empresas/'.rawurlencode($payload).'/pdf');
            $status = $r->getStatusCode();
            $this->assertLessThan(500, $status, "{$key}: no 5xx (got {$status})");
            $this->assertNotSame('application/pdf', $r->headers->get('Content-Type'), "{$key}: no PDF generated");

            $body = (string) $r->getContent();
            $this->assertStringNotContainsString($this->canaryContent, $body, "{$key}: canary content not disclosed");
            $this->assertStringNotContainsString('root:x:0:0', $body, "{$key}: no system file content");

            // Which layer denied: the controller returns the «Archivo no disponible» view (404); otherwise the router
            // rejected the path before the controller. Either way nothing was read or rendered.
            $isController = $status === 404 && str_contains($body, 'Archivo no disponible');
            if ($isController) {
                $this->assertSame(0, $this->viewQueries, "{$key}: no report-view query");
                $this->assertSame(0, $this->pdfRenders, "{$key}: no PDF render");
            } else {
                $this->assertContains($status, [403, 404], "{$key}: safe rejection status");
            }
            $cases[$key] = ['payload' => $payload, 'status' => $status, 'layer' => $isController ? 'controller:not-available' : 'router'];
        }

        // Side effects: the canary is untouched, and the legitimate numeric id still downloads a real PDF.
        $this->assertFileExists($this->canary);
        $this->assertSame($this->canaryContent, file_get_contents($this->canary), 'canary content unchanged');

        $this->resetSpies();
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

        $dir = storage_path('app/pdf_evidence');
        @mkdir($dir, 0775, true);

        $cases = [];
        foreach ($this->payloads() as $key => $payload) {
            $r = $this->get('/admin/descargas/empresas/'.$company->getKey().'/pdf?secciones='.rawurlencode($payload));
            $r->assertOk();
            $this->assertSame('application/pdf', $r->headers->get('Content-Type'), "{$key}: a PDF is returned");
            // An unrecognised value falls back to the complete profile (no section is a filename).
            $this->assertStringContainsString('perfil-'.strtolower($company->rif).'.pdf', (string) $r->headers->get('Content-Disposition'), "{$key}: full profile");

            // Decode the actual PDF text (compressed bytes are not proof) and assert the canary is not in the document.
            $file = "{$dir}/path_secciones_{$key}.pdf";
            file_put_contents($file, $r->getContent());
            $run = Process::run(['pdftotext', '-enc', 'UTF-8', $file, '-']);
            $this->assertTrue($run->successful(), "{$key}: pdftotext available");
            $this->assertNoCanaryInText($run->output(), $key);
            @unlink($file);

            $cases[$key] = ['payload' => $payload, 'result' => 'full-profile', 'pdf_text_checked' => true];
        }

        $this->assertFileExists($this->canary);
        $this->assertSame($this->canaryContent, file_get_contents($this->canary), 'canary content unchanged');

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
