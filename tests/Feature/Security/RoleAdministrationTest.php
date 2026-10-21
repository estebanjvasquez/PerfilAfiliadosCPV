<?php

namespace Tests\Feature\Security;

use App\Models\User;
use BezhanSalleh\FilamentShield\Resources\RoleResource;
use BezhanSalleh\FilamentShield\Resources\RoleResource\Pages\CreateRole;
use BezhanSalleh\FilamentShield\Resources\RoleResource\Pages\EditRole;
use BezhanSalleh\FilamentShield\Resources\RoleResource\Pages\ListRoles;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/**
 * CPV-SEC-01-R4-ROLE-ADMIN (Issue #2 `6318625636`): role administration is exclusively for a user who CURRENTLY holds
 * the configured super_admin role. The owner's rule, written here as literals, independent of the gate under test:
 * - guest → login; unverified → verification prompt;
 * - an affiliate, a user without permissions, a user manager, a non-super-admin holding every role-administration
 *   permission through a role, and one holding them as direct grants are ALL denied: list, view, create, edit,
 *   permission editor, delete and bulk delete — including direct Livewire calls;
 * - a page or action mounted while the user was super_admin is refused once the role is removed;
 * - a super admin lists, creates, edits (name and permissions) and deletes ordinary disposable roles;
 * - the privileged role's identity (name, guard) cannot be rewritten or deleted, no other role can take its name, and
 *   no role-definition change is possible by a lesser actor; refused attempts leave roles and permission links unchanged.
 * Synthetic users and roles in the disposable PostgreSQL only. Shield's gate bypass is off in this project
 * (`define_via_gate = false`), so the super admin fixture holds the explicit role permissions through its role.
 */
class RoleAdministrationTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    /** The installed role-administration permission set: Shield's resource prefixes on the role identifier. */
    private array $rolePermissions;

    private Role $super;

    private Role $ordinary;

    /** @var array<string, ?User> */
    private array $actors = [];

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->rolePermissions = array_map(fn ($p) => $p.'_role', config('filament-shield.permission_prefixes.resource'));
        $perms = array_map(fn ($p) => Permission::findOrCreate($p, 'web'), $this->rolePermissions);

        $this->super = Role::findOrCreate(config('filament-shield.super_admin.name'), 'web');
        $this->super->givePermissionTo($perms);
        $managerRole = Role::findOrCreate('zzz-r4-role-manager', 'web');
        $managerRole->syncPermissions($perms);
        $this->ordinary = Role::findOrCreate('zzz-r4-ordinary', 'web');
        $this->ordinary->syncPermissions([Permission::findOrCreate('view_any_empresa', 'web')]);

        $this->actors = [
            'guest' => null,
            'unverified' => $this->user([], [], verified: false),
            'affiliate' => $this->user(['view_any_empresa', 'view_empresa', 'update_empresa']),
            'no_permission' => $this->user(),
            'user_manager' => $this->user(['view_any_user', 'view_user', 'create_user', 'update_user']),
            'role_manager_by_role' => $this->user([], [$managerRole]),
            'role_manager_direct' => $this->user($this->rolePermissions),
            'super_admin' => $this->user([], [$this->super]),
        ];
    }

    private function user(array $permissions = [], array $roles = [], bool $verified = true): User
    {
        $user = $verified ? User::factory()->create() : User::factory()->unverified()->create();
        if ($permissions) {
            $user->givePermissionTo(array_map(fn ($p) => Permission::findOrCreate($p, 'web'), $permissions));
        }
        foreach ($roles as $role) {
            $user->assignRole($role);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /** Every role's name, guard and permission set: what a refused attempt must leave unchanged. */
    private function definitions(): array
    {
        return Role::query()->orderBy('id')->get()->mapWithKeys(fn (Role $r) => [
            $r->id => [$r->name, $r->guard_name, DB::table('role_has_permissions')->where('role_id', $r->id)->orderBy('permission_id')->pluck('permission_id')->all()],
        ])->all();
    }

    /** A refusal (forbidden / not found / authorization) is a denial; anything else fails the test. */
    private function attempt(callable $call): void
    {
        try {
            $call();
        } catch (HttpExceptionInterface|AuthorizationException|ModelNotFoundException) {
            // denied; state asserted by the caller
        }
    }

    /** The harness lost the component: the server refused the request. It must be a denial status, never an error. */
    private function refused($component): bool
    {
        if ($component->instance() !== null) {
            return false;
        }
        try {
            $component->assertForbidden();
        } catch (\PHPUnit\Framework\AssertionFailedError) {
            $component->assertNotFound();
        }

        return true;
    }

    // ------------------------------------------------------------------ HTTP entrypoints, every actor

    #[Test]
    public function only_a_current_super_admin_reaches_any_role_administration_page(): void
    {
        $urls = [
            'list' => RoleResource::getUrl('index'),
            'create' => RoleResource::getUrl('create'),
            'view' => RoleResource::getUrl('view', ['record' => $this->ordinary]),
            'edit' => RoleResource::getUrl('edit', ['record' => $this->ordinary]),
            'edit_privileged' => RoleResource::getUrl('edit', ['record' => $this->super]),
        ];
        $expected = [
            'guest' => 'login', 'unverified' => 'verify', 'affiliate' => 'deny', 'no_permission' => 'deny', 'user_manager' => 'deny',
            'role_manager_by_role' => 'deny', 'role_manager_direct' => 'deny', 'super_admin' => 'allow',
        ];

        foreach ($expected as $actor => $outcome) {
            foreach ($urls as $page => $url) {
                auth()->logout();
                $response = $this->actors[$actor] ? $this->actingAs($this->actors[$actor])->get($url) : $this->get($url);
                $status = $response->getStatusCode();
                $this->assertLessThan(500, $status, "{$actor} {$page}: never an error");
                match ($outcome) {
                    'allow' => $this->assertSame(200, $status, "{$actor} {$page}: allowed"),
                    'deny' => $this->assertContains($status, [403, 404], "{$actor} {$page}: denied"),
                    'login' => $this->assertTrue($response->isRedirect() && str_contains((string) $response->headers->get('Location'), 'login'), "{$actor} {$page}: to login"),
                    'verify' => $this->assertTrue($response->isRedirect() && str_contains((string) $response->headers->get('Location'), 'verif'), "{$actor} {$page}: to verification"),
                };
            }
        }
        // The gate itself, for the record-level abilities a page does not show.
        foreach (['role_manager_by_role', 'role_manager_direct', 'user_manager'] as $actor) {
            foreach (['update', 'delete', 'replicate', 'forceDelete', 'restore'] as $ability) {
                $this->assertFalse(Gate::forUser($this->actors[$actor])->allows($ability, $this->super), "{$actor} cannot {$ability} the privileged role");
            }
            foreach (['viewAny', 'create', 'deleteAny', 'reorder'] as $ability) {
                $this->assertFalse(Gate::forUser($this->actors[$actor])->allows($ability, Role::class), "{$actor} cannot {$ability} roles");
            }
        }
    }

    // ------------------------------------------------------------------ Livewire paths of lesser actors

    #[Test]
    public function a_non_super_admin_with_every_role_permission_cannot_change_any_role_definition(): void
    {
        $before = $this->definitions();
        $ownRole = Role::findByName('zzz-r4-role-manager', 'web');
        $this->withoutExceptionHandling();

        foreach (['role_manager_by_role', 'role_manager_direct'] as $actor) {
            $user = $this->actors[$actor];
            // Self-elevation by rewriting its own role, editing the privileged role, creating a role, deleting roles.
            $this->attempt(fn () => Livewire::actingAs($user)->test(EditRole::class, ['record' => $ownRole->getKey()])
                ->set('data.name', $this->super->name)->set('data.user', ['view_any_user', 'update_user'])->call('save'));
            $this->attempt(fn () => Livewire::actingAs($user)->test(EditRole::class, ['record' => $this->super->getKey()])
                ->set('data.name', 'zzz-renamed')->call('save'));
            $this->attempt(fn () => Livewire::actingAs($user)->test(CreateRole::class)
                ->set('data.name', 'zzz-r4-new')->set('data.guard_name', 'web')->call('create'));
            $this->attempt(fn () => Livewire::actingAs($user)->test(ListRoles::class)->callTableAction('delete', $this->ordinary));
            $this->attempt(fn () => Livewire::actingAs($user)->test(ListRoles::class)->callTableBulkAction('delete', [$this->ordinary->getKey(), $this->super->getKey()]));
            $this->attempt(fn () => Livewire::actingAs($user)->test(EditRole::class, ['record' => $this->ordinary->getKey()])->callAction('delete'));
        }

        $this->assertEquals($before, $this->definitions(), 'no role name, guard or permission link changed');
        $this->assertFalse(Role::query()->where('name', 'zzz-r4-new')->exists(), 'no role created');
        $this->assertFalse($this->actors['role_manager_by_role']->fresh()->hasRole($this->super->name), 'no self-elevation');
    }

    #[Test]
    public function a_page_mounted_while_super_admin_is_refused_after_the_role_is_removed(): void
    {
        // A super admin who ALSO holds the role permissions as direct grants: losing the super_admin role must end role
        // administration even though the generic permissions remain.
        $admin = $this->actors['super_admin'];
        $admin->givePermissionTo($this->rolePermissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $edit = Livewire::actingAs($admin)->test(EditRole::class, ['record' => $this->ordinary->getKey()]);
        $list = Livewire::actingAs($admin)->test(ListRoles::class);
        $before = $this->definitions();

        DB::table('model_has_roles')->where(['role_id' => $this->super->getKey(), 'model_id' => $admin->getKey()])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->attempt(function () use ($edit) {
            $edit->set('data.name', 'zzz-after-revocation');
            if (! $this->refused($edit)) {
                $edit->call('save');
                $this->refused($edit);
            }
        });
        $this->attempt(function () use ($list) {
            $list->call('mountTableAction', 'delete', (string) $this->ordinary->getKey());
            if (! $this->refused($list)) {
                $list->call('callMountedTableAction');
            }
        });

        $this->assertEquals($before, $this->definitions(), 'nothing changed after the role was removed');
        $this->assertNotNull(Role::find($this->ordinary->getKey()));
        $this->assertFalse(Gate::forUser($admin->fresh())->allows('update', $this->ordinary), 'direct grants alone are not enough');
    }

    // ------------------------------------------------------------------ the allowed path

    #[Test]
    public function a_super_admin_lists_creates_edits_and_deletes_ordinary_roles(): void
    {
        $admin = $this->actors['super_admin'];
        $this->actingAs($admin)->get(RoleResource::getUrl('index'))->assertOk();

        Livewire::actingAs($admin)->test(CreateRole::class)
            ->set('data.name', 'zzz-r4-created')->set('data.guard_name', 'web')->set('data.empresa', ['view_any_empresa'])
            ->call('create')->assertHasNoFormErrors();
        $created = Role::findByName('zzz-r4-created', 'web');
        $this->assertTrue($created->hasPermissionTo('view_any_empresa'), 'created with its permission');

        Livewire::actingAs($admin)->test(EditRole::class, ['record' => $created->getKey()])
            ->set('data.name', 'zzz-r4-edited')->set('data.empresa', ['view_empresa'])
            ->call('save')->assertHasNoFormErrors();
        $edited = Role::find($created->getKey());
        $this->assertSame('zzz-r4-edited', $edited->name, 'renamed');
        $this->assertSame(['view_empresa'], $edited->permissions()->pluck('name')->all(), 'permissions replaced');

        Livewire::actingAs($admin)->test(EditRole::class, ['record' => $edited->getKey()])->callAction('delete');
        $this->assertNull(Role::find($edited->getKey()), 'deleted');

        $a = Role::findOrCreate('zzz-r4-bulk-a', 'web');
        $b = Role::findOrCreate('zzz-r4-bulk-b', 'web');
        Livewire::actingAs($admin)->test(ListRoles::class)->callTableBulkAction('delete', [$a->getKey(), $b->getKey()]);
        $this->assertFalse(Role::query()->whereIn('id', [$a->getKey(), $b->getKey()])->exists(), 'bulk deleted');
        $this->assertNotNull(Role::find($this->super->getKey()), 'the privileged role is still there');
    }

    // ------------------------------------------------------------------ the privileged role's identity

    #[Test]
    public function the_privileged_role_identity_cannot_be_rewritten_or_taken(): void
    {
        $admin = $this->actors['super_admin'];
        $this->actingAs($admin);
        $before = $this->definitions();

        $name = $this->super->name;
        // Each attempt is checked at once, so the first unprotected field is reported by name.
        $this->attempt(fn () => Role::find($this->super->getKey())->update(['name' => 'zzz-renamed']));
        $this->assertSame($name, Role::find($this->super->getKey())->name, 'the privileged role name is protected');
        $this->attempt(fn () => Role::find($this->super->getKey())->update(['guard_name' => 'api']));
        $this->assertSame('web', Role::find($this->super->getKey())->guard_name, 'the privileged role guard is protected');
        $this->attempt(fn () => Role::find($this->super->getKey())->delete());
        $this->assertNotNull(Role::find($this->super->getKey()), 'the privileged role cannot be deleted');
        $this->attempt(fn () => Role::find($this->ordinary->getKey())->update(['name' => $name]));
        $this->assertSame('zzz-r4-ordinary', Role::find($this->ordinary->getKey())->name, 'no other role takes the privileged name');
        $this->attempt(fn () => Role::query()->create(['name' => $name, 'guard_name' => 'api']));
        $this->assertSame(1, Role::query()->where('name', $name)->count(), 'no second privileged-name role under another guard');
        // Through the page too: a super admin cannot rename the privileged role by mistake.
        $this->attempt(fn () => Livewire::actingAs($admin)->test(EditRole::class, ['record' => $this->super->getKey()])
            ->set('data.name', 'zzz-renamed')->call('save'));

        $this->assertEquals($before, $this->definitions(), 'the privileged role and all other roles are unchanged');
        $this->assertSame(1, Role::query()->where('name', $this->super->name)->count(), 'exactly one privileged role, under its guard');
        $this->assertTrue($admin->fresh()->hasRole($this->super->name), 'the administrator keeps the role');
    }
}
