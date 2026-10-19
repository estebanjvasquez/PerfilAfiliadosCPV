<?php

namespace Tests\Feature;

use App\Exports\JoinViewExport;
use App\Filament\Resources\EmpresaResource;
use App\Models\Empresa;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * CPV-EXPORT-ACCESS-REPAIR-AND-ERROR-PAGES (Issue #2 `6264837159`): pruebas de seguridad de la descarga del perfil
 * de una empresa, en PDF y XLS.
 *
 * Reglas aprobadas por el propietario:
 * - **Invitado:** login del panel.
 * - **Sin verificar:** página de verificación.
 * - **Afiliado verificado:** solo su empresa, y con `view_empresa`.
 * - **Personal de la Cámara:** las mismas reglas de vínculo y permiso. No hay acceso automático a otras empresas.
 * - **Todo lo demás:** el mismo 404 «Archivo no disponible».
 * Ninguna prueba de este archivo espera que una descarga no autorizada funcione.
 *
 * Datos SINTÉTICOS con una marca distinta por empresa (ALFA, BETA, GAMMA), en el PostgreSQL desechable.
 *
 * Doble declarado: las vistas de reporte (`capacityView`, …) que este branch no trae se crean DENTRO de la
 * transacción del test. Vienen de tests/Fixtures/pgsql_report_views/, copiadas de
 * `feature/supplhi-postgres-buscador` @ `5df23fa`, y su md5 es el mismo que en staging (primer test).
 *
 * Espías:
 * - **Composer de la vista `pdf`:** registra cada render.
 * - **DB::listen:** registra cada consulta a una vista de reporte.
 * En los caminos denegados, los dos tienen que quedar en CERO.
 */
class ExportAccessTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    /** md5(pg_get_viewdef) en staging, 2026-10-19 (solo lectura sobre pg_class, sin datos). */
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

    private const PERMS = ['view_any_empresa', 'view_empresa', 'update_empresa'];

    private int $pdfRenders = 0;

    private int $viewQueries = 0;

    /** @var array<string, Empresa> */
    private array $companies = [];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $db = DB::connection('pgsql');
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
    }

    private function user(bool $verified = true, array $permissions = self::PERMS, ?string $role = null): User
    {
        $user = $verified ? User::factory()->create() : User::factory()->unverified()->create();
        if ($role) {
            $r = Role::findOrCreate($role, 'web');
            $r->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p, 'web'), $permissions));
            $user->assignRole($r);
        } elseif ($permissions) {
            $user->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p, 'web'), $permissions));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function company(string $marker, ?User $owner): Empresa
    {
        $this->actingAs($owner ?? $this->user());
        $empresa = Empresa::create(['rif' => 'J'.random_int(100000000, 999999999), 'name' => $marker, 'phone' => '+58-212-5550000']);
        if (! $owner) {
            $empresa->users()->detach();
        }
        auth()->logout();

        return $this->companies[$marker] = $empresa;
    }

    /** Vínculo empresa-usuario directo en la tabla: Empresa::users() necesita una sesión (backlog conocido). */
    private function link(Empresa $empresa, User $user): void
    {
        DB::connection()->table('empresa_user')->insert(['empresa_id' => $empresa->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function url(string $kind, string $id): string
    {
        return route("filament.admin.export.{$kind}", ['empresa' => $id]);
    }

    private function hit(?User $as, string $url, string $method = 'GET', array $headers = []): \Illuminate\Testing\TestResponse
    {
        $this->pdfRenders = 0;
        $this->viewQueries = 0;
        auth()->logout();
        $this->flushSession();
        if ($as) {
            $this->actingAs($as);
        }

        return $this->call($method, $url, [], [], [], $this->transformHeadersToServerVars($headers));
    }

    private function assertNothingGenerated(string $label): void
    {
        $this->assertSame(0, $this->viewQueries, "{$label}: no report-view query");
        $this->assertSame(0, $this->pdfRenders, "{$label}: no PDF render");
    }

    private function assertPrivate(\Illuminate\Testing\TestResponse $r, string $label): void
    {
        $cache = (string) $r->headers->get('Cache-Control');
        foreach (['private', 'no-store', 'max-age=0'] as $directive) {
            $this->assertStringContainsString($directive, $cache, "{$label}: Cache-Control has {$directive}");
        }
        $this->assertStringNotContainsString('public', $cache, "{$label}: never public");
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'), "{$label}: nosniff");
    }

    private function sheetText(\Illuminate\Testing\TestResponse $r): string
    {
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($r->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray();

        return mb_strtoupper(implode(' | ', array_map(fn ($row) => implode(' ', array_map('strval', $row)), $sheet)));
    }

    /** @return array{0: Empresa, 1: Empresa, 2: Empresa, 3: User, 4: User} */
    private function world(): array
    {
        $ownerA = $this->user();
        $ownerB = $this->user();
        $a = $this->company('ZZZ MARCA ALFA', $ownerA);
        $b = $this->company('ZZZ MARCA BETA', $ownerB);
        $c = $this->company('ZZZ MARCA GAMMA', null);

        return [$a, $b, $c, $ownerA, $ownerB];
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

    #[Test]
    public function guests_go_to_the_panel_login_and_nothing_is_generated(): void
    {
        [$a, $b] = $this->world();
        $login = Filament::getPanel('admin')->getLoginUrl();
        foreach (['pdf', 'xls'] as $kind) {
            foreach ([(string) $a->id, (string) $b->id, '999999999', 'abc'] as $id) {
                foreach (['GET', 'HEAD'] as $method) {
                    $r = $this->hit(null, $this->url($kind, $id), $method);
                    $r->assertRedirect($login);
                    $this->assertNothingGenerated("guest {$method} {$kind} {$id}");
                }
            }
            // La ruta vieja solo redirige a la nueva, sin consultar nada: la respuesta no revela si la empresa existe.
            $old = $this->hit(null, "/{$kind}/{$a->id}");
            $old->assertRedirect($this->url($kind, (string) $a->id));
            $this->assertNothingGenerated("guest old {$kind}");
            $this->assertSame((string) $this->hit(null, "/{$kind}/999999999")->headers->get('Location'), $this->url($kind, '999999999'));
        }
    }

    #[Test]
    public function unverified_users_go_to_the_verification_page_and_nothing_is_generated(): void
    {
        [$a] = $this->world();
        $unverified = $this->user(verified: false);
        $this->link($a, $unverified);
        $prompt = Filament::getPanel('admin')->getEmailVerificationPromptUrl();
        foreach (['pdf', 'xls'] as $kind) {
            foreach (['GET', 'HEAD'] as $method) {
                $r = $this->hit($unverified, $this->url($kind, (string) $a->id), $method);
                $r->assertRedirect($prompt);
                $this->assertNothingGenerated("unverified {$method} {$kind}");
            }
        }
        // La página de verificación explica la situación; no hay 500.
        $this->hit($unverified, $prompt)->assertOk()->assertSee('Verifique su correo electrónico')->assertSee('todavía no envía correos');
    }

    #[Test]
    public function the_owner_downloads_their_own_company_with_private_headers_and_a_useful_name(): void
    {
        [$a, $b, , $ownerA] = $this->world();

        $pdf = $this->hit($ownerA, $this->url('pdf', (string) $a->id));
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertSame(1, $this->pdfRenders);
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $pdf->headers->get('Content-Disposition'));
        $this->assertStringContainsString('perfil-'.strtolower($a->rif).'.pdf', (string) $pdf->headers->get('Content-Disposition'));
        $this->assertPrivate($pdf, 'owner pdf');

        $xls = $this->hit($ownerA, $this->url('xls', (string) $a->id));
        $xls->assertOk();
        $text = $this->sheetText($xls);
        $this->assertStringContainsString('ZZZ MARCA ALFA', $text);
        $this->assertStringNotContainsString('ZZZ MARCA BETA', $text);
        $this->assertStringNotContainsString('ZZZ MARCA GAMMA', $text);
        $this->assertStringStartsWith('attachment', (string) $xls->headers->get('Content-Disposition'));
        $this->assertStringContainsString('perfil-'.strtolower($a->rif).'.xlsx', (string) $xls->headers->get('Content-Disposition'));
        $this->assertStringContainsString('spreadsheetml', (string) $xls->headers->get('Content-Type'));
        $this->assertPrivate($xls, 'owner xls');

        // Un query string inofensivo no cambia la empresa ni produce un 500.
        $q = $this->hit($ownerA, $this->url('xls', (string) $a->id).'?x=1');
        $q->assertOk();
        $this->assertStringContainsString('ZZZ MARCA ALFA', $this->sheetText($q));

        // HEAD: mismas cabeceras, sin cuerpo.
        foreach (['pdf', 'xls'] as $kind) {
            $head = $this->hit($ownerA, $this->url($kind, (string) $a->id), 'HEAD');
            $head->assertOk();
            $this->assertPrivate($head, "owner HEAD {$kind}");
        }

        // El enlace de la columna «PDF» de la lista de Empresas apunta a la ruta protegida.
        $this->assertSame($this->url('pdf', (string) $a->id), route('filament.admin.export.pdf', ['empresa' => $a->getKey()]));
        $this->actingAs($ownerA)->get(EmpresaResource::getUrl('index'))->assertOk();
    }

    #[Test]
    public function every_denial_is_the_same_safe_404_and_nothing_is_generated(): void
    {
        [$a, $b, $c, $ownerA] = $this->world();
        $noCompany = $this->user();
        $noPermission = $this->user(permissions: []);
        $this->link($a, $noPermission);
        $staffNotLinked = $this->user(role: config('filament-shield.super_admin.name'));

        $cases = [
            'owner A → B' => [$ownerA, (string) $b->id],
            'owner A → company without users' => [$ownerA, (string) $c->id],
            'user with no company → A' => [$noCompany, (string) $a->id],
            'linked to A without view_empresa → A' => [$noPermission, (string) $a->id],
            'Cámara staff not linked → A' => [$staffNotLinked, (string) $a->id],
            'missing id' => [$ownerA, '999999999'],
            'non-numeric id' => [$ownerA, 'abc'],
            'huge id' => [$ownerA, str_repeat('9', 30)],
        ];

        foreach (['pdf', 'xls'] as $kind) {
            $reference = null;
            foreach ($cases as $label => [$user, $id]) {
                $r = $this->hit($user, $this->url($kind, $id));
                $r->assertNotFound();
                $body = (string) $r->getContent();
                $this->assertNothingGenerated("{$kind} {$label}");
                $this->assertPrivate($r, "{$kind} {$label}");
                $this->assertStringContainsString('Archivo no disponible', $body);
                $this->assertStringContainsString('No encontramos el archivo solicitado o su cuenta no tiene acceso para descargarlo.', $body);
                $this->assertStringContainsString('comuníquese con la Cámara', $body);
                foreach ([$a, $b, $c] as $e) {
                    $this->assertStringNotContainsString($e->name, $body, "{$kind} {$label}: no company name");
                    $this->assertStringNotContainsString($e->rif, $body, "{$kind} {$label}: no RIF");
                }
                foreach (['view_empresa', 'empresa_user', 'Exception', 'SQLSTATE', 'vendor/', base_path()] as $leak) {
                    $this->assertStringNotContainsString($leak, $body, "{$kind} {$label}: no technical detail ({$leak})");
                }
                // Mismo cuerpo exacto para «no existe» y «no tiene acceso»: no hay oráculo de existencia.
                $reference ??= $body;
                $this->assertSame($reference, $body, "{$kind} {$label}: identical to the other denials");

                $head = $this->hit($user, $this->url($kind, $id), 'HEAD');
                $head->assertNotFound();
                $this->assertNothingGenerated("HEAD {$kind} {$label}");
            }

            // JSON: el mismo mensaje seguro, idéntico para inexistente y sin acceso.
            $json1 = $this->hit($ownerA, $this->url($kind, (string) $b->id), 'GET', ['Accept' => 'application/json']);
            $json2 = $this->hit($ownerA, $this->url($kind, '999999999'), 'GET', ['Accept' => 'application/json']);
            $json1->assertNotFound()->assertExactJson(['message' => 'Archivo no disponible', 'detail' => 'No encontramos el archivo solicitado o su cuenta no tiene acceso para descargarlo.']);
            $this->assertSame($json1->getContent(), $json2->getContent());
            $this->assertNothingGenerated("{$kind} json");
        }
    }

    #[Test]
    public function linked_staff_keep_their_existing_access(): void
    {
        [$a] = $this->world();
        $staff = $this->user(role: config('filament-shield.super_admin.name'));
        $this->link($a, $staff);

        $this->hit($staff, $this->url('pdf', (string) $a->id))->assertOk();
        $xls = $this->hit($staff, $this->url('xls', (string) $a->id));
        $xls->assertOk();
        $this->assertStringContainsString('ZZZ MARCA ALFA', $this->sheetText($xls));
    }

    #[Test]
    public function the_general_404_is_safe_for_guests_and_users(): void
    {
        [, $b, , $ownerA] = $this->world();

        $guest = $this->hit(null, '/esta-direccion-no-existe-zzz');
        $guest->assertNotFound()->assertSee('Página no encontrada')->assertSee('Iniciar sesión');
        $this->assertStringNotContainsString('esta-direccion-no-existe-zzz', (string) $guest->getContent());

        // La edición de una empresa ajena ya respondía 404: ahora con la página en español, sin datos de esa empresa.
        $other = $this->hit($ownerA, EmpresaResource::getUrl('edit', ['record' => $b]));
        $other->assertNotFound()->assertSee('Página no encontrada');
        $this->assertStringNotContainsString('ZZZ MARCA BETA', (string) $other->getContent());
        $this->assertStringNotContainsString($b->rif, (string) $other->getContent());

        // Como en producción (APP_DEBUG apagado; el entorno aislado lo tiene encendido): JSON sin detalles.
        config(['app.debug' => false]);
        $json = $this->hit(null, '/esta-direccion-no-existe-zzz', 'GET', ['Accept' => 'application/json']);
        $json->assertNotFound();
        $this->assertSame(['message'], array_keys($json->json()));
    }

    #[Test]
    public function the_camara_join_views_report_exports_only_the_validated_company(): void
    {
        [$a] = $this->world();
        $rows = (new JoinViewExport((int) $a->id))->collection();
        $this->assertCount(1, $rows);
        $this->assertSame('ZZZ MARCA ALFA', mb_strtoupper((string) $rows[0]->nombre));

        // La página de la Cámara (Shield, sin cambio de acceso) toma la empresa una sola vez, al montarse, y solo si
        // el id es numérico. Su vista HTML no se renderiza aquí: ya fallaba con empresas sin datos completos, antes
        // de este cambio y sin relación con él (ver el informe).
        foreach ([(string) $a->id => (int) $a->id, 'abc' => null, '1?2' => null, '' => null] as $query => $expected) {
            $this->app->instance('request', \Illuminate\Http\Request::create('/admin/join-views'.($query === '' ? '' : '?'.$query)));
            $page = new \App\Filament\Pages\JoinViews;
            $page->mount();
            $this->assertSame($expected, $page->empresaId, "join-views?{$query}");
        }
    }
}
