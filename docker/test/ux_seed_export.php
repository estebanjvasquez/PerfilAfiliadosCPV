<?php

/*
 * CPV-EXPORT-ACCESS-REPAIR-AND-ERROR-PAGES (instruction 6264837159): synthetic setup for the export browser check.
 *
 * - The PostgreSQL report views the PDF/XLS read, from the app's own test fixture
 *   (tests/Fixtures/pgsql_report_views, staging-identical definitions), created in this disposable database.
 * - The synthetic affiliate gets `view_any_empresa`/`view_empresa` and keeps its company (A).
 * - A second synthetic company (B), linked only to the synthetic admin.
 * - An UNVERIFIED synthetic user with the affiliate's password hash, linked to A.
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

$db = Illuminate\Support\Facades\DB::connection('pgsql');
foreach (glob('tests/Fixtures/pgsql_report_views/*.php') as $file) {
    (require $file)->up();
}

foreach (['view_any_empresa', 'view_empresa'] as $permission) {
    $affiliate->givePermissionTo(Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
}
app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

Illuminate\Support\Facades\Auth::login($admin);
$b = App\Models\Empresa::create(['rif' => 'J000000017', 'name' => 'ZZZ UX EMPRESA AJENA', 'phone' => '+58-212-5550001']);
Illuminate\Support\Facades\Auth::logout();

$unverified = App\Models\User::firstOrNew(['email' => 'zzz-ux-unverified@example.test']);
$unverified->forceFill(['name' => 'ZZZ UX Sin Verificar', 'password' => $affiliate->password, 'email_verified_at' => null])->save();
$db->table('empresa_user')->insertOrIgnore(['empresa_id' => $a->id, 'user_id' => $unverified->id, 'created_at' => now(), 'updated_at' => now()]);

$views = $db->selectOne("select count(*) as n from pg_class c join pg_namespace n on n.oid=c.relnamespace where n.nspname='public' and c.relkind='v' and c.relname ilike '%View'")->n;
echo 'A_ID=', $a->id, "\n", 'B_ID=', $b->id, "\n";
echo 'report views=', $views, ' affiliate linked to A=', (int) $db->table('empresa_user')->where('empresa_id', $a->id)->where('user_id', $affiliate->id)->exists(), "\n";
echo "seed done\n";
