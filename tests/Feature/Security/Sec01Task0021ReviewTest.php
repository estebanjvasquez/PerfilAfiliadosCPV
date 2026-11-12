<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\Shield\RoleResource\Pages\EditRole;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TASK-0021 (Issue #2 `6823247114`), final review of F-SEC01-R4-01 at the observed scale. The historical defect removed
 * 109 hidden permissions from a role WITHOUT users; the 6/1 other losses were VISIBLE boxes unchecked, which the editor
 * must still honour. Literal expectations, synthetic data in the disposable PostgreSQL only:
 * - a role without users and >100 hidden permissions shaped like the real ones (export_*, taxonomy_*, legacy pages,
 *   widgets and unregistered resources) keeps every one when saved unchanged;
 * - unchecking one visible box removes exactly that one and nothing hidden;
 * - the privileged role with >100 hidden permissions keeps them all, including role administration.
 */
class Sec01Task0021ReviewTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @return array<int, string> */
    private function hiddenNames(): array
    {
        $names = [];
        foreach (range(1, 30) as $i) {
            $names[] = sprintf('export_zzz_t21_%02d', $i);
            $names[] = sprintf('taxonomy_zzz_t21_%02d', $i);
        }
        foreach (range(1, 20) as $i) {
            $names[] = sprintf('page_ZzzT21Legacy%02d', $i);
            $names[] = sprintf('widget_ZzzT21Legacy%02d', $i);
        }
        foreach (['view', 'view_any', 'create', 'update', 'delete', 'delete_any'] as $prefix) {
            $names[] = $prefix.'_zzz_t21_unregistered';
        }

        return $names; // 106 names, none rendered by the editor
    }

    /** @return array<int, string> */
    private function names(Role $role): array
    {
        return DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('role_id', $role->getKey())->orderBy('permissions.name')->pluck('permissions.name')->all();
    }

    private function superAdmin(array $extra): array
    {
        $super = Role::findOrCreate(config('filament-shield.super_admin.name'), 'web');
        $rolePermissions = array_map(fn ($p) => Permission::findOrCreate($p.'_role', 'web')->name,
            config('filament-shield.permission_prefixes.resource'));
        $super->givePermissionTo([...$rolePermissions, ...$extra]);
        $admin = User::factory()->create();
        $admin->assignRole($super);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [$super, $admin];
    }

    #[Test]
    public function a_role_without_users_keeps_over_a_hundred_hidden_permissions_and_loses_only_the_unchecked_visible_one(): void
    {
        $hidden = $this->hiddenNames();
        $visible = ['view_any_user', 'view_user', 'update_user', 'view_any_empresa', 'view_empresa'];
        foreach ([...$hidden, ...$visible] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        [, $admin] = $this->superAdmin([]);
        $role = Role::findOrCreate('zzz-t21-no-users', 'web');
        $role->syncPermissions([...$hidden, ...$visible]);
        $this->assertSame(0, DB::table('model_has_roles')->where('role_id', $role->getKey())->count(), 'no users');
        $this->assertGreaterThan(100, count($hidden));
        $permissionRows = Permission::query()->count();

        Livewire::actingAs($admin)->test(EditRole::class, ['record' => $role->getKey()])->call('save')->assertHasNoFormErrors();
        $expected = [...$hidden, ...$visible];
        sort($expected);
        $this->assertSame($expected, $this->names($role), 'saved unchanged: every hidden and visible permission kept');

        Livewire::actingAs($admin)->test(EditRole::class, ['record' => $role->getKey()])
            ->set('data.user', ['view_any_user', 'view_user'])->call('save')->assertHasNoFormErrors();
        $expected = array_values(array_diff($expected, ['update_user']));
        $this->assertSame($expected, $this->names($role), 'only the unchecked visible box is removed');
        $this->assertSame($permissionRows, Permission::query()->count(), 'no Permission created or deleted');
    }

    #[Test]
    public function the_privileged_role_with_over_a_hundred_hidden_permissions_keeps_them_all(): void
    {
        $hidden = $this->hiddenNames();
        foreach ($hidden as $name) {
            Permission::findOrCreate($name, 'web');
        }
        [$super, $admin] = $this->superAdmin([...$hidden, 'view_empresa']);
        $before = $this->names($super);
        $memberships = DB::table('model_has_roles')->where('role_id', $super->getKey())->count();

        Livewire::actingAs($admin)->test(EditRole::class, ['record' => $super->getKey()])->call('save')->assertHasNoFormErrors();

        $this->assertSame($before, $this->names($super));
        $this->assertContains('update_role', $this->names($super), 'role administration stays with super_admin');
        $this->assertSame($memberships, DB::table('model_has_roles')->where('role_id', $super->getKey())->count());
    }
}
