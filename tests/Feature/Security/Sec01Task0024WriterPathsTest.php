<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/**
 * TASK-0024 (Issue #2 `6848913937`, decisions `6849259532`, reservation R-05): AUDIT ONLY, synthetic fixtures in the
 * disposable PostgreSQL of the isolated runner. It inventories the application paths that write roles, role
 * memberships and permissions, and separates:
 * - controls that must hold (expected green);
 * - a reproduction of a gap found while inventorying (expected RED on `270e07d` until a fix is assigned);
 * - the documented limit of the R4 model-event guard (query-level and quiet writes bypass it), which is an INTERNAL
 *   capability: no application path uses it, and a static inventory keeps it that way.
 * Nothing here runs against staging and nothing is a fix.
 */
class Sec01Task0024WriterPathsTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private string $superName;

    private Role $super;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->superName = config('filament-shield.super_admin.name');
        $this->super = Role::findOrCreate($this->superName, 'web');
    }

    private function user(array $permissions = [], array $roles = []): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'web'));
        }
        foreach ($roles as $r) {
            $user->assignRole($r);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function attempt(callable $call): void
    {
        try {
            $call();
        } catch (HttpExceptionInterface|AuthorizationException|ModelNotFoundException) {
            // denied; state asserted by the caller
        }
    }

    private function privilegedMembershipExists(User $user): bool
    {
        return DB::table('model_has_roles')
            ->where('role_id', $this->super->getKey())
            ->where('model_type', $user->getMorphClass())
            ->where('model_id', $user->getKey())
            ->exists();
    }

    // ------------------------------------------------------------------ reproduction (expected RED on 270e07d)

    /**
     * R2's rule: a non-super-admin can neither add nor REMOVE a privileged role, and cannot open (so modify) a
     * privileged account. The list's row action «Eliminar» is gated only by `delete_user` (UserPolicy::delete has no
     * target), and deleting the account detaches its privileged role. Expected: refused, account and role intact.
     */
    #[Test]
    public function a_user_manager_cannot_delete_a_privileged_account_from_the_list(): void
    {
        $manager = $this->user(['view_any_user', 'view_user', 'delete_user']);
        $admin = $this->user(roles: [$this->super]);

        $this->attempt(fn () => Livewire::actingAs($manager)->test(ListUsers::class)->callTableAction('delete', $admin));

        $this->assertNotNull(User::find($admin->getKey()), 'the privileged account still exists');
        $this->assertTrue($this->privilegedMembershipExists($admin), 'its privileged role membership is intact');
    }

    // ------------------------------------------------------------------ controls (expected green)

    /** Positive control for the reproduction: the same actor and action DO delete an ordinary account. */
    #[Test]
    public function the_same_user_manager_deletes_an_ordinary_account_from_the_list(): void
    {
        $manager = $this->user(['view_any_user', 'view_user', 'delete_user']);
        $ordinary = $this->user();

        Livewire::actingAs($manager)->test(ListUsers::class)->callTableAction('delete', $ordinary);

        $this->assertNull(User::find($ordinary->getKey()), 'the ordinary account was deleted');
    }

    /** The edit page's «Eliminar» on a privileged account is unreachable for a non-super-admin (mount guard). */
    #[Test]
    public function the_edit_page_delete_of_a_privileged_account_is_refused(): void
    {
        $manager = $this->user(['view_any_user', 'view_user', 'update_user', 'delete_user']);
        $admin = $this->user(roles: [$this->super]);

        $this->attempt(fn () => Livewire::actingAs($manager)->test(EditUser::class, ['record' => $admin->getKey()])->callAction('delete'));

        $this->assertNotNull(User::find($admin->getKey()));
        $this->assertTrue($this->privilegedMembershipExists($admin));
    }

    /** Write boundary after hydrate: a target that becomes privileged after the page was mounted is refused at save. */
    #[Test]
    public function a_target_promoted_after_mount_is_refused_at_save(): void
    {
        $manager = $this->user(['view_any_user', 'view_user', 'update_user']);
        $target = $this->user();
        $page = Livewire::actingAs($manager)->test(EditUser::class, ['record' => $target->getKey()]);

        $target->assignRole($this->super); // concurrent promotion by a super admin
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->attempt(fn () => $page->fillForm(['name' => 'zzz-t24-renamed', 'roles' => []])->call('save'));

        $this->assertTrue($this->privilegedMembershipExists($target), 'the privileged role was not removed');
        $this->assertNotSame('zzz-t24-renamed', User::find($target->getKey())->name, 'the privileged account was not modified');
    }

    // ------------------------------------------------------------------ R4 limit (internal capability, documented)

    /**
     * PrivilegedRoleDefinitionGuard listens to Eloquent model events. An ordinary model write is refused; query-level,
     * quiet and event-less writes are not seen by it. This test pins that limit (green = limit confirmed) so a future
     * change is visible; the static inventory below shows no application path uses these writers on role tables.
     */
    #[Test]
    public function r4_guard_refuses_model_writes_but_not_query_level_or_quiet_writes(): void
    {
        $id = $this->super->getKey();
        $name = fn () => DB::table('roles')->where('id', $id)->value('name');

        $refused = false;
        try {
            Role::find($id)->update(['name' => 'zzz-t24-model']);
        } catch (AuthorizationException) {
            $refused = true;
        }
        $this->assertTrue($refused, 'model update refused');
        $this->assertSame($this->superName, $name());

        $bypasses = [
            'query builder update' => fn () => Role::query()->whereKey($id)->update(['name' => 'zzz-t24-q']),
            'saveQuietly' => function () use ($id) { $r = Role::find($id); $r->name = 'zzz-t24-quiet'; $r->saveQuietly(); },
            'withoutEvents' => fn () => Role::withoutEvents(fn () => Role::find($id)->update(['name' => 'zzz-t24-noevents'])),
            'DB::table update' => fn () => DB::table('roles')->where('id', $id)->update(['name' => 'zzz-t24-db']),
        ];
        foreach ($bypasses as $label => $write) {
            $write();
            $this->assertNotSame($this->superName, $name(), "$label is not seen by the model-event guard (internal capability)");
            DB::table('roles')->where('id', $id)->update(['name' => $this->superName]);
        }
    }

    /**
     * Static inventory of application code (app/ and routes/): no raw or event-less writer targets the role,
     * permission or membership tables. The guarded writers are the Shield role editor (RolePolicy + R4-01 sync) and
     * the user form's role relationship (PrivilegedRoleGuard). Console seeders and vendor commands are out of scope.
     */
    #[Test]
    public function no_application_code_writes_role_tables_outside_the_guarded_paths(): void
    {
        $tables = '(roles|permissions|model_has_roles|model_has_permissions|role_has_permissions)';
        $patterns = [
            'query builder on a role table' => "/DB::(?:connection\\([^)]*\\)->)?table\\(\\s*['\"]{$tables}['\"]\\s*\\)[^;]*->(insert|update|delete|upsert|truncate|insertOrIgnore|updateOrInsert)\\s*\\(/s",
            'raw SQL writing a role table' => "/DB::(?:connection\\([^)]*\\)->)?(insert|update|delete|statement|unprepared|affectingStatement)\\s*\\(\\s*['\"][^'\"]*\\b(insert\\s+into|update|delete\\s+from|truncate(\\s+table)?)\\s+\"?(public\\.)?{$tables}\\b/is",
            'Role/Permission query-level write' => '/\b(Role|Permission)::(query\(\)|where\w*\([^;]*\))\s*->\s*(update|delete|forceDelete)\s*\(/',
            'withoutEvents' => '/withoutEvents\s*\(/',
        ];
        $hits = [];
        foreach (['app', 'routes'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $src = file_get_contents($file->getPathname());
                foreach ($patterns as $label => $re) {
                    if (preg_match($re, $src, $m)) {
                        $hits[] = $label.': '.str_replace(base_path().'/', '', $file->getPathname()).' ['.trim(substr($m[0], 0, 80)).']';
                    }
                }
            }
        }
        $this->assertSame([], $hits, "unguarded writers found:\n".implode("\n", $hits));
    }
}
