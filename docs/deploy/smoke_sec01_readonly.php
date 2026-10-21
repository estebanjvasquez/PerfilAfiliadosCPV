<?php

/**
 * CPV-SEC-01-R1-R2-DEPLOY (Issue #2 `6316265832`): read-only post-deploy checks for F-SEC01-01 (impersonation gate),
 * F-SEC01-02a (pre-sync role authorization), R2-REV-05 (account write transaction) and F-SEC01-04 (removed `/form`).
 *
 * Writes nothing: everything runs inside ONE pgsql transaction set READ ONLY and rolled back; users are in-memory
 * models only (never saved); no request is sent, no account is created, edited or impersonated. Prints only check
 * names, booleans and connection NAMES (never credentials or ids).
 *
 * Run from the app root inside the container:  docker compose exec -T -u www-data app php < this-file
 * "source" checks read the deployed code; "exec" checks run the deployed code in memory.
 */

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

$results = [];
$check = function (string $kind, string $name, callable $fn) use (&$results) {
    try {
        $ok = (bool) $fn();
    } catch (\Throwable $e) {
        $ok = false;
        $name .= ' [exception: '.get_class($e).']';
    }
    $results[] = [$ok, $kind, $name];
};

$conn = DB::connection();
$conn->beginTransaction();
$conn->statement('SET TRANSACTION READ ONLY');

try {
    $superName = config('filament-shield.super_admin.name');
    $roleModel = config('permission.models.role');
    $userConn = (new App\Models\User)->getConnection()->getName();
    $roleConn = (new $roleModel)->getConnection()->getName();
    echo "connections: account={$userConn} role/pivot={$roleConn}\n";

    $check('config', 'app.debug is off', fn () => config('app.debug') === false);
    $check('config', 'maintenance mode is off', fn () => ! app()->isDownForMaintenance());
    $check('config', 'account and role/pivot resolve to the SAME connection', fn () => $userConn === $roleConn);

    // F-SEC01-01
    $check('source', 'User::canImpersonate() is installed', fn () => method_exists(App\Models\User::class, 'canImpersonate'));
    $check('exec', 'in-memory user without roles cannot impersonate', function () use ($roleModel) {
        $u = new App\Models\User;
        $u->setRelation('roles', collect());
        return $u->canImpersonate() === false;
    });
    $check('exec', 'in-memory user with an ordinary role cannot impersonate', function () use ($roleModel) {
        $u = new App\Models\User;
        $u->setRelation('roles', collect([new $roleModel(['name' => 'zzz-ordinary', 'guard_name' => 'web'])]));
        return $u->canImpersonate() === false;
    });
    $check('exec', 'in-memory user with the super admin role can impersonate', function () use ($roleModel, $superName) {
        $u = new App\Models\User;
        $u->setRelation('roles', collect([new $roleModel(['name' => $superName, 'guard_name' => 'web'])]));
        return $u->canImpersonate() === true;
    });

    // F-SEC01-02a
    $check('source', 'UserResource roles field syncs through PrivilegedRoleGuard::authorizedRoleIds (pre-sync)', function () {
        $src = file_get_contents(app_path('Filament/Resources/UserResource.php'));
        return str_contains($src, 'saveRelationshipsUsing') && str_contains($src, 'PrivilegedRoleGuard::authorizedRoleIds');
    });
    $check('exec', 'without a super admin actor, a requested privileged role is removed before sync', function () {
        auth()->logout();
        $privileged = App\Support\Security\PrivilegedRoleGuard::privilegedRoleIds();
        if ($privileged === []) {
            return false;
        }
        $authorized = App\Support\Security\PrivilegedRoleGuard::authorizedRoleIds(array_merge($privileged, [999999999]), null);
        return $authorized === [999999999];
    });

    // R2-REV-05
    foreach ([App\Filament\Resources\UserResource\Pages\CreateUser::class, App\Filament\Resources\UserResource\Pages\EditUser::class] as $page) {
        $short = class_basename($page);
        $check('source', "{$short} uses UsesAccountWriteTransaction", fn () => in_array(
            App\Filament\Resources\UserResource\Concerns\UsesAccountWriteTransaction::class, class_uses($page), true));
        $check('exec', "{$short}::hasDatabaseTransactions() is true", fn () => (new $page)->hasDatabaseTransactions() === true);
        $check('exec', "{$short} transaction covers exactly the account connection", fn () => $page::accountWriteConnections() === [$userConn]);
    }

    // F-SEC01-04
    $check('exec', 'no /form route is registered', function () {
        foreach (Route::getRoutes() as $route) {
            if (trim($route->uri(), '/') === 'form') {
                return false;
            }
        }
        return true;
    });
    $check('source', 'legacy App\\Http\\Livewire\\Form class is absent', fn () => ! class_exists('App\\Http\\Livewire\\Form'));
} finally {
    $conn->rollBack();
}

$passed = 0;
foreach ($results as [$ok, $kind, $name]) {
    $passed += $ok ? 1 : 0;
    printf("  [%s] (%s) %s\n", $ok ? 'PASS' : 'FAIL', $kind, $name);
}
printf("SEC01 read-only: %d/%d\n", $passed, count($results));
exit($passed === count($results) ? 0 : 1);
