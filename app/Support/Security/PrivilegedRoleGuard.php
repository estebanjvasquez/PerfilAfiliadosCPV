<?php

namespace App\Support\Security;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;

/**
 * CPV-SEC-01-R2-ACCOUNT-PATH (Issue #2 `6310273754`, review `6310331211` R2-REV-01): authorization for PRIVILEGE GRANTS
 * at the user write boundary, enforced BEFORE the role relationship is persisted.
 *
 * `create_user` / `update_user` manage ordinary accounts. They are not, by themselves, the authority to grant the
 * privileged role (today: `super_admin`, which Shield treats as all-permissions) or to modify an account that holds it.
 * The role Select on UserResource persists through a relationship, so neither `$fillable` nor the UI stops a forged
 * request. The authoritative control is the relationship save itself (`saveRelationshipsUsing`): the set that reaches
 * `sync()` is reduced to what the current actor may grant, so a forbidden privileged role is NEVER written — there is no
 * post-save cleanup window. Filament already wraps the create/edit save in a database transaction, so the account and
 * its (authorized) roles persist atomically.
 *
 * Rules:
 * - a super admin keeps full control of the roles set;
 * - a non-super-admin can neither ADD nor REMOVE a privileged role: the synced privileged roles equal the record's
 *   current privileged roles, and their non-privileged roles are whatever the form set;
 * - opening (and so modifying) an account that already holds a privileged role is blocked for a non-super-admin, checked
 *   fresh at the write boundary (see UserResource\Pages\EditUser::authorizeAccess).
 *
 * Everything is scoped to these panel pages. There is no global model event, so factories, seeders, the registration
 * flow and other trusted writers are untouched (the owner's guidance in `6310273754` / `6297739082` / `6310331211`).
 */
class PrivilegedRoleGuard
{
    /** Role names that only a super admin may grant, remove or carry into an edited account. */
    public static function privilegedRoleNames(): array
    {
        return array_values(array_filter([
            config('filament-shield.super_admin.name'),
        ]));
    }

    private static function guard(): string
    {
        return config('auth.defaults.guard', 'web');
    }

    /** Ids of the privileged roles (for this guard), queried fresh. */
    public static function privilegedRoleIds(): array
    {
        return Role::query()
            ->whereIn('name', static::privilegedRoleNames())
            ->where('guard_name', static::guard())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** A fresh pivot query, so a cached (empty) roles relation cannot hide a current grant. */
    public static function userHasPrivilegedRole(?User $user): bool
    {
        return $user instanceof User
            && $user->roles()
                ->whereIn('name', static::privilegedRoleNames())
                ->where('guard_name', static::guard())
                ->exists();
    }

    /** Whether the current actor may grant or remove privileged roles. Read fresh at the write boundary. */
    public static function actorMayGrantPrivileged(): bool
    {
        $actor = Auth::user();

        return static::userHasPrivilegedRole($actor instanceof User ? $actor : null);
    }

    /**
     * The role ids that may actually be synced for `$record`, given who is saving, computed BEFORE the sync.
     *
     * A super admin's request is unchanged. For anyone else, privileged role ids are taken out of the requested set and
     * replaced by whatever privileged roles the record already holds (so a non-super-admin can neither add nor remove
     * them). `$record` is null on create (no current roles).
     *
     * @param  array<int|string>  $requestedRoleIds
     * @return array<int> the authorized role ids to sync
     */
    public static function authorizedRoleIds(array $requestedRoleIds, ?Model $record): array
    {
        $requested = array_values(array_unique(array_map('intval', $requestedRoleIds)));

        if (static::actorMayGrantPrivileged()) {
            return $requested;
        }

        $privileged = static::privilegedRoleIds();
        $nonPrivilegedRequested = array_values(array_diff($requested, $privileged));

        $currentPrivileged = [];
        if ($record instanceof Model && $record->exists && method_exists($record, 'roles')) {
            $currentPrivileged = $record->roles()
                ->whereIn('name', static::privilegedRoleNames())
                ->where('guard_name', static::guard())
                ->pluck('roles.id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return array_values(array_unique(array_merge($nonPrivilegedRequested, $currentPrivileged)));
    }

    /** True when the requested set tried to change privileged roles and was reduced (for a user-facing notice). */
    public static function requestWasReduced(array $requestedRoleIds, array $authorizedRoleIds): bool
    {
        sort($requestedRoleIds);
        $req = array_values(array_unique(array_map('intval', $requestedRoleIds)));
        $auth = $authorizedRoleIds;
        sort($req);
        sort($auth);

        return $req !== $auth;
    }
}
