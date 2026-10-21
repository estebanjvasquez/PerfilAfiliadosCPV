<?php

namespace App\Filament\Resources\UserResource\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * CPV-SEC-01-R2-ACCOUNT-PATH, second review of `6310331211` (R2-REV-05): the account row and its role pivot are written
 * as ONE application transaction on CreateUser and EditUser.
 *
 * Filament v3.3.55 leaves panel database transactions OFF by default (Panel::hasDatabaseTransactions() is false), and
 * its page hooks are no-ops until they are enabled. This trait enables them for these two pages only (the panel
 * default is unchanged), and opens the transaction on the connections the writes actually use, not on whatever the
 * default connection happens to be: the User model's connection (account row) and the role model's connection (Spatie
 * writes the `model_has_roles` pivot through the related role query). Today both resolve to the same connection.
 *
 * Filament's create()/save() call begin before validation and roll back on any Throwable, so a failure after the
 * account write or after the role sync leaves neither behind. The privileged-role authorization still runs before the
 * sync (PrivilegedRoleGuard); this transaction supplements it, it does not replace it.
 */
trait UsesAccountWriteTransaction
{
    protected ?bool $hasDatabaseTransactions = true;

    /** @return array<int, string> distinct connection names of the account row and the role pivot */
    public static function accountWriteConnections(): array
    {
        $roleModel = config('permission.models.role');

        return array_values(array_unique([
            (new User)->getConnection()->getName(),
            (new $roleModel)->getConnection()->getName(),
        ]));
    }

    protected function beginDatabaseTransaction(): void
    {
        if (! $this->hasDatabaseTransactions()) {
            return;
        }

        foreach (static::accountWriteConnections() as $connection) {
            DB::connection($connection)->beginTransaction();
        }
    }

    protected function commitDatabaseTransaction(): void
    {
        if (! $this->hasDatabaseTransactions()) {
            return;
        }

        foreach (array_reverse(static::accountWriteConnections()) as $connection) {
            DB::connection($connection)->commit();
        }
    }

    protected function rollBackDatabaseTransaction(): void
    {
        if (! $this->hasDatabaseTransactions()) {
            return;
        }

        foreach (array_reverse(static::accountWriteConnections()) as $connection) {
            DB::connection($connection)->rollBack();
        }
    }
}
