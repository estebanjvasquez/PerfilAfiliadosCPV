<?php

/*
 * CPV-VERIFICATION-FLOW-AND-COMPLETION (instruction 6215451160): synthetic setup for the browser check.
 *
 * - An UNVERIFIED synthetic user (zzz-ux-unverified@example.test) with the same password as the synthetic
 *   affiliate (its hash is copied; this script never sees the password), no role and no company.
 * - One signed verification link for that user, signed for the host the browser uses
 *   (CPV_UX_BASE_URL, set by the runner). It is printed as VERIFY_LINK=… so the browser script can read
 *   it from /out/ux_seed.log. The APP_KEY is ephemeral to this run, so the link is worthless afterwards;
 *   the line is still redacted before any evidence is committed.
 * - The synthetic affiliate's company gets real Gestión data (ISO 9001), so the check can show that
 *   toggling «No Aplica» keeps it. Shield permissions the module policies ask for.
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
$base = getenv('CPV_UX_BASE_URL');
if (! $base) {
    fwrite($err, "CPV_UX_BASE_URL missing\n");
    exit(4);
}

$affiliate = App\Models\User::where('email', 'zzz-ux-affiliate@example.test')->first();
$empresa = App\Models\Empresa::whereRaw('upper(name) like ?', ['ZZZ UX EMPRESA FICTICIA%'])->first()
    ?? App\Models\Empresa::where('rif', 'like', 'J%000000009')->first();
if (! $affiliate || ! $empresa) {
    fwrite($err, "synthetic affiliate or company missing\n");
    exit(5);
}

$permissions = ['view_any_empresa', 'view_empresa', 'update_empresa', 'view_any_management', 'view_management', 'create_management', 'update_management'];
foreach ($permissions as $permission) {
    $affiliate->givePermissionTo(Spatie\Permission\Models\Permission::findOrCreate($permission, 'web'));
}
app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

$unverified = App\Models\User::firstOrNew(['email' => 'zzz-ux-unverified@example.test']);
$unverified->forceFill(['name' => 'ZZZ UX Sin Verificar', 'password' => $affiliate->password, 'email_verified_at' => null])->save();

// Real Gestión data, created as the affiliate (the model hooks put the actor in the INSERT).
Illuminate\Support\Facades\Auth::login($affiliate);
$management = $empresa->management()->first() ?? $empresa->management()->create([]);
$management->update(['iso9001' => true]);
Illuminate\Support\Facades\Auth::logout();

Illuminate\Support\Facades\URL::forceRootUrl($base);
echo 'VERIFY_LINK=', Filament\Facades\Filament::getPanel('admin')->getVerifyEmailUrl($unverified), "\n";
echo 'unverified user id=', $unverified->id, ' roles=', $unverified->getRoleNames()->count(), ' companies=', $unverified->empresas()->count(), "\n";
echo 'empresa id=', $empresa->id, ' gestion iso9001=', (int) $management->fresh()->iso9001,
    ' cached completion=', (int) Illuminate\Support\Facades\DB::connection()->table('empresas')->where('id', $empresa->id)->value('completion_percentage'), "\n";
echo "seed done\n";
