<?php

/*
 * CPV-MODEL-COMPATIBILITY-AND-LABELS (instruction 6211997650): synthetic access for the browser check of
 * the «Sistemas de gestión» tab (OVID / ISO 9001 / ISO 17025 labels and save).
 *
 * - Shield permissions that production keeps in its database and this stack does not seed (super_admin has
 *   no gate bypass: define_via_gate = false): the company screen and ManagementPolicy, for the synthetic
 *   affiliate and the synthetic admin only.
 * - The synthetic admin is linked to the synthetic company (company visibility is by link).
 * - One synthetic city if there is none (EditEmpresa::getSteps() needs a row, pre-existing).
 * - One `management` row for the synthetic company, inserted WITHOUT Eloquent events: Management::created()
 *   runs `update management set management.user_id = ...`, which PostgreSQL rejects (pre-existing defect
 *   reported in this round, not fixed by it). The check exercises the update path the labels belong to.
 *
 * Runs only inside the disposable stack: it refuses unless APP_ENV=testing and pgsql is testdb/cpv_test.
 */

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cfg = config('database.connections.pgsql');
if (app()->environment() !== 'testing' || ($cfg['host'] ?? null) !== 'testdb' || ($cfg['database'] ?? null) !== 'cpv_test') {
    fwrite(fopen('php://stderr', 'w'), "REFUSING: not the disposable test database\n");
    exit(3);
}

$affiliate = App\Models\User::where('email', 'zzz-ux-affiliate@example.test')->first();
$admin = App\Models\User::where('email', 'zzz-ux-admin@example.test')->first();
// By name, case-insensitive: the model normalizes the RIF and stores the name in upper case (same as
// ux_seed_taxonomy_map.php).
$empresa = App\Models\Empresa::whereRaw('upper(name) like ?', ['ZZZ UX EMPRESA FICTICIA%'])->first()
    ?? App\Models\Empresa::where('rif', 'like', 'J%000000009')->first();
if (! $affiliate || ! $admin || ! $empresa) {
    fwrite(fopen('php://stderr', 'w'), "synthetic affiliate, admin or company missing\n");
    exit(4);
}

Illuminate\Support\Facades\Auth::login($admin);
if (! $empresa->users()->where('users.id', $admin->id)->exists()) {
    $empresa->users()->attach($admin->id);
}

$permissions = ['view_any_empresa', 'view_empresa', 'update_empresa', 'view_any_management', 'view_management', 'create_management', 'update_management'];
foreach ([$affiliate, $admin] as $user) {
    foreach ($permissions as $permission) {
        $user->givePermissionTo(Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
    }
}
app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

$main = Illuminate\Support\Facades\DB::connection();
if ($main->table('cities')->count() === 0) {
    $countryId = $main->table('countries')->insertGetId(['country_name' => 'ZZZ País sintético', 'created_at' => now(), 'updated_at' => now()]);
    $stateId = $main->table('states')->insertGetId(['country_id' => $countryId, 'state_name' => 'ZZZ Estado sintético', 'created_at' => now(), 'updated_at' => now()]);
    $main->table('cities')->insert(['country_id' => $countryId, 'states_id' => $stateId, 'city_name' => 'ZZZ Ciudad sintética', 'created_at' => now(), 'updated_at' => now()]);
}
if (! $main->table('management')->where('empresa_id', $empresa->id)->exists()) {
    $main->table('management')->insert(['empresa_id' => $empresa->id, 'created_at' => now(), 'updated_at' => now()]);
}

echo 'empresa id=', $empresa->id, ' admin linked=', (int) $empresa->users()->where('users.id', $admin->id)->exists(),
    ' management rows=', $main->table('management')->where('empresa_id', $empresa->id)->count(), "\n";
