<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * CPV-SEC-01-R2-ACCOUNT-PATH, second review of `6310331211` (R2-REV-05, narrow atomicity correction).
 *
 * The account row and its role pivot must be written as ONE application transaction on the connection they actually
 * use, on both user pages. A failure is injected right after the LAST write of the request (create: the role pivot
 * insert, which follows the account insert; edit: the account update, which follows the relationship sync), and the
 * state is asserted AFTER the failed request but BEFORE test teardown. The outer DatabaseTransactions wrapper is
 * still open at that point, so it cannot hide a missing application transaction: without one, the partial writes stay
 * visible here. The transaction boundary itself is also instrumented (begin events and the nesting level seen by the
 * failing statement), so the result does not rest on teardown cleanliness.
 *
 * Synthetic users, roles and permissions only, in the disposable PostgreSQL.
 */
class AccountWriteAtomicityTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['pgsql'];

    private const INJECTED = 'CPV-R2-REV-05 injected failure';

    private string $superName;

    /** @var array<int, string> connection names that began a transaction while armed */
    private array $begun = [];

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

    private function user(array $permissions = [], array $roles = [], array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        foreach ($permissions as $p) {
            $user->givePermissionTo(Permission::findOrCreate($p, 'web'));
        }
        foreach ($roles as $r) {
            $user->assignRole($r);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /** The connections the account row and the role pivot are really written on (not assumed to be the default). */
    private function accountConnection(): string
    {
        return (new User)->getConnection()->getName();
    }

    private function pivotConnection(): string
    {
        return (new Role)->getConnection()->getName();
    }

    /**
     * Run `$request`, throwing once right after the first executed statement that matches `$pattern`. Returns the
     * transaction level of the account connection seen by that statement (null if it never ran) and whether the
     * injected failure surfaced to the caller.
     *
     * @return array{level: ?int, surfaced: bool}
     */
    private function failAfter(string $pattern, callable $request): array
    {
        $armed = true;
        $level = null;
        $this->begun = [];

        Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use (&$armed) {
            if ($armed) {
                $this->begun[] = $event->connectionName;
            }
        });
        Event::listen(QueryExecuted::class, function (QueryExecuted $event) use (&$armed, &$level, $pattern) {
            if ($armed && preg_match($pattern, $event->sql)) {
                $armed = false;
                $level = $event->connection->transactionLevel();
                throw new \RuntimeException(self::INJECTED);
            }
        });

        $surfaced = false;
        try {
            $request();
        } catch (\Throwable $e) {
            $surfaced = str_contains($e->getMessage(), self::INJECTED)
                || ($e->getPrevious() && str_contains($e->getPrevious()->getMessage(), self::INJECTED));
            if (! $surfaced) {
                throw $e;
            }
        } finally {
            $armed = false;
        }

        return ['level' => $level, 'surfaced' => $surfaced];
    }

    #[Test]
    public function the_account_and_its_role_pivot_live_on_the_connection_the_tests_transact(): void
    {
        // The application transaction must cover the connection actually used by the account and by the pivot.
        $this->assertSame('pgsql', $this->accountConnection());
        $this->assertSame('pgsql', $this->pivotConnection());
    }

    #[Test]
    public function a_failed_create_leaves_no_account_and_no_role_links(): void
    {
        $this->role($this->superName, ['view_user']);
        $plain = $this->role('plain', ['view_user']);
        $manager = $this->user(['view_any_user', 'view_user', 'create_user']);
        $baseline = DB::connection($this->accountConnection())->transactionLevel();
        $pivotRowsBefore = DB::connection($this->pivotConnection())->table('model_has_roles')->count();

        // Fail right after the role pivot insert, which follows the account insert: both writes have happened.
        $result = $this->failAfter('/insert\s+into\s+"?model_has_roles"?/i', function () use ($manager, $plain) {
            Livewire::actingAs($manager)->test(CreateUser::class)
                ->set('data.name', 'ZZZ ATOMICO')
                ->set('data.email', 'zzzatomico@zzz.test')
                ->set('data.password', 'secret-pass-123')
                ->set('data.roles', [$plain->getKey()])
                ->call('create');
        });

        $this->assertTrue($result['surfaced'], 'the injected failure reached the caller');
        // Boundary: the failing statement ran inside an application transaction nested in the test wrapper.
        $this->assertGreaterThan($baseline, $result['level'], 'the account + pivot writes ran inside an application transaction');
        $this->assertContains($this->accountConnection(), $this->begun, 'the transaction was opened on the account connection');
        $this->assertContains($this->pivotConnection(), $this->begun, 'the transaction was opened on the pivot connection');

        // State after the failed request, before teardown (the outer test transaction is still open).
        $this->assertSame($baseline, DB::connection($this->accountConnection())->transactionLevel(), 'the application transaction was closed');
        $this->assertFalse(User::where('email', 'zzzatomico@zzz.test')->exists(), 'no account is left behind');
        $this->assertSame($pivotRowsBefore, DB::connection($this->pivotConnection())->table('model_has_roles')->count(), 'no role link is left behind');
    }

    #[Test]
    public function a_failed_edit_restores_the_prior_account_fields_and_roles(): void
    {
        $this->role($this->superName, ['view_user']);
        $before = $this->role('plain-before', ['view_user']);
        $after = $this->role('plain-after', ['view_user']);
        $manager = $this->user(['view_any_user', 'view_user', 'update_user']);
        $target = $this->user(roles: [$before], attributes: ['name' => 'ZZZ ANTES', 'email' => 'zzzantes@zzz.test']);
        $baseline = DB::connection($this->accountConnection())->transactionLevel();

        // Edit order in Filament v3: the relationship sync runs first, then the account update. Fail right after the
        // account update, so both the role links and the account fields have been mutated when the failure happens.
        $result = $this->failAfter('/update\s+"?users"?\s+set/i', function () use ($manager, $target, $after) {
            Livewire::actingAs($manager)->test(EditUser::class, ['record' => $target->getKey()])
                ->set('data.name', 'ZZZ DESPUES')
                ->set('data.email', 'zzzdespues@zzz.test')
                ->set('data.roles', [$after->getKey()])
                ->call('save');
        });

        $this->assertTrue($result['surfaced'], 'the injected failure reached the caller');
        $this->assertGreaterThan($baseline, $result['level'], 'the pivot + account writes ran inside an application transaction');
        $this->assertContains($this->accountConnection(), $this->begun);
        $this->assertContains($this->pivotConnection(), $this->begun);

        // State after the failed request, before teardown.
        $this->assertSame($baseline, DB::connection($this->accountConnection())->transactionLevel(), 'the application transaction was closed');
        $fresh = User::query()->findOrFail($target->getKey());
        $this->assertSame('ZZZ ANTES', $fresh->name, 'the prior name is restored');
        $this->assertSame('zzzantes@zzz.test', $fresh->email, 'the prior email is restored');
        $this->assertSame(['plain-before'], $fresh->roles()->pluck('name')->all(), 'the prior role links are restored');
    }

    #[Test]
    public function complete_create_and_edit_still_persist_and_commit(): void
    {
        $this->role($this->superName, ['view_user']);
        $plain = $this->role('plain', ['view_user']);
        $other = $this->role('plain-other', ['view_user']);
        $manager = $this->user(['view_any_user', 'view_user', 'create_user', 'update_user']);
        $baseline = DB::connection($this->accountConnection())->transactionLevel();

        Livewire::actingAs($manager)->test(CreateUser::class)
            ->set('data.name', 'ZZZ COMPLETO')
            ->set('data.email', 'zzzcompleto@zzz.test')
            ->set('data.password', 'secret-pass-123')
            ->set('data.roles', [$plain->getKey()])
            ->call('create')
            ->assertHasNoErrors();

        $created = User::where('email', 'zzzcompleto@zzz.test')->firstOrFail();
        $this->assertSame(['plain'], $created->roles()->pluck('name')->all());

        Livewire::actingAs($manager)->test(EditUser::class, ['record' => $created->getKey()])
            ->set('data.name', 'ZZZ COMPLETO EDITADO')
            ->set('data.roles', [$other->getKey()])
            ->call('save')
            ->assertHasNoErrors();

        $edited = $created->fresh();
        $this->assertSame('ZZZ COMPLETO EDITADO', $edited->name);
        $this->assertSame(['plain-other'], $edited->roles()->pluck('name')->all());
        $this->assertSame($baseline, DB::connection($this->accountConnection())->transactionLevel(), 'committed; no transaction left open');
    }

    #[Test]
    public function a_failed_create_with_a_forged_privileged_role_never_writes_it_and_leaves_nothing(): void
    {
        $super = $this->role($this->superName, ['view_user']);
        $plain = $this->role('plain', ['view_user']);
        $manager = $this->user(['view_any_user', 'view_user', 'create_user']);
        $pivotRowsBefore = DB::connection($this->pivotConnection())->table('model_has_roles')->count();

        $connection = DB::connection($this->pivotConnection());
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        try {
            $result = $this->failAfter('/insert\s+into\s+"?model_has_roles"?/i', function () use ($manager, $super, $plain) {
                Livewire::actingAs($manager)->test(CreateUser::class)
                    ->set('data.name', 'ZZZ FORJADO')
                    ->set('data.email', 'zzzforjado@zzz.test')
                    ->set('data.password', 'secret-pass-123')
                    ->set('data.roles', [$super->getKey(), $plain->getKey()])
                    ->call('create');
            });
        } finally {
            $log = $connection->getQueryLog();
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }

        $this->assertTrue($result['surfaced']);
        // The pre-sync authorization still holds: the privileged id is never bound in a pivot write.
        foreach ($log as $entry) {
            if (str_contains($entry['query'], 'model_has_roles') && preg_match('/\b(insert|update)\b/i', $entry['query'])) {
                foreach ($entry['bindings'] as $binding) {
                    $this->assertFalse(is_numeric($binding) && (int) $binding === $super->getKey(), 'the forged privileged role is never written');
                }
            }
        }
        $this->assertFalse(User::where('email', 'zzzforjado@zzz.test')->exists());
        $this->assertSame($pivotRowsBefore, DB::connection($this->pivotConnection())->table('model_has_roles')->count());
    }
}
