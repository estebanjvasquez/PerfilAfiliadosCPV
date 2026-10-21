<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/**
 * CPV-SEC-01-R2-ACCOUNT-PATH (Issue #2 `6310273754`): privilege injection through account creation and editing.
 *
 * Expectations are the APPROVED BUSINESS RULES, written here as literals, not read from the gate under test:
 * - `create_user` / `update_user` manage ordinary accounts; they are not the authority to grant the privileged role
 *   (`super_admin`) or to modify an account that holds it;
 * - only a super admin grants or removes the privileged role;
 * - a forged `roles` relationship, a crafted id, the `admin` display name or a forged verified/identity field does not
 *   elevate an account;
 * - allowed staff operations still succeed, passwords stay hashed and hidden, and no response reveals another hash.
 *
 * Synthetic users, roles and permissions in the disposable PostgreSQL. No real role mapping is used.
 */
class AccountPrivilegeTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private string $superName;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->superName = config('filament-shield.super_admin.name');
    }

    private function role(string $name, array $permissions = []): Role
    {
        $role = Role::findOrCreate($name, 'web');
        $role->syncPermissions(array_map(fn ($p) => Permission::findOrCreate($p, 'web'), $permissions));

        return $role;
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

    // ---- primary: privilege injection, fails before the fix / passes after ----

    #[Test]
    public function a_staff_user_manager_cannot_grant_the_privileged_role_on_create(): void
    {
        $super = $this->role($this->superName, ['view_user']);
        $this->role('plain', ['view_user']);
        $manager = $this->user(['view_any_user', 'view_user', 'create_user', 'update_user']);

        Livewire::actingAs($manager)->test(CreateUser::class)
            ->set('data.name', 'ZZZ NUEVO STAFF')
            ->set('data.email', 'zzznuevo@zzz.test')
            ->set('data.password', 'secret-pass-123')
            ->set('data.roles', [$super->getKey()])
            ->call('create')
            ->assertHasNoErrors();

        $created = User::where('email', 'zzznuevo@zzz.test')->firstOrFail();
        $this->assertFalse($created->hasRole($this->superName), 'the forged privileged role must not be granted');
        $this->assertFalse($created->fresh()->hasRole($this->superName));
    }

    #[Test]
    public function a_staff_user_manager_cannot_grant_the_privileged_role_on_edit(): void
    {
        $super = $this->role($this->superName, ['view_user']);
        $manager = $this->user(['view_any_user', 'view_user', 'update_user']);
        $target = $this->user();

        Livewire::actingAs($manager)->test(EditUser::class, ['record' => $target->getKey()])
            ->set('data.roles', [$super->getKey()])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($target->fresh()->hasRole($this->superName), 'edit must not elevate the target');
    }

    #[Test]
    public function a_staff_user_manager_cannot_grant_the_privileged_role_to_itself(): void
    {
        $super = $this->role($this->superName, ['view_user']);
        $manager = $this->user(['view_any_user', 'view_user', 'update_user']);

        Livewire::actingAs($manager)->test(EditUser::class, ['record' => $manager->getKey()])
            ->set('data.roles', [$super->getKey()])
            ->call('save');

        $this->assertFalse($manager->fresh()->hasRole($this->superName), 'no self-elevation');
    }

    #[Test]
    public function a_staff_user_manager_cannot_open_a_privileged_account(): void
    {
        $super = $this->role($this->superName, ['view_user']);
        $manager = $this->user(['view_any_user', 'view_user', 'update_user']);
        $admin = $this->user(roles: [$super]);

        // The edit page is requested over HTTP (as the browser would); the mount guard returns 403.
        $this->actingAs($manager);
        $this->get(\App\Filament\Resources\UserResource::getUrl('edit', ['record' => $admin->getKey()]))->assertForbidden();
        $this->assertTrue($admin->fresh()->hasRole($this->superName), 'the privileged account is unchanged');

        // A super admin with the ordinary management permission may open it.
        $superAdmin = $this->user(['view_any_user', 'view_user', 'update_user'], [$super]);
        $this->actingAs($superAdmin);
        $this->get(\App\Filament\Resources\UserResource::getUrl('edit', ['record' => $admin->getKey()]))->assertSuccessful();
    }

    // ---- allowed paths still work ----

    #[Test]
    public function a_super_admin_may_grant_the_privileged_role(): void
    {
        $super = $this->role($this->superName, ['view_user']);
        $admin = $this->user(['view_any_user', 'view_user', 'create_user'], [$super]);

        Livewire::actingAs($admin)->test(CreateUser::class)
            ->set('data.name', 'ZZZ OTRO ADMIN')
            ->set('data.email', 'zzzadmin@zzz.test')
            ->set('data.password', 'secret-pass-123')
            ->set('data.roles', [$super->getKey()])
            ->call('create')
            ->assertHasNoErrors();

        $this->assertTrue(User::where('email', 'zzzadmin@zzz.test')->firstOrFail()->hasRole($this->superName));
    }

    #[Test]
    public function a_staff_user_manager_may_create_an_ordinary_user_with_a_non_privileged_role(): void
    {
        $this->role($this->superName, ['view_user']);
        $plain = $this->role('plain', ['view_user']);
        $manager = $this->user(['view_any_user', 'view_user', 'create_user']);

        Livewire::actingAs($manager)->test(CreateUser::class)
            ->set('data.name', 'ZZZ COMUN')
            ->set('data.email', 'zzzcomun@zzz.test')
            ->set('data.password', 'secret-pass-123')
            ->set('data.roles', [$plain->getKey()])
            ->call('create')
            ->assertHasNoErrors();

        $created = User::where('email', 'zzzcomun@zzz.test')->firstOrFail();
        $this->assertTrue($created->hasRole('plain'), 'ordinary role assignment still works');
        $this->assertFalse($created->hasRole($this->superName));
    }

    // ---- password and identity invariants ----

    #[Test]
    public function passwords_are_hashed_and_hidden_and_a_blank_edit_keeps_the_old_hash(): void
    {
        $this->role($this->superName, ['view_user']);
        $manager = $this->user(['view_any_user', 'view_user', 'create_user', 'update_user']);

        Livewire::actingAs($manager)->test(CreateUser::class)
            ->set('data.name', 'ZZZ HASH')
            ->set('data.email', 'zzzhash@zzz.test')
            ->set('data.password', 'secret-pass-123')
            ->call('create')
            ->assertHasNoErrors();

        $created = User::where('email', 'zzzhash@zzz.test')->firstOrFail();
        $this->assertNotSame('secret-pass-123', $created->password, 'stored password is not plaintext');
        $this->assertTrue(Hash::check('secret-pass-123', $created->password), 'password is hashed');
        $this->assertArrayNotHasKey('password', $created->toArray(), 'password is hidden from serialization');
        $originalHash = $created->password;

        // A blank password on edit leaves the hash untouched (the redundant lookup method was removed).
        Livewire::actingAs($manager)->test(EditUser::class, ['record' => $created->getKey()])
            ->set('data.name', 'ZZZ HASH EDITADO')
            ->set('data.password', '')
            ->call('save')
            ->assertHasNoErrors();

        $after = $created->fresh();
        $this->assertSame('ZZZ HASH EDITADO', $after->name);
        $this->assertSame($originalHash, $after->password, 'a blank password does not change or null the hash');
    }

    // ---- public registration ----

    #[Test]
    public function public_registration_creates_only_an_ordinary_unverified_account(): void
    {
        $super = $this->role($this->superName, ['view_user']);

        // Forged elevation fields are not part of the registration form schema, so they must not persist.
        Livewire::test(\App\Filament\Pages\Register::class)
            ->set('data.name', 'ZZZ PUBLICO')
            ->set('data.email', 'zzzpublico@zzz.test')
            ->set('data.password', 'secret-pass-123')
            ->set('data.passwordConfirmation', 'secret-pass-123')
            ->set('data.roles', [$super->getKey()])
            ->set('data.email_verified_at', now())
            ->call('register');

        $user = User::where('email', 'zzzpublico@zzz.test')->first();
        $this->assertNotNull($user, 'registration created the account');
        $this->assertFalse($user->hasRole($this->superName), 'registration grants no role');
        $this->assertSame(0, $user->roles()->count());
        $this->assertNull($user->email_verified_at, 'registration does not self-verify');
        $this->assertTrue(Hash::check('secret-pass-123', $user->password));
    }

    #[Test]
    public function the_display_name_admin_confers_no_authority(): void
    {
        $this->role($this->superName, ['view_user']);
        $manager = $this->user(['view_any_user', 'view_user', 'create_user']);

        Livewire::actingAs($manager)->test(CreateUser::class)
            ->set('data.name', 'admin')
            ->set('data.email', 'zzzadminname@zzz.test')
            ->set('data.password', 'secret-pass-123')
            ->call('create')
            ->assertHasNoErrors();

        $created = User::where('email', 'zzzadminname@zzz.test')->firstOrFail();
        $this->assertSame(0, $created->roles()->count());
        $this->assertFalse($created->hasRole($this->superName));
    }
}
