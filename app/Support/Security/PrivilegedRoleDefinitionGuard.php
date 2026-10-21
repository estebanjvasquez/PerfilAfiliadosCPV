<?php

namespace App\Support\Security;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

/**
 * CPV-SEC-01-R4-ROLE-ADMIN (Issue #2 `6318625636`): the privileged role's IDENTITY cannot be rewritten.
 *
 * The impersonation gate and the account-grant guard recognise the privileged role by its configured name and guard.
 * So, whoever is acting (page, console, any writer), this refuses at the model boundary:
 * - renaming the privileged role, or moving it to another guard;
 * - deleting it;
 * - giving any other role the privileged name, under any guard (including creating one).
 * Its permissions stay editable by a super admin through the role editor. Creating the privileged role when none exists
 * at all, under the default guard (first install / Shield setup), stays possible.
 */
final class PrivilegedRoleDefinitionGuard
{
    public static function register(): void
    {
        $roleModel = config('permission.models.role');

        $roleModel::creating(function (Model $role) {
            if (static::isPrivilegedName($role->name)
                && (static::privilegedRoleExists() || $role->guard_name !== static::guard())) {
                static::refuse();
            }
        });

        $roleModel::updating(function (Model $role) {
            $wasPrivileged = static::isPrivilegedName($role->getOriginal('name'));
            if ($wasPrivileged && $role->isDirty(['name', 'guard_name'])) {
                static::refuse();
            }
            if (! $wasPrivileged && $role->isDirty('name') && static::isPrivilegedName($role->name)) {
                static::refuse();
            }
        });

        $roleModel::deleting(function (Model $role) {
            if (static::isPrivilegedName($role->getOriginal('name') ?? $role->name)) {
                static::refuse();
            }
        });
    }

    private static function isPrivilegedName(?string $name): bool
    {
        return $name !== null && in_array($name, PrivilegedRoleGuard::privilegedRoleNames(), true);
    }

    private static function guard(): string
    {
        return config('auth.defaults.guard', 'web');
    }

    private static function privilegedRoleExists(): bool
    {
        $roleModel = config('permission.models.role');

        return $roleModel::query()->whereIn('name', PrivilegedRoleGuard::privilegedRoleNames())->exists();
    }

    private static function refuse(): never
    {
        throw new AuthorizationException('La definición del rol con privilegios no se puede modificar.');
    }
}
