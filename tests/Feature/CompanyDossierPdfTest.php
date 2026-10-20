<?php

namespace Tests\Feature;

use App\Filament\Resources\EmpresaResource\Pages\EditEmpresa;
use App\Filament\Resources\EmpresaResource\Pages\ListEmpresas;
use App\Models\Asset;
use App\Models\Contact;
use App\Models\Empresa;
use App\Models\EmpresaModuleStatus;
use App\Models\EmpresaTaxonomyCategory;
use App\Models\Experience;
use App\Models\Management;
use App\Models\Presence;
use App\Models\Sustainability;
use App\Models\TaxonomyCategory;
use App\Models\TaxonomyCategoryTranslation;
use App\Models\User;
use App\Support\CompanyDossier;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * CPV-COMPLETE-COMPANY-PDF (Issue #2 `6270558322`): el CONTENIDO REAL del PDF «perfil completo».
 *
 * Cada PDF se genera por la ruta protegida y se lee con `pdftotext` (poppler-utils, en la imagen del runner aislado).
 * No basta con espiar la vista ni con mirar los bytes `%PDF`. Los PDF y su texto quedan en `storage/app/pdf_evidence/`,
 * y el runner renderiza cada página como PNG para revisarla.
 *
 * Datos SINTÉTICOS con un centinela distinto en cada módulo, en el PostgreSQL desechable. Las vistas de reporte se crean
 * desde tests/Fixtures/pgsql_report_views/ (las mismas de staging; ver ExportAccessTest).
 */
class CompanyDossierPdfTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private const VIEW_PATTERN = '/"?(capacityView|catalogoView|ClientsView|FinanceView|ManagementDetView|ExperienceView|PresenceView|SustainabilityView|ResourceView|MachineryView|FacilityView|InventoryView|ManagementView)"?/';

    private const HEADINGS = [
        'Datos generales', 'Operación en Venezuela', 'Contactos', 'Clientes', 'Cámaras', 'Sectores de actividad económica y servicios',
        'Categorías CPV', 'Enfoque de sostenibilidad', 'Recursos en Venezuela', 'Recursos humanos', 'Maquinarias y equipos',
        'Instalaciones', 'Inventario', 'Experiencia relevante', 'Presencia internacional', 'Sistemas de gestión',
    ];

    private int $pdfRenders = 0;

    private int $viewQueries = 0;

    private array $cat = [];

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
        $this->catalogs();
    }

    // ---------------------------------------------------------------- fixtures

    private function catalogs(): void
    {
        $now = ['created_at' => now(), 'updated_at' => now()];
        $db = DB::connection();
        $country = $db->table('countries')->insertGetId(['country_name' => 'ZZZPAISCENTINELA'] + $now);
        $country2 = $db->table('countries')->insertGetId(['country_name' => 'ZZZPAISEXTERIOR'] + $now);
        $state = $db->table('states')->insertGetId(['country_id' => $country, 'state_name' => 'ZZZ Estado'] + $now);
        $sector1 = $db->table('sectors')->insertGetId(['name' => 'ZZZSECTORPRINCIPAL'] + $now);
        $infrasector = $db->table('infrasectors')->insertGetId(['sector_name' => 'ZZZINFRASECTOR'] + $now);
        $infratype = $db->table('infratypes')->insertGetId(['infrasectors_id' => $infrasector, 'type_name' => 'ZZZINFRATIPO'] + $now);
        $infrasystem = $db->table('infrasystems')->insertGetId(['infratypes_id' => $infratype, 'system_name' => 'ZZZINFRASISTEMA'] + $now);
        $infraregion = $db->table('infraregions')->insertGetId(['region_name' => 'ZZZINFRAREGION'] + $now);
        $pivot = $db->table('infraregion_infrasystem')->insertGetId(['infrasystem_id' => $infrasystem, 'infraregion_id' => $infraregion] + $now);
        $this->cat = [
            'country' => $country,
            'country2' => $country2,
            'city' => $db->table('cities')->insertGetId(['country_id' => $country, 'states_id' => $state, 'city_name' => 'ZZZCIUDADCENTINELA'] + $now),
            'sector1' => $sector1,
            'sector2' => $db->table('sectors')->insertGetId(['name' => 'ZZZSECTORSECUNDARIO'] + $now),
            'service' => $db->table('services')->insertGetId(['sectors_id' => $sector1, 'name' => 'ZZZSERVICIOCENTINELA'] + $now),
            'infrasector' => $infrasector,
            'infratype' => $infratype,
            'infrasystem' => $infrasystem,
            'infraregion' => $infraregion,
            'infrafacility' => $db->table('infrafacilities')->insertGetId(['infraregion_infrasystem_id' => $pivot, 'facility_name' => 'ZZZINFRAINSTALACION'] + $now),
            'area1' => $db->table('areas')->insertGetId(['sust_title' => 'ZZZAREAACTIVA', 'sust_description' => 'Área sintética'] + $now),
            'area2' => $db->table('areas')->insertGetId(['sust_title' => 'ZZZAREANO', 'sust_description' => 'Área sintética'] + $now),
            'chamber' => $db->table('chambers')->insertGetId(['name' => 'ZZZCAMARACENTINELA'] + $now),
        ];
    }

    private function user(bool $withPermission = true): User
    {
        $user = User::factory()->create();
        if ($withPermission) {
            $user->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p, 'web'), ['view_any_empresa', 'view_empresa', 'update_empresa']));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /** Empresa creada por su usuario: Empresa::created() la vincula a Auth::user(). */
    private function company(User $owner, array $attributes = []): Empresa
    {
        $this->actingAs($owner);
        $empresa = Empresa::create($attributes + ['rif' => 'J'.random_int(100000000, 999999999), 'name' => 'ZZZ EMPRESA '.Str::upper(Str::random(6))]);
        auth()->logout();

        return $empresa;
    }

    /** Una empresa con datos distintos en TODOS los módulos. `$p` es el prefijo del centinela (A, B…). */
    private function fullCompany(User $owner, string $p): Empresa
    {
        $c = $this->cat;
        $empresa = $this->company($owner, [
            'name' => "ZZZ{$p}NOMBRE", 'phone' => "+58-212-{$p}5550101", 'website' => "https://zzz{$p}.test", 'street' => "ZZZ{$p}CALLE 123",
            'city_id' => $c['city'], 'ano_fund' => 1987, 'linkedin_profile' => "in/zzz{$p}linkedin", 'billing_id' => 3, 'employees_id' => 2,
            'status_id' => 1, 'property_id' => 1, 'origin_id' => 0, 'sector_principal_id' => $c['sector1'], 'sector_secundario_id' => $c['sector2'],
            'customers_country' => [['customer_name' => "ZZZ{$p}CLIENTE", 'country_id' => (string) $c['country']]],
        ]);
        $db = DB::connection();
        $now = ['created_at' => now(), 'updated_at' => now()];

        foreach ([["ZZZ{$p}CONTACTOUNO", true], ["ZZZ{$p}CONTACTODOS", false]] as [$name, $principal]) {
            $contact = Contact::create(['name' => $name, 'position' => "ZZZ{$p}CARGO", 'phone' => '+58-414-0000000', 'email' => strtolower($name).'@zzz.test']);
            $db->table('contact_empresa')->insert(['contact_id' => $contact->id, 'empresa_id' => $empresa->id, 'is_principal' => $principal] + $now);
        }
        $db->table('chamber_empresa')->insert(['empresa_id' => $empresa->id, 'chamber_id' => $c['chamber']] + $now);
        $db->table('empresa_sector_service')->insert(['empresa_id' => $empresa->id, 'service_id' => $c['service']] + $now);

        $grupo = TaxonomyCategory::create(['code' => 'CPV-'.random_int(900, 999), 'parent_id' => null, 'is_active' => true, 'source_version' => 'test']);
        $hoja = TaxonomyCategory::create(['code' => $grupo->code.'.'.random_int(10, 49), 'parent_id' => $grupo->id, 'is_active' => true, 'source_version' => 'test']);
        $sugerida = TaxonomyCategory::create(['code' => $grupo->code.'.'.random_int(50, 98), 'parent_id' => $grupo->id, 'is_active' => true, 'source_version' => 'test']);
        foreach ([[$grupo, "ZZZ{$p}GRUPOCPV"], [$hoja, "ZZZ{$p}CATEGORIACPV"], [$sugerida, "ZZZ{$p}SUGERIDA"]] as [$cat, $name]) {
            TaxonomyCategoryTranslation::create(['category_id' => $cat->id, 'locale' => 'es', 'name' => $name]);
        }
        EmpresaTaxonomyCategory::create(['empresa_id' => $empresa->id, 'category_id' => $hoja->id, 'origen' => EmpresaTaxonomyCategory::ORIGEN_SELF_DECLARED, 'es_principal' => true]);
        EmpresaTaxonomyCategory::create(['empresa_id' => $empresa->id, 'category_id' => $sugerida->id, 'origen' => EmpresaTaxonomyCategory::ORIGEN_SUGGESTED, 'es_principal' => false]);

        $this->actingAs($owner);
        Sustainability::create(['empresa_id' => $empresa->id, 'areas_id' => $c['area1'], 'sust_status' => 2]);
        Sustainability::create(['empresa_id' => $empresa->id, 'areas_id' => $c['area2'], 'sust_status' => 0]);
        Asset::create([
            'empresa_id' => $empresa->id,
            'employee' => [['employee_type' => 2, 'junior_q' => 7, 'medium_q' => 8, 'senior_q' => 9]],
            'machinery' => [['machinery_name' => 8, 'machinery_qid' => 3, 'machinery_est' => 2]],
            'facility' => [['facility_type' => 4, 'facility_q' => 3, 'facility_surf' => 456, 'facility_own' => 2]],
            'inventory' => [['inventory_name' => 1, 'inventory_q' => 2, 'inventory_unit' => "ZZZ{$p}UNIDAD", 'inventory_est' => 4]],
        ]);
        // Como lo guarda el formulario: claves UUID con {type, data}. Seis entradas y una descripción larga, para varias páginas.
        $entries = [];
        foreach (range(2014, 2019) as $i => $year) {
            $entries[(string) Str::uuid()] = ['type' => 'Experiencia Relevante', 'data' => [
                'exp_year' => (string) $year, 'infrasectors_id' => $c['infrasector'], 'infratypes_id' => $c['infratype'], 'infrasystems_id' => $c['infrasystem'],
                'infraregions_id' => $c['infraregion'], 'infrafacilities_id' => $c['infrafacility'], 'magnitud' => '2', 'prof_tech' => '1500', 'manpower' => '3200',
                'sectors_id' => $c['sector1'], 'services_id' => [$c['service']],
                'Descripcion' => "ZZZ{$p}EXPERIENCIA{$i} ".str_repeat('Trabajo sintético de mantenimiento mayor en planta. ', 6),
            ]];
        }
        Experience::create(['empresa_id' => $empresa->id, 'exp_year' => $entries]);
        // Los Repeater guardan listas (Repeater::mutateDehydratedStateUsing -> array_values). Una oficina y DOS experiencias:
        // PresenceView las empareja por posición y perdía la segunda.
        Presence::create([
            'empresa_id' => $empresa->id,
            'has_offices' => true,
            'office_data' => [['country_id' => $c['country2'], 'offices_surf' => '120', 'employees_q' => 4, 'status' => true]],
            'has_experience' => true,
            'experience_data' => [
                ['expcountry_id' => $c['country2'], 'projects_q' => 3, 'role' => '2', 'executed_q' => '3', 'expemployees_q' => 10, 'main_clients' => "ZZZ{$p}CLIENTEEXTERIOR"],
                ['expcountry_id' => $c['country'], 'projects_q' => 5, 'role' => '1', 'executed_q' => '4', 'expemployees_q' => 2, 'main_clients' => "ZZZ{$p}SEGUNDOCLIENTE"],
            ],
        ]);
        Management::create([
            'empresa_id' => $empresa->id, 'iso9001' => true, 'iso17025' => false, 'quality_otros' => true, 'quality_data' => [['quality_otros_name' => "ZZZ{$p}OTROCALIDAD"]],
            'iso14001' => false, 'iso50001' => true, 'environment_otros' => false, 'dun' => false, 'iso37001' => true, 'credibility_otros' => false,
            'iso45001' => true, 'ovid' => true, 'security_otros' => false, 'pmi' => true, 'pmi_otros' => false, 'iso27001' => false, 'info_otros' => true,
            'info_data' => [['info_otros_name' => "ZZZ{$p}OTROINFO"]],
        ]);
        auth()->logout();

        return $empresa;
    }

    // ---------------------------------------------------------------- helpers

    private function url(Empresa|string $empresa, array $query = []): string
    {
        return route('filament.admin.export.pdf', ['empresa' => $empresa instanceof Empresa ? $empresa->getKey() : $empresa] + $query);
    }

    private function hit(?User $as, string $url): \Illuminate\Testing\TestResponse
    {
        $this->pdfRenders = 0;
        $this->viewQueries = 0;
        auth()->logout();
        $this->flushSession();
        if ($as) {
            $this->actingAs($as);
        }

        return $this->get($url);
    }

    /** Guarda el PDF como evidencia y devuelve su texto real (pdftotext), con los espacios normalizados. */
    private function pdfText(\Illuminate\Testing\TestResponse $r, string $evidence): string
    {
        $r->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $r->getContent());
        $dir = storage_path('app/pdf_evidence');
        @mkdir($dir, 0775, true);
        $file = "{$dir}/{$evidence}.pdf";
        file_put_contents($file, $r->getContent());

        $run = Process::run(['pdftotext', '-enc', 'UTF-8', $file, '-']);
        $this->assertTrue($run->successful(), 'pdftotext (poppler-utils) is required to read the real PDF: '.$run->errorOutput());
        $text = $run->output();
        file_put_contents("{$dir}/{$evidence}.txt", $text);

        return preg_replace('/\s+/u', ' ', $text);
    }

    private function pages(string $evidence): int
    {
        $info = Process::run(['pdfinfo', storage_path("app/pdf_evidence/{$evidence}.pdf")])->output();

        return preg_match('/^Pages:\s+(\d+)/m', $info, $m) ? (int) $m[1] : 0;
    }

    /**
     * PDF-01 (`6272035810`): the header must be on EVERY page, checked page by page in the real PDF, not on the whole text.
     * - **Text:** the page's own text (`pdftotext -f N -l N`) has the title, the company name, «RIF <rif>» and «Página N de M».
     * - **Logos:** the page places its two logo images (`pdfimages -list`).
     * - **Pixels:** rendered at 72 dpi (1 px = 1 pt), the row at 20 pt is mostly the yellow band, and the row at 100 pt (between the
     *   band, which ends at ~94 pt, and the body, which starts at 112.5 pt) is pure white: no missing band, no band overflowing
     *   into the body, no body text under the band.
     *
     * @return int number of pages checked
     */
    private function assertHeaderOnEveryPage(string $evidence, string $title, Empresa $empresa): int
    {
        $file = storage_path("app/pdf_evidence/{$evidence}.pdf");
        $pages = $this->pages($evidence);
        $this->assertGreaterThan(0, $pages, "{$evidence}: page count");
        $flat = fn (string $s) => preg_replace('/\s+/u', ' ', $s);
        for ($p = 1; $p <= $pages; $p++) {
            $label = "{$evidence} page {$p}/{$pages}";
            $text = $flat(Process::run(['pdftotext', '-f', (string) $p, '-l', (string) $p, '-enc', 'UTF-8', $file, '-'])->output());
            $this->assertSame(1, substr_count($text, $title), "{$label}: title once");
            $this->assertStringContainsString($flat($empresa->name).' · RIF '.$empresa->rif, $text, "{$label}: company name and RIF");
            $this->assertStringContainsString("Página {$p} de {$pages}", $text, "{$label}: footer");

            $images = collect(explode("\n", Process::run(['pdfimages', '-f', (string) $p, '-l', (string) $p, '-list', $file])->output()))
                ->filter(fn ($line) => preg_match('/^\s*\d+\s+\d+\s+image\b/', $line))->count();
            $this->assertSame(2, $images, "{$label}: both logos");

            $png = storage_path("app/pdf_evidence/{$evidence}-p{$p}-72dpi");
            Process::run(['pdftoppm', '-f', (string) $p, '-l', (string) $p, '-r', '72', '-png', '-singlefile', $file, $png]);
            $img = imagecreatefrompng("{$png}.png");
            $this->assertNotFalse($img, "{$label}: rendered");
            $width = imagesx($img);
            $share = function (int $y, callable $test) use ($img, $width) {
                $n = 0;
                for ($x = 0; $x < $width; $x++) {
                    $c = imagecolorat($img, $x, $y);
                    $n += $test(($c >> 16) & 255, ($c >> 8) & 255, $c & 255) ? 1 : 0;
                }

                return $n / $width;
            };
            $yellow = fn ($r, $g, $b) => $r > 220 && $g > 150 && $g < 215 && $b < 80;
            $white = fn ($r, $g, $b) => $r > 245 && $g > 245 && $b > 245;
            $this->assertGreaterThan(0.6, $share(20, $yellow), "{$label}: yellow band at the top");
            $this->assertSame(1.0, $share(100, $white), "{$label}: clean gap between band and body");
            @unlink("{$png}.png");
        }

        return $pages;
    }

    // ---------------------------------------------------------------- tests

    #[Test]
    public function every_page_carries_the_header_in_complete_partial_minimal_and_long_documents(): void
    {
        $owner = $this->user();
        $full = $this->fullCompany($owner, 'A');

        // Longest name the column allows (100), and a long experience description, for wrapping and pagination.
        $long = $this->fullCompany($owner, 'L');
        $long->forceFill(['name' => mb_substr('ZZZ CONSORCIO DE SERVICIOS PETROLEROS INTEGRALES DE ORIENTE Y OCCIDENTE, MANTENIMIENTO Y CONSTRUCCION C.A. EXTRA', 0, 100)])->saveQuietly();
        $this->actingAs($owner);
        $experience = Experience::where('empresa_id', $long->id)->firstOrFail();
        $entries = $experience->exp_year;
        $first = array_key_first($entries);
        $entries[$first]['data']['Descripcion'] = 'ZZZLARGA '.str_repeat('Descripción sintética extensa de un contrato de ingeniería, procura y construcción con varias fases y entregables. ', 40);
        $experience->update(['exp_year' => $entries]);
        auth()->logout();
        $long->refresh();
        $this->assertSame(100, mb_strlen($long->name));

        $minimal = $this->company($owner, ['name' => 'ZZZMINIMA']);
        $complete = 'PERFIL COMPLETO DE LA EMPRESA';
        $partial = 'PERFIL DE LA EMPRESA — SECCIONES SELECCIONADAS';

        $checked = [];
        foreach ([
            ['header_full', $full, [], $complete],
            ['header_partial', $full, ['secciones' => 'generales,experiencia'], $partial],
            ['header_minimal', $minimal, [], $complete],
            ['header_long_full', $long, [], $complete],
            ['header_long_partial', $long, ['secciones' => 'experiencia,gestion'], $partial],
        ] as [$evidence, $empresa, $query, $title]) {
            $text = $this->pdfText($this->hit($owner, $this->url($empresa, $query)), $evidence);
            $checked[$evidence] = $this->assertHeaderOnEveryPage($evidence, $title, $empresa);
        }
        $this->assertStringContainsString('ZZZLARGA', $text);
        // The long description really spans pages, so the check covers a card split across a page break.
        $this->assertGreaterThan($checked['header_full'], $checked['header_long_full']);
        file_put_contents(storage_path('app/pdf_evidence/header_pages.json'), json_encode($checked, JSON_PRETTY_PRINT));
    }

    #[Test]
    public function the_complete_profile_is_the_default_and_contains_every_module(): void
    {
        $ownerA = $this->user();
        $a = $this->fullCompany($ownerA, 'A');
        $this->fullCompany($this->user(), 'B');

        $r = $this->hit($ownerA, $this->url($a));
        $this->assertStringStartsWith('attachment', (string) $r->headers->get('Content-Disposition'));
        $this->assertStringContainsString('perfil-'.strtolower($a->rif).'.pdf', (string) $r->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $text = $this->pdfText($r, 'complete_full_company');
        $this->assertSame(1, $this->pdfRenders);

        $this->assertStringContainsString('PERFIL COMPLETO DE LA EMPRESA', $text);
        $this->assertStringContainsString('todas las secciones del perfil', $text);
        $this->assertStringContainsString('No es una calificación crediticia', $text);
        foreach (self::HEADINGS as $heading) {
            $this->assertStringContainsString($heading, $text, "heading «{$heading}»");
        }
        $pages = $this->pages('complete_full_company');
        $this->assertGreaterThan(1, $pages);
        $this->assertStringContainsString("Página 1 de {$pages}", $text);
        $this->assertStringContainsString("Página {$pages} de {$pages}", $text);

        $expected = [
            // Datos generales, contactos, operación
            'ZZZANOMBRE', 'ZZZACALLE 123', 'ZZZCIUDADCENTINELA, ZZZPAISCENTINELA', 'https://zzzA.test', 'LinkedIn: in/zzzAlinkedin', '1987',
            'Privado / Internacional', 'ZZZSECTORPRINCIPAL', 'ZZZSECTORSECUNDARIO', '1.000.001 - 10.000.000 USD', '51 - 100', 'Activa',
            'ZZZACONTACTOUNO', '(contacto principal)', 'ZZZACONTACTODOS', 'ZZZACARGO', 'zzzacontactouno@zzz.test',
            // Clientes, cámaras, sectores y servicios, categorías
            'ZZZACLIENTE', 'ZZZCAMARACENTINELA', 'ZZZSERVICIOCENTINELA', 'ZZZAGRUPOCPV > ZZZACATEGORIACPV', 'Declarada por la empresa',
            // Sostenibilidad
            'ZZZAREAACTIVA', 'ZZZAREANO',
            // Recursos: 7+8+9 ingenieros; maquinaria tipo 8 con su valor (antes repetía la cantidad); instalación; inventario
            'Ingenieros', '24', 'Servicios a pozos e instalaciones petroleras', '51 - 100', '100.001 - 1.000.000 USD', 'Laboratorios', '456', 'Alquilada',
            'Producto terminado', 'ZZZAUNIDAD', '> 10.000.000 USD',
            // Experiencia (entradas con clave UUID, que ExperienceView no lee)
            'ZZZAEXPERIENCIA0', 'ZZZAEXPERIENCIA5', '2014', '2019', 'ZZZINFRASECTOR', 'ZZZINFRATIPO', 'ZZZINFRASISTEMA', 'ZZZINFRAREGION', 'ZZZINFRAINSTALACION', '1500', '3200',
            // Presencia: oficina y DOS experiencias en el exterior
            'ZZZPAISEXTERIOR', '120', 'ZZZACLIENTEEXTERIOR', 'Contratista principal', 'ZZZASEGUNDOCLIENTE', 'Subcontratista', '> 10.000.001 USD',
            // Gestión
            'ISO 9001:2015 Sistema de Gestión de la Calidad: Sí', 'ISO 17025:2005 Laboratorios de Ensayo y de Calibración: No', 'Otros: ZZZAOTROCALIDAD',
            'OVID: Sí', 'Project Management Professional (PMI): Sí', 'Otros: ZZZAOTROINFO', 'Dun & Bradstreet (registro declarado por la empresa): No',
        ];
        foreach ($expected as $value) {
            $this->assertStringContainsString($value, $text, "value «{$value}»");
        }

        // Nada de otra empresa, ni sugerencias sin confirmar, ni artefactos de las vistas.
        foreach (['ZZZB', 'ZZZASUGERIDA', '["', 'null', 'COVID'] as $absent) {
            $this->assertStringNotContainsString($absent, $text, "absent «{$absent}»");
        }
        $this->assertStringNotContainsString('No hay información registrada', $text, 'a complete company has no empty section');

        // Diagnóstico de la causa: la vista de experiencias devuelve UNA fila vacía para las entradas con clave UUID.
        $this->assertSame([null], DB::table('ExperienceView')->where('id', $a->id)->pluck('ano')->all());
    }

    #[Test]
    public function a_new_or_incomplete_profile_is_usable_and_honest(): void
    {
        $owner = $this->user();
        $minimal = $this->company($owner, ['name' => 'ZZZMINIMA', 'phone' => '+58-212-5550000']);
        $this->fullCompany($this->user(), 'B');

        $text = $this->pdfText($this->hit($owner, $this->url($minimal)), 'minimal_new_company');
        $this->assertStringContainsString('PERFIL COMPLETO DE LA EMPRESA', $text);
        $this->assertStringContainsString('ZZZMINIMA', $text);
        foreach (self::HEADINGS as $heading) {
            $this->assertStringContainsString($heading, $text, "heading «{$heading}»");
        }
        $this->assertGreaterThanOrEqual(12, substr_count($text, 'No hay información registrada.'));
        $this->assertStringNotContainsString(EmpresaModuleStatus::NO_APLICA_LABEL_LARGO, $text, 'nothing was declared not applicable');
        $this->assertStringNotContainsString('ZZZB', $text);
        $this->assertStringNotContainsString(': Sí', $text, 'no certification is invented');
    }

    #[Test]
    public function no_aplica_is_shown_as_declared_and_a_single_contact_is_listed(): void
    {
        $owner = $this->user();
        $empresa = $this->company($owner, ['name' => 'ZZZNOAPLICA']);
        $contact = Contact::create(['name' => 'ZZZUNICOCONTACTO', 'position' => 'ZZZCARGOUNICO', 'email' => 'unico@zzz.test']);
        DB::table('contact_empresa')->insert(['contact_id' => $contact->id, 'empresa_id' => $empresa->id, 'is_principal' => false, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([EmpresaModuleStatus::MODULE_EXPERIENCIAS, EmpresaModuleStatus::MODULE_PRESENCIA, EmpresaModuleStatus::MODULE_SOSTENIBILIDAD, EmpresaModuleStatus::MODULE_GESTION] as $module) {
            EmpresaModuleStatus::setStatus($empresa->id, $module, true);
        }
        EmpresaModuleStatus::setStatus($empresa->id, EmpresaModuleStatus::MODULE_RECURSOS, true, 'machinery');
        $this->actingAs($owner);
        Asset::create(['empresa_id' => $empresa->id, 'employee' => [['employee_type' => 0, 'junior_q' => 2, 'medium_q' => 0, 'senior_q' => 1]]]);

        $text = $this->pdfText($this->hit($owner, $this->url($empresa)), 'no_aplica_company');
        // Experiencia, presencia, sostenibilidad, gestión y maquinaria: marcadas por el afiliado.
        $this->assertSame(5, substr_count($text, EmpresaModuleStatus::NO_APLICA_LABEL_LARGO));
        $this->assertStringContainsString('Bachilleres', $text);
        // Instalaciones e inventario no se marcaron ni tienen datos: se dice eso, no «no aplica». Desde Recursos hasta el final
        // solo esas dos dicen «No hay información registrada.»: experiencia, presencia y gestión están marcadas.
        $recursos = (string) strstr($text, 'Recursos en Venezuela Recursos humanos');
        $this->assertNotSame('', $recursos);
        $this->assertSame(2, substr_count($recursos, 'No hay información registrada.'));
        // Con UN solo contacto, el contacto aparece (antes la lista quedaba vacía).
        $this->assertStringContainsString('ZZZUNICOCONTACTO', $text);
        $this->assertStringContainsString('ZZZCARGOUNICO', $text);
    }

    #[Test]
    public function optional_sections_are_allowlisted_and_never_change_the_company_or_the_access(): void
    {
        $ownerA = $this->user();
        $a = $this->fullCompany($ownerA, 'A');
        $b = $this->fullCompany($ownerB = $this->user(), 'B');

        // Subconjunto explícito: solo esas secciones, el PDF lo dice, y el archivo se llama «-parcial».
        $r = $this->hit($ownerA, $this->url($a, ['secciones' => 'generales,recursos']));
        $this->assertStringContainsString('perfil-'.strtolower($a->rif).'-parcial.pdf', (string) $r->headers->get('Content-Disposition'));
        $text = $this->pdfText($r, 'selected_sections');
        $this->assertStringContainsString('PERFIL DE LA EMPRESA — SECCIONES SELECCIONADAS', $text);
        $this->assertStringContainsString('Secciones incluidas: Datos generales y contactos, Recursos en Venezuela.', $text);
        $this->assertStringContainsString('Secciones omitidas por elección del usuario: Clientes, Cámaras,', $text);
        $this->assertStringContainsString('Este documento no es el perfil completo.', $text);
        foreach (['ZZZANOMBRE', 'ZZZACONTACTOUNO', 'Servicios a pozos e instalaciones petroleras', 'ZZZAUNIDAD'] as $value) {
            $this->assertStringContainsString($value, $text);
        }
        foreach (['ZZZACLIENTE ', 'ZZZCAMARACENTINELA', 'ZZZAEXPERIENCIA', 'ZZZACLIENTEEXTERIOR', 'OVID: Sí', 'ZZZAREAACTIVA', 'ZZZACATEGORIACPV'] as $value) {
            $this->assertStringNotContainsString($value, $text);
        }

        // Forma de arreglo.
        $text = $this->pdfText($this->hit($ownerA, $this->url($a).'?secciones[]=gestion'), 'selected_array_form');
        $this->assertStringContainsString('OVID: Sí', $text);
        $this->assertStringNotContainsString('ZZZACALLE', $text);

        // Valores desconocidos, maliciosos o demasiado largos: se ignoran y sale el perfil COMPLETO.
        foreach (['zzz,<script>alert(1)</script>,../../etc/passwd', str_repeat('generales,', 60), 'GENERALES;DROP'] as $i => $bad) {
            $r = $this->hit($ownerA, $this->url($a, ['secciones' => $bad]));
            $this->assertStringContainsString('perfil-'.strtolower($a->rif).'.pdf', (string) $r->headers->get('Content-Disposition'), "bad #{$i}");
            $text = $this->pdfText($r, "ignored_selection_{$i}");
            $this->assertStringContainsString('PERFIL COMPLETO DE LA EMPRESA', $text);
            $this->assertStringContainsString('ZZZAEXPERIENCIA0', $text);
            $this->assertStringNotContainsString('<script>', $text);
        }
        $this->assertSame(array_keys(CompanyDossier::SECTIONS), CompanyDossier::sectionsFrom(['generales' => ['x'], 7, null]));
        $this->assertSame(['generales', 'gestion'], CompanyDossier::sectionsFrom(' Gestion , generales,gestion '));

        // Un `empresa` en el query string no cambia la empresa de la ruta.
        $text = $this->pdfText($this->hit($ownerA, $this->url($a).'?secciones=generales&empresa='.$b->id), 'query_cannot_switch_company');
        $this->assertStringContainsString('ZZZANOMBRE', $text);
        $this->assertStringNotContainsString('ZZZB', $text);

        // La selección no abre ningún acceso: la otra empresa sigue siendo el mismo 404, sin generar nada.
        $reference = $this->hit($ownerA, $this->url($b))->assertNotFound()->getContent();
        foreach (['generales', 'generales,recursos', 'zzz'] as $sel) {
            $r = $this->hit($ownerA, $this->url($b, ['secciones' => $sel]));
            $r->assertNotFound();
            $this->assertSame($reference, $r->getContent());
            $this->assertSame(0, $this->viewQueries, "denied {$sel}: no report-view query");
            $this->assertSame(0, $this->pdfRenders, "denied {$sel}: no render");
        }
        $this->hit(null, $this->url($a, ['secciones' => 'generales']))->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
        $this->assertSame(0, $this->pdfRenders);
        $this->hit($ownerB, $this->url($b, ['secciones' => 'generales']))->assertOk();
    }

    #[Test]
    public function the_list_shows_a_real_full_profile_action_separate_from_the_listing_export(): void
    {
        $owner = $this->user();
        $a = $this->fullCompany($owner, 'A');

        $list = Livewire::actingAs($owner)->test(ListEmpresas::class)->call('loadTable');
        $list->assertTableActionExists('perfil_completo')
            ->assertTableActionVisible('perfil_completo', $a)
            ->assertTableActionHasLabel('perfil_completo', 'Perfil completo (PDF)')
            ->assertTableActionHasUrl('perfil_completo', $this->url($a), $a)
            ->assertTableActionVisible('perfil_secciones', $a)
            ->assertTableBulkActionExists('export')
            ->assertTableBulkActionHasLabel('export', 'Exportar listado')
            ->assertSee('Perfil completo (PDF)');
        // El panel usa ->spa(): sin la excepción, el enlace se abría con wire:navigate y el archivo nunca se descargaba.
        // Panel::boot() pasa estos dos ajustes a FilamentView en cada petición; aquí se aplican igual, sin el resto del boot.
        $panel = Filament::getPanel('admin');
        $this->assertTrue($panel->hasSpaMode());
        \Filament\Support\Facades\FilamentView::spa($panel->hasSpaMode());
        \Filament\Support\Facades\FilamentView::spaUrlExceptions($panel->getSpaUrlExceptions());
        $this->assertFalse(\Filament\Support\Facades\FilamentView::hasSpaMode($this->url($a)), 'the download link bypasses SPA navigation');
        $this->assertTrue(\Filament\Support\Facades\FilamentView::hasSpaMode(route('filament.admin.resources.empresas.index')), 'the rest of the panel keeps SPA mode');

        // «Elegir secciones»: «Todas» lleva al perfil completo; un subconjunto, a ?secciones= con la lista blanca.
        $list->callTableAction('perfil_secciones', $a, data: ['alcance' => 'todas'])->assertRedirect($this->url($a));
        // La lista se REEMPLAZA (callTableAction(data:) la mezclaría con las 10 marcadas por defecto).
        $choose = fn (array $sections) => $list->mountTableAction('perfil_secciones', $a)
            ->set('mountedTableActionsData.0.alcance', 'elegir')
            ->set('mountedTableActionsData.0.secciones', $sections)
            ->callMountedTableAction();
        $choose(['gestion', 'generales'])->assertRedirect($this->url($a, ['secciones' => 'generales,gestion']));
        $choose([])->assertHasTableActionErrors(['secciones']);

        // También desde la ficha de la empresa.
        Livewire::actingAs($owner)->test(EditEmpresa::class, ['record' => $a->getRouteKey()])
            ->assertActionVisible('perfil_completo')
            ->assertActionHasLabel('perfil_completo', 'Descargar perfil completo (PDF)')
            ->assertActionHasUrl('perfil_completo', $this->url($a));
    }

    #[Test]
    public function the_manual_explains_the_full_profile_the_optional_sections_and_the_listing_export(): void
    {
        $html = app(\App\Support\UserManual\UserManualRenderer::class)->render()['html'];
        $start = strpos($html, 'id="descargar-perfil"');
        $this->assertNotFalse($start);
        $section = substr($html, $start, (strpos($html, '<h3 ', $start + 1) ?: strlen($html)) - $start);

        // Las mismas etiquetas que la interfaz (EmpresaResource, EditEmpresa) y que el PDF.
        foreach (['Perfil completo (PDF)', 'Descargar perfil completo (PDF)', 'Elegir secciones', 'Todas las secciones (perfil completo)',
            'Solo las secciones que elija', 'Descargar PDF', '-parcial.pdf', 'Exportar listado', 'no el perfil completo',
            EmpresaModuleStatus::NO_APLICA_LABEL_LARGO, 'no es una calificación crediticia', 'Archivo no disponible'] as $text) {
            $this->assertStringContainsString($text, $section, "manual: «{$text}»");
        }
        $this->assertStringNotContainsString('columna <strong>PDF</strong>', $html, 'the invisible PDF column is gone from the manual');
    }
}
