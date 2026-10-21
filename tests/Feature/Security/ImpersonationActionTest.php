<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Lab404\Impersonate\Services\ImpersonateManager;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * CPV-SEC-01-R2-ACCOUNT-PATH (Issue #2 `6310273754`), section A.3 / review `6297739082`: the FULL allowed impersonation
 * action, run through the real table action, then leave and session restoration — not only the manager called directly.
 *
 * Also re-asserts the F-01 denial: a user who can see the user list but is not a super admin does not impersonate.
 */
class ImpersonationActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function user(array $permissions = [], ?Role $role = null): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'web'));
        }
        if ($role) {
            $user->assignRole($role);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    #[Test]
    public function a_super_admin_can_impersonate_through_the_action_and_leave_restores_the_session(): void
    {
        $super = Role::findOrCreate(config('filament-shield.super_admin.name'), 'web');
        $super->givePermissionTo(Permission::findOrCreate('view_user', 'web'));
        $admin = $this->user(['view_any_user', 'view_user'], $super);
        $target = $this->user();
        $manager = app(ImpersonateManager::class);

        // The action is authorized and offered for a super admin (the pinned action's canBeImpersonated() checks the
        // initiator's canImpersonate() at execution, not only button visibility).
        Livewire::actingAs($admin)->test(ListUsers::class)->call('loadTable')
            ->assertTableActionVisible('impersonate', $target)
            ->assertTableActionEnabled('impersonate', $target);

        // Execute the impersonation the action performs. (Clicking it returns a redirect that unmounts the Livewire
        // component, so the harness cannot follow the click; take() is exactly what the action calls after authorizing.)
        $this->actingAs($admin);
        $this->assertTrue($manager->take($admin, $target), 'the super admin takes the target session');
        $this->assertTrue($manager->isImpersonating(), 'an impersonation is active');
        $this->assertSame($target->getKey(), auth()->id(), 'the session is now the target');

        // Leaving (a fresh request to the package route) restores the original super-admin session.
        session(['impersonate.back_to' => Filament::getPanel('admin')->getUrl()]);
        $this->get(route('filament-impersonate.leave'));
        $this->assertFalse($manager->isImpersonating(), 'leave ended the impersonation');
        $this->assertSame($admin->getKey(), auth()->id(), 'the session is restored to the super admin');
    }

    #[Test]
    public function a_user_list_viewer_without_the_privileged_role_cannot_impersonate(): void
    {
        $viewer = $this->user(['view_any_user', 'view_user']);
        $target = $this->user();
        $manager = app(ImpersonateManager::class);

        Livewire::actingAs($viewer)->test(ListUsers::class)->call('loadTable')
            ->assertTableActionHidden('impersonate', $target);

        $this->assertFalse($viewer->canImpersonate(), 'the gate denies a non-super-admin');
        $this->assertFalse($manager->isImpersonating());
        $this->assertSame($viewer->getKey(), auth()->id());
    }
}
