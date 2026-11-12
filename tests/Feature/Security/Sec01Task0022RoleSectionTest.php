<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\Shield\RoleResource;
use App\Filament\Resources\Shield\RoleResource\Pages\CreateRole;
use App\Filament\Resources\Shield\RoleResource\Pages\EditRole;
use App\Models\User;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use BezhanSalleh\FilamentShield\FilamentShield as ShieldService;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * TASK-0022 / F-SEC01-T21-01 (Issue #2 `6829151868`). The published RoleResource got Shield's key `shield::role`, so the
 * role editor's "Role" section rendered six names that do not exist (`*_shield::role`) and that RolePolicy never checks.
 * Literal expectations, synthetic data in the disposable PostgreSQL only:
 * - the Role section renders exactly the six `*_role` names of Shield's role prefixes, every one checked by RolePolicy,
 *   and no `shield::` name is rendered anywhere;
 * - every other registered resource keeps Shield's default key;
 * - the Role section is read-only: never editable, so it can neither grant role administration to another role (R4
 *   containment) nor remove it from super_admin (lock-out); hidden permissions are still preserved (R4-01);
 * - forged names through the Role field or any other field create and grant nothing.
 */
class Sec01Task0022RoleSectionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    /** Shield 3.9.10 RoleResource::getPermissionPrefixes(). */
    private const ROLE_PREFIXES = ['view', 'view_any', 'create', 'update', 'delete', 'delete_any'];

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @return array<int, string> */
    private function expectedRoleNames(): array
    {
        $names = array_map(fn (string $prefix) => $prefix.'_role', self::ROLE_PREFIXES);
        sort($names);

        return $names;
    }

    /** Permission names RolePolicy checks, read from its source. @return array<int, string> */
    private function policyNames(): array
    {
        preg_match_all("/'([a-z_]+_role)'/", file_get_contents(app_path('Policies/RolePolicy.php')), $m);

        return array_values(array_unique($m[1]));
    }

    /** @return array<int, string> */
    private function names(Role $role): array
    {
        return DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('role_id', $role->getKey())->orderBy('permissions.name')->pluck('permissions.name')->all();
    }

    /** @return array{0: Role, 1: User} */
    private function superAdmin(array $extra = []): array
    {
        $super = Role::findOrCreate(config('filament-shield.super_admin.name'), 'web');
        foreach ([...$this->expectedRoleNames(), ...$extra] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $super->givePermissionTo([...$this->expectedRoleNames(), ...$extra]);
        $admin = User::factory()->create();
        $admin->assignRole($super);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return [$super, $admin];
    }

    /** @return array<string, CheckboxList> keyed by field name */
    private function checkboxLists($livewire): array
    {
        $lists = [];
        foreach ($livewire->instance()->form->getFlatComponents() as $component) {
            if ($component instanceof CheckboxList) {
                $lists[$component->getName()] = $component;
            }
        }

        return $lists;
    }

    #[Test]
    public function the_role_section_renders_exactly_the_six_names_role_policy_checks(): void
    {
        [$super, $admin] = $this->superAdmin();
        $page = Livewire::actingAs($admin)->test(EditRole::class, ['record' => $super->getKey()]);
        $lists = $this->checkboxLists($page);

        $all = collect($lists)->flatMap(fn (CheckboxList $list) => array_keys($list->getOptions()))->all();
        $this->assertSame([], array_values(array_filter($all, fn ($n) => str_contains((string) $n, 'shield::'))),
            'no shield:: name is rendered');

        $this->assertArrayHasKey('role', $lists, 'the Role section field is keyed `role`');
        $rendered = array_keys($lists['role']->getOptions());
        sort($rendered);
        $this->assertSame($this->expectedRoleNames(), $rendered, 'the six real role permissions');
        $this->assertSame([], array_values(array_diff($rendered, $this->policyNames())), 'every rendered name is checked by RolePolicy');

        $state = $page->get('data.role');
        sort($state);
        $this->assertSame($this->expectedRoleNames(), $state, 'super_admin sees its six role permissions checked');
        $this->assertTrue($lists['role']->isDisabled(), 'read-only');
        $this->assertSame('role', FilamentShield::getPermissionIdentifier(RoleResource::class));
    }

    #[Test]
    public function every_other_resource_keeps_shields_default_key(): void
    {
        $default = new \ReflectionMethod(ShieldService::class, 'getDefaultPermissionIdentifier');
        $vendor = new ShieldService;
        $checked = 0;
        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            if (is_a($resource, RoleResource::class, true)) {
                continue;
            }
            $this->assertSame((string) $default->invoke($vendor, $resource), FilamentShield::getPermissionIdentifier($resource), $resource);
            $checked++;
        }
        $this->assertGreaterThan(10, $checked);
    }

    #[Test]
    public function super_admin_keeps_role_administration_and_hidden_permissions_even_if_the_role_boxes_are_cleared(): void
    {
        $hidden = ['export_zzz_t22_a', 'taxonomy_zzz_t22_b', 'page_ZzzT22Legacy', 'widget_ZzzT22Legacy'];
        [$super, $admin] = $this->superAdmin([...$hidden, 'view_empresa']);
        $before = $this->names($super);
        $rows = Permission::query()->count();

        Livewire::actingAs($admin)->test(EditRole::class, ['record' => $super->getKey()])->call('save')->assertHasNoFormErrors();
        $this->assertSame($before, $this->names($super), 'saved unchanged');

        Livewire::actingAs($admin)->test(EditRole::class, ['record' => $super->getKey()])
            ->set('data.role', [])->call('save')->assertHasNoFormErrors();
        $this->assertSame($before, $this->names($super), 'clearing the read-only Role boxes removes nothing (no lock-out)');
        $this->assertTrue($admin->fresh()->can('update', $super->fresh()), 'the administrator still administers roles');
        $this->assertSame($rows, Permission::query()->count());
    }

    #[Test]
    public function another_role_never_gets_role_administration_or_forged_names_from_the_editor(): void
    {
        [, $admin] = $this->superAdmin(['view_any_user', 'view_user']);
        Permission::findOrCreate('export_zzz_t22_kept', 'web'); // hidden: rendered by no field
        $role = Role::findOrCreate('zzz-t22-ordinary', 'web');
        $role->syncPermissions(['view_any_user', 'export_zzz_t22_kept']);
        $rows = Permission::query()->count();
        $forged = ['update_shield::role', 'view_shield::role', 'zzz_t22_forged', 'super_admin'];

        Livewire::actingAs($admin)->test(EditRole::class, ['record' => $role->getKey()])
            ->set('data.role', [...$this->expectedRoleNames(), ...$forged])
            ->set('data.user', ['view_any_user', 'view_user', 'update_role', 'zzz_t22_forged'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame(['export_zzz_t22_kept', 'view_any_user', 'view_user'], $this->names($role),
            'only the visible, editable box is added; hidden kept; no *_role; nothing forged');
        $this->assertSame($rows, Permission::query()->count(), 'no Permission created');
        $this->assertSame(0, Permission::query()->where('name', 'like', '%shield::%')->count());

        Livewire::actingAs($admin)->test(CreateRole::class)
            ->fillForm(['name' => 'zzz-t22-created', 'guard_name' => 'web'])
            ->set('data.role', $this->expectedRoleNames())
            ->call('create')->assertHasNoFormErrors();
        $created = Role::query()->where('name', 'zzz-t22-created')->firstOrFail();
        $this->assertSame([], $this->names($created), 'a new role gets no role administration');
    }
}
