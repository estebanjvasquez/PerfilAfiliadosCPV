<?php

/*
 * CPV-COMPLETE-COMPANY-PDF (instruction 6270558322): synthetic setup for the full-profile PDF browser check.
 *
 * - The PostgreSQL report views the PDF reads, from the app's own test fixture (tests/Fixtures/pgsql_report_views,
 *   staging-identical definitions), created in this disposable database.
 * - The synthetic affiliate gets `view_any_empresa`/`view_empresa` and keeps its company (A).
 * - Company A gets synthetic data in EVERY profile module (sentinels ZZZUX…), so the downloaded file is a full dossier.
 * - The synthetic admin («authorized staff») is linked to A and gets `view_empresa`: the same rule as everyone else.
 * - A second synthetic company (B), linked only to the synthetic admin.
 * Prints A_ID/B_ID (synthetic ids of this disposable stack) for the browser script.
 *
 * Runs only inside the disposable stack: it refuses unless APP_ENV=testing and pgsql is testdb/cpv_test.
 */

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$err = fopen('php://stderr', 'w');

$cfg = config('database.connections.pgsql');
if (app()->environment() !== 'testing' || ($cfg['host'] ?? null) !== 'testdb' || ($cfg['database'] ?? null) !== 'cpv_test') {
    fwrite($err, "REFUSING: not the disposable test database\n");
    exit(3);
}

$affiliate = App\Models\User::where('email', 'zzz-ux-affiliate@example.test')->first();
$admin = App\Models\User::where('email', 'zzz-ux-admin@example.test')->first();
$a = App\Models\Empresa::whereRaw('upper(name) like ?', ['ZZZ UX EMPRESA FICTICIA%'])->first()
    ?? App\Models\Empresa::where('rif', 'like', 'J%000000009')->first();
if (! $affiliate || ! $admin || ! $a) {
    fwrite($err, "synthetic affiliate, admin or company missing\n");
    exit(4);
}

