<?php

namespace App\Filament\Resources\Shield\RoleResource\Concerns;

use App\Support\Security\RolePermissionSync;
use Filament\Forms\Components\CheckboxList;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * CPV-SEC-01 F-SEC01-R4-01 (Issue #2 `6320241495`): the role row and its permission links are ONE transaction, and the
 * permission write touches only what the form renders (RolePermissionSync).
 *
 * Filament v3.3.55 leaves panel transactions off; this enables them for the role pages only, on the connections the writes
 * use (role model, permission model; Spatie writes `role_has_permissions` through the role). Filament's save()/create()
 * begin before validation and roll back on any Throwable, so a failure after the role update or during the sync leaves
 * neither behind. Spatie forgets its permission cache inside the transaction, where another request could rebuild it from
 * the old rows; it is forgotten again after commit.
 */
trait WritesRenderedPermissionsOnly
{
    public function hasDatabaseTransactions(): bool
    {
        return true;
    }

    /** @return array<int, string> */
    public static function roleWriteConnections(): array
    {
        $roleModel = config('permission.models.role');
        $permissionModel = config('permission.models.permission');

        return array_values(array_unique([
            (new $roleModel)->getConnection()->getName(),
            (new $permissionModel)->getConnection()->getName(),
        ]));
    }

    /**
     * Server-side editable set: the options of the permission fields this page renders now (visible components only;
     * a disabled tab contributes nothing). Built from the schema, never from submitted data.
     *
     * @return array<int, string>
     */
    protected function editablePermissionNames(): array
    {
        return collect($this->form->getFlatComponents())
            ->filter(fn ($component) => $component instanceof CheckboxList)
            ->flatMap(fn (CheckboxList $component) => array_keys($component->getOptions()))
            ->filter(fn ($name) => is_string($name) && $name !== '')
            ->unique()
            ->values()
            ->all();
    }

    protected function writeRenderedPermissions(): void
    {
        RolePermissionSync::apply($this->getRecord(), $this->permissions, $this->editablePermissionNames());
    }

    protected function beginDatabaseTransaction(): void
    {
        foreach (static::roleWriteConnections() as $connection) {
            DB::connection($connection)->beginTransaction();
        }
    }

    protected function commitDatabaseTransaction(): void
    {
        foreach (array_reverse(static::roleWriteConnections()) as $connection) {
            DB::connection($connection)->commit();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function rollBackDatabaseTransaction(): void
    {
        foreach (array_reverse(static::roleWriteConnections()) as $connection) {
            DB::connection($connection)->rollBack();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
