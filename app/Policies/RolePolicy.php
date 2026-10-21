<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Security\PrivilegedRoleGuard;
use Spatie\Permission\Models\Role;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * CPV-SEC-01-R4-ROLE-ADMIN (Issue #2 `6318625636`, owner rule 2026-10-21): role administration is exclusively for a
 * user who CURRENTLY holds the configured super_admin role. A generic permission (`update_role`, a direct grant, or user
 * management) is never enough by itself. Every ability of the role editor (Shield's RoleResource: list, view, permission
 * editor, create, edit, delete, bulk delete, and the rest of the prefixes) goes through here.
 *
 * The super_admin check is a fresh pivot query (PrivilegedRoleGuard::userHasPrivilegedRole), so a page opened before the
 * role was removed is refused on its next request; the explicit permission is still required too, because Shield's gate
 * bypass is off in this project (`define_via_gate = false`). The privileged role's identity itself is protected at the
 * model boundary (App\Support\Security\PrivilegedRoleDefinitionGuard).
 */
class RolePolicy
{
    use HandlesAuthorization;

    private function administers(User $user, string $permission): bool
    {
        return PrivilegedRoleGuard::userHasPrivilegedRole($user) && $user->can($permission);
    }

    public function viewAny(User $user): bool
    {
        return $this->administers($user, 'view_any_role');
    }

    public function view(User $user, Role $role): bool
    {
        return $this->administers($user, 'view_role');
    }

    public function create(User $user): bool
    {
        return $this->administers($user, 'create_role');
    }

    public function update(User $user, Role $role): bool
    {
        return $this->administers($user, 'update_role');
    }

    public function delete(User $user, Role $role): bool
    {
        return $this->administers($user, 'delete_role');
    }

    public function deleteAny(User $user): bool
    {
        return $this->administers($user, 'delete_any_role');
    }

    public function forceDelete(User $user, Role $role): bool
    {
        return $this->administers($user, 'force_delete_role');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $this->administers($user, 'force_delete_any_role');
    }

    public function restore(User $user, Role $role): bool
    {
        return $this->administers($user, 'restore_role');
    }

    public function restoreAny(User $user): bool
    {
        return $this->administers($user, 'restore_any_role');
    }

    public function replicate(User $user, Role $role): bool
    {
        return $this->administers($user, 'replicate_role');
    }

    public function reorder(User $user): bool
    {
        return $this->administers($user, 'reorder_role');
    }
}