try {
    $db = Illuminate\Support\Facades\DB::connection('pgsql');
    foreach (glob('tests/Fixtures/pgsql_report_views/*.php') as $file) {
        (require $file)->up();
    }

    foreach ([$affiliate, $admin] as $user) {
        foreach (['view_any_empresa', 'view_empresa'] as $permission) {
            $user->givePermissionTo(Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
        }
    }
    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    $db->table('empresa_user')->insertOrIgnore(['empresa_id' => $a->id, 'user_id' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);

    $now = ['created_at' => now(), 'updated_at' => now()];
    $country = $db->table('countries')->insertGetId(['country_name' => 'ZZZUXPAIS'] + $now);
    $state = $db->table('states')->insertGetId(['country_id' => $country, 'state_name' => 'ZZZUXESTADO'] + $now);
    $city = $db->table('cities')->insertGetId(['country_id' => $country, 'states_id' => $state, 'city_name' => 'ZZZUXCIUDAD'] + $now);
    $sector = $db->table('sectors')->insertGetId(['name' => 'ZZZUXSECTOR'] + $now);
    $service = $db->table('services')->insertGetId(['sectors_id' => $sector, 'name' => 'ZZZUXSERVICIO'] + $now);
    $infrasector = $db->table('infrasectors')->insertGetId(['sector_name' => 'ZZZUXINFRASECTOR'] + $now);
    $area = $db->table('areas')->insertGetId(['sust_title' => 'ZZZUXAREA', 'sust_description' => 'Área sintética'] + $now);
    $chamber = $db->table('chambers')->insertGetId(['name' => 'ZZZUXCAMARA'] + $now);

    $a->forceFill([
        'street' => 'ZZZUXCALLE 1', 'city_id' => $city, 'website' => 'https://zzzux.test', 'ano_fund' => 1990, 'billing_id' => 2, 'employees_id' => 1,
        'status_id' => 1, 'property_id' => 1, 'origin_id' => 1, 'sector_principal_id' => $sector, 'linkedin_profile' => 'linkedin.com/company/zzzux',
        'customers_country' => [['customer_name' => 'ZZZUXCLIENTE', 'country_id' => (string) $country]],
    ])->save();

    $contact = App\Models\Contact::create(['name' => 'ZZZUXCONTACTO', 'position' => 'ZZZUXCARGO', 'phone' => '+58-414-0000000', 'email' => 'contacto@zzzux.test']);
    $db->table('contact_empresa')->insert(['contact_id' => $contact->id, 'empresa_id' => $a->id, 'is_principal' => true] + $now);
    $db->table('chamber_empresa')->insert(['empresa_id' => $a->id, 'chamber_id' => $chamber] + $now);
    $db->table('empresa_sector_service')->insertOrIgnore(['empresa_id' => $a->id, 'service_id' => $service] + $now);

    $grupo = App\Models\TaxonomyCategory::create(['code' => 'CPV-UX'.random_int(100, 999), 'parent_id' => null, 'is_active' => true, 'source_version' => 'ux-synthetic-dossier']);
    $hoja = App\Models\TaxonomyCategory::create(['code' => $grupo->code.'.10', 'parent_id' => $grupo->id, 'is_active' => true, 'source_version' => 'ux-synthetic-dossier']);
    App\Models\TaxonomyCategoryTranslation::create(['category_id' => $grupo->id, 'locale' => 'es', 'name' => 'ZZZUXGRUPO']);
    App\Models\TaxonomyCategoryTranslation::create(['category_id' => $hoja->id, 'locale' => 'es', 'name' => 'ZZZUXCATEGORIA']);
    App\Models\EmpresaTaxonomyCategory::create(['empresa_id' => $a->id, 'category_id' => $hoja->id, 'origen' => 'self_declared', 'es_principal' => true]);

    Illuminate\Support\Facades\Auth::login($affiliate);
    App\Models\Sustainability::create(['empresa_id' => $a->id, 'areas_id' => $area, 'sust_status' => 2]);
    App\Models\Asset::create([
        'empresa_id' => $a->id,
        'employee' => [['employee_type' => 1, 'junior_q' => 3, 'medium_q' => 4, 'senior_q' => 5]],
        'machinery' => [['machinery_name' => 2, 'machinery_qid' => 1, 'machinery_est' => 1]],
        'facility' => [['facility_type' => 0, 'facility_q' => 1, 'facility_surf' => 300, 'facility_own' => 1]],
        'inventory' => [['inventory_name' => 0, 'inventory_q' => 1, 'inventory_unit' => 'ZZZUXUNIDAD', 'inventory_est' => 1]],
    ]);
    $entries = [];
    foreach ([2018, 2021] as $year) {
        $entries[(string) Illuminate\Support\Str::uuid()] = ['type' => 'Experiencia Relevante', 'data' => [
            'exp_year' => (string) $year, 'infrasectors_id' => $infrasector, 'magnitud' => '3', 'sectors_id' => $sector, 'services_id' => [$service],
            'Descripcion' => "ZZZUXEXPERIENCIA{$year}",
        ]];
    }
    App\Models\Experience::create(['empresa_id' => $a->id, 'exp_year' => $entries]);
    App\Models\Presence::create(['empresa_id' => $a->id, 'has_offices' => false, 'office_data' => [], 'has_experience' => true,
        'experience_data' => [['expcountry_id' => $country, 'projects_q' => 2, 'role' => '3', 'executed_q' => '2', 'expemployees_q' => 6, 'main_clients' => 'ZZZUXCLIENTEEXTERIOR']]]);
    App\Models\Management::create(['empresa_id' => $a->id, 'iso9001' => true, 'ovid' => true, 'pmi' => false]);
    Illuminate\Support\Facades\Auth::logout();

    Illuminate\Support\Facades\Auth::login($admin);
    $b = App\Models\Empresa::create(['rif' => 'J000000017', 'name' => 'ZZZ UX EMPRESA AJENA', 'phone' => '+58-212-5550001']);
    Illuminate\Support\Facades\Auth::logout();
} catch (Throwable $e) {
    fwrite($err, 'seed failed: '.get_class($e).': '.strtok($e->getMessage(), "\n")."\n");
    exit(5);
}

$views = $db->selectOne("select count(*) as n from pg_class c join pg_namespace n on n.oid=c.relnamespace where n.nspname='public' and c.relkind='v' and c.relname ilike '%View'")->n;
echo 'A_ID=', $a->id, "\n", 'B_ID=', $b->id, "\n", 'A_RIF=', strtolower($a->rif), "\n";
echo 'report views=', $views, ' affiliate linked to A=', (int) $db->table('empresa_user')->where('empresa_id', $a->id)->where('user_id', $affiliate->id)->exists(), "\n";
echo "seed done\n";
