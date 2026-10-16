<?php

/*
 * CPV-FIRST-SAVE-POSTGRES-COMPATIBILITY (instruction 6212967641): synthetic setup for the browser check of
 * the FIRST save of the five profile modules.
 *
 * - Shield permissions the module policies ask for (production keeps them in its database, this stack does
 *   not seed them; super_admin has no gate bypass), for the synthetic affiliate and the synthetic admin.
 * - The synthetic admin is linked to the synthetic company.
 * - Minimal catalogs the forms need: country/state/city, sector, infrastructure sector, two areas.
 * - NO module rows: the check asserts that the company starts with none, and creates them through the forms.
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
// By name, case-insensitive: the model normalizes the RIF and stores the name in upper case.
$empresa = App\Models\Empresa::whereRaw('upper(name) like ?', ['ZZZ UX EMPRESA FICTICIA%'])->first()
    ?? App\Models\Empresa::where('rif', 'like', 'J%000000009')->first();
if (! $affiliate || ! $admin || ! $empresa) {
    fwrite($err, "synthetic affiliate, admin or company missing\n");
    exit(4);
}

Illuminate\Support\Facades\Auth::login($admin);
if (! $empresa->users()->where('users.id', $admin->id)->exists()) {
    $empresa->users()->attach($admin->id);
}

$permissions = [
    'view_any_empresa', 'view_empresa', 'update_empresa',
    'view_any_management', 'view_management', 'create_management', 'update_management',
    'view_any_asset', 'view_asset', 'create_asset', 'update_asset',
    'view_any_experience', 'view_experience', 'create_experience', 'update_experience',
    'view_any_presence', 'view_presence', 'create_presence', 'update_presence', 'delete_presence',
];
foreach ([$affiliate, $admin] as $user) {
    foreach ($permissions as $permission) {
        $user->givePermissionTo(Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
    }
}
app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

$db = Illuminate\Support\Facades\DB::connection();
$now = now();
if ($db->table('cities')->count() === 0) {
    $countryId = $db->table('countries')->insertGetId(['country_name' => 'ZZZ País sintético', 'created_at' => $now, 'updated_at' => $now]);
    $stateId = $db->table('states')->insertGetId(['country_id' => $countryId, 'state_name' => 'ZZZ Estado sintético', 'created_at' => $now, 'updated_at' => $now]);
    $db->table('cities')->insert(['country_id' => $countryId, 'states_id' => $stateId, 'city_name' => 'ZZZ Ciudad sintética', 'created_at' => $now, 'updated_at' => $now]);
}
if ($db->table('sectors')->count() === 0) {
    $db->table('sectors')->insert(['name' => 'ZZZ Sector sintético', 'created_at' => $now, 'updated_at' => $now]);
}
if ($db->table('infrasectors')->count() === 0) {
    $db->table('infrasectors')->insert(['sector_name' => 'ZZZ Infraestructura sintética', 'created_at' => $now, 'updated_at' => $now]);
}
if ($db->table('areas')->count() === 0) {
    $db->table('areas')->insert([
        ['sust_title' => 'ZZZ Área uno', 'sust_description' => 'Área sintética uno', 'created_at' => $now, 'updated_at' => $now],
        ['sust_title' => 'ZZZ Área dos', 'sust_description' => 'Área sintética dos', 'created_at' => $now, 'updated_at' => $now],
    ]);
}

$counts = [];
foreach (['management', 'assets', 'experiences', 'presences', 'sustainabilities'] as $table) {
    $counts[$table] = $db->table($table)->where('empresa_id', $empresa->id)->count();
}
echo 'empresa id=', $empresa->id, ' admin linked=', (int) $empresa->users()->where('users.id', $admin->id)->exists(),
    ' module rows before=', json_encode($counts), "\n";
if (array_sum($counts) !== 0) {
    fwrite($err, "the synthetic company already has module rows\n");
    exit(5);
}
