<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\Shield\RoleResource\Pages\CreateRole;
use App\Filament\Resources\Shield\RoleResource\Pages\EditRole;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Events\PermissionAttached;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/**
 * CPV-SEC-01 F-SEC01-R4-01 (Issue #2 `6320241495`): saving a role in the editor changes ONLY the permissions the editor
 * renders. The expectations are literals, independent of the code under test:
 * - permissions the form does not render (`zzz_r401_hidden_*`: no resource, page or widget option, custom tab disabled)
 *   survive every save, read fresh from the database at save time (a stale page neither drops a hidden permission added
 *   since it was opened nor restores one removed since);
 * - visible checkboxes still add and remove (`data.empresa`: the Empresa resource's options);
 * - a forged name, a numeric id, or a hidden name sent through a visible field is neither created nor granted;
 * - the privileged role keeps its hidden permissions; a page opened while super_admin is refused once the role is gone;
 * - a failure in the middle of the permission write rolls back the role row and every link.
 * Exact permission sets, Permission row counts and role memberships are asserted before teardown. Synthetic data in the
 * disposable PostgreSQL only.
 */
class RoleEditorPermissionPreservationTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private Role $super;

    private Role $ordinary;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        foreach (['view_empresa', 'view_any_empresa', 'update_empresa', 'zzz_r401_hidden_a', 'zzz_r401_hidden_b', 'zzz_r401_hidden_other'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $rolePermissions = array_map(fn ($p) => $p.'_role', config('filament-shield.permission_prefixes.resource'));

        $this->super = Role::findOrCreate(config('filament-shield.super_admin.name'), 'web');
        $this->super->givePermissionTo([...array_map(fn ($p) => Permission::findOrCreate($p, 'web'), $rolePermissions), 'view_empresa', 'zzz_r401_hidden_a']);
        $this->ordinary = Role::findOrCreate('zzz-r401-ordinary', 'web');
        $this->ordinary->syncPermissions(['view_any_empresa', 'view_empresa', 'zzz_r401_hidden_a', 'zzz_r401_hidden_b']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole($this->super);
        User::factory()->create()->assignRole($this->ordinary);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return array<int, string> the role's persisted permission names, sorted */
    private function names(Role $role): array
    {
        return DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('role_id', $role->getKey())->orderBy('permissions.name')->pluck('permissions.name')->all();
    }

    private function snapshot(): array
    {
        return [
            'permissions' => Permission::query()->count(),
            'memberships' => DB::table('model_has_roles')->orderBy('role_id')->orderBy('model_id')->get(['role_id', 'model_id'])->map(fn ($r) => (array) $r)->all(),
        ];
    }

    private function edit(Role $role)
    {
        return Livewire::actingAs($this->admin)->test(EditRole::class, ['record' => $role->getKey()]);
    }

    #[Test]
    public function saving_without_changes_keeps_every_permission_of_an_ordinary_and_the_privileged_role(): void
    {
        $ordinary = $this->names($this->ordinary);
        $super = $this->names($this->super);
        $before = $this->snapshot();

        $this->edit($this->ordinary)->call('save')->assertHasNoFormErrors();
        $this->edit($this->super)->call('save')->assertHasNoFormErrors();

        $this->assertSame(['view_any_empresa', 'view_empresa', 'zzz_r401_hidden_a', 'zzz_r401_hidden_b'], $this->names($this->ordinary));
        $this->assertSame($ordinary, $this->names($this->ordinary));
        $this->assertSame($super, $this->names($this->super), 'the privileged role keeps its hidden and role permissions');
        $this->assertContains('zzz_r401_hidden_a', $this->names($this->super));
        $this->assertSame($before, $this->snapshot(), 'no Permission created; memberships unchanged');
    }

    #[Test]
    public function a_visible_removal_and_addition_apply_while_hidden_permissions_stay(): void
    {
        $before = $this->snapshot();

        $this->edit($this->ordinary)->set('data.empresa', ['view_any_empresa'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(['view_any_empresa', 'zzz_r401_hidden_a', 'zzz_r401_hidden_b'], $this->names($this->ordinary), 'one visible removed');

        $this->edit($this->ordinary)->set('data.empresa', ['view_any_empresa', 'update_empresa'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(['update_empresa', 'view_any_empresa', 'zzz_r401_hidden_a', 'zzz_r401_hidden_b'], $this->names($this->ordinary), 'one visible added');

        // The privileged role: a visible removal there keeps its hidden and role-administration permissions.
        $this->edit($this->super)->set('data.empresa', [])->call('save')->assertHasNoFormErrors();
        $this->assertNotContains('view_empresa', $this->names($this->super));
        $this->assertContains('zzz_r401_hidden_a', $this->names($this->super));
        $this->assertContains('update_role', $this->names($this->super));
        $this->assertSame($before, $this->snapshot());
    }

    #[Test]
    public function the_preserved_part_is_read_fresh_not_from_the_open_page(): void
    {
        $page = $this->edit($this->ordinary);

        // After the page was opened: another writer adds one hidden permission and removes another.
        $this->ordinary->givePermissionTo('zzz_r401_hidden_other');
        $this->ordinary->revokePermissionTo('zzz_r401_hidden_b');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $page->set('data.empresa', ['view_empresa'])->call('save')->assertHasNoFormErrors();

        $this->assertSame(['view_empresa', 'zzz_r401_hidden_a', 'zzz_r401_hidden_other'], $this->names($this->ordinary),
            'the hidden part is the current one: the new hidden permission kept, the removed one not restored');
    }

    #[Test]
    public function forged_names_ids_and_hidden_names_in_a_visible_field_are_not_created_or_granted(): void
    {
        $before = $this->snapshot();
        $otherId = (string) Permission::findByName('zzz_r401_hidden_other', 'web')->getKey();
        $forged = ['view_any_empresa', 'zzz_r401_forged', $otherId, '999999', 'zzz_r401_hidden_other'];

        $this->edit($this->ordinary)->set('data.empresa', $forged)->set('data.zzz_r401_extra', ['zzz_r401_forged_extra'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame(['view_any_empresa', 'zzz_r401_hidden_a', 'zzz_r401_hidden_b'], $this->names($this->ordinary));

        Livewire::actingAs($this->admin)->test(CreateRole::class)
            ->set('data.name', 'zzz-r401-created')->set('data.guard_name', 'web')->set('data.empresa', $forged)
            ->call('create')->assertHasNoFormErrors();
        $this->assertSame(['view_any_empresa'], $this->names(Role::findByName('zzz-r401-created', 'web')));

        $this->assertSame($before['permissions'], Permission::query()->count(), 'no Permission created');
        $this->assertFalse(Permission::query()->where('name', 'like', 'zzz_r401_forged%')->exists());
        $this->assertSame($before['memberships'], $this->snapshot()['memberships']);
    }

    #[Test]
    public function a_page_opened_as_super_admin_saves_nothing_after_the_role_is_removed(): void
    {
        $page = $this->edit($this->ordinary);
        $names = $this->names($this->ordinary);

        DB::table('model_has_roles')->where(['role_id' => $this->super->getKey(), 'model_id' => $this->admin->getKey()])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        try {
            // The next request is refused (the harness then loses the component); only if it were not, try to save.
            $page->set('data.empresa', []);
            if ($page->instance() !== null) {
                $page->set('data.name', 'zzz-r401-after')->call('save');
            }
        } catch (HttpExceptionInterface|AuthorizationException|ModelNotFoundException) {
            // refused
        }

        $this->assertSame($names, $this->names($this->ordinary));
        $this->assertSame('zzz-r401-ordinary', Role::find($this->ordinary->getKey())->name);
    }

    #[Test]
    public function a_failure_during_the_permission_write_rolls_back_the_role_and_every_link(): void
    {
        $names = $this->names($this->ordinary);
        $before = $this->snapshot();
        config(['permission.events_enabled' => true]);
        // Injected after Spatie has detached every link and attached the new ones: the worst point to stop.
        Event::listen(PermissionAttached::class, fn () => throw new \RuntimeException('injected persistence failure'));

        $this->withoutExceptionHandling();
        foreach ([
            fn () => $this->edit($this->ordinary)->set('data.name', 'zzz-r401-renamed')->set('data.empresa', ['update_empresa'])->call('save'),
            fn () => Livewire::actingAs($this->admin)->test(CreateRole::class)->set('data.name', 'zzz-r401-failed')
                ->set('data.guard_name', 'web')->set('data.empresa', ['view_empresa'])->call('create'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('the injected failure must surface');
            } catch (\RuntimeException $e) {
                $this->assertSame('injected persistence failure', $e->getMessage());
            }
        }

        $this->assertSame('zzz-r401-ordinary', Role::find($this->ordinary->getKey())->name, 'role row rolled back');
        $this->assertSame($names, $this->names($this->ordinary), 'every link rolled back');
        $this->assertFalse(Role::query()->where('name', 'zzz-r401-failed')->exists(), 'no role created');
        $this->assertSame($before, $this->snapshot());
    }
}
