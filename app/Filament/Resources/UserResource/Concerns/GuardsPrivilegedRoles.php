<?php

namespace App\Filament\Resources\UserResource\Concerns;

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;

/**
 * CPV-SEC-01-R2-ACCOUNT-PATH (Issue #2 `6310273754`): authorization for PRIVILEGE GRANTS at the user write boundary.
 *
 * Having `create_user` / `update_user` lets staff manage ordinary accounts. It is NOT, by itself, the authority to grant
 * a PRIVILEGED role (today: `super_admin`, which Shield treats as all-permissions) or to modify an account that already
 * holds one. The role Select on UserResource saves through a relationship, separate from mass assignment, so neither the
 * model's `$fillable` nor hiding the option in the UI stops a forged request. This guard enforces the rule server-side,
 * after the record and its relationships are saved, by reconciling the privileged roles the current actor may not grant.
 *
 * Only a super admin may grant or remove a privileged role, or edit a user who holds one. Everything else a permitted
 * staff user could do before still works: creating and editing ordinary users, and assigning non-privileged roles.
 *
 * All checks hit the database directly (the role pivot), not the model's in-memory roles relation, which the form may
 * have cached empty before the save. Whether the ACTOR is a privileged grantor is snapshotted BEFORE the write, so a
 * user cannot elevate itself in the same save and then be read back as already privileged.
 *
 * Scoped to these panel pages on purpose: it is not a global model event, so factories, seeders, registration and other
 * trusted writers are untouched (the owner's guidance in `6310273754` / `6297739082`).
 */
trait GuardsPrivilegedRoles
{
    /** Set in authorizeAccess() (edit) before any write; whether the actor may grant privileged roles this request. */
    protected ?bool $actorMayGrantPrivileged = null;

    /** Role names that only a super admin may grant, remove or carry into an edited account. */
    protected function privilegedRoleNames(): array
    {
        return array_values(array_filter([
            config('filament-shield.super_admin.name'),
        ]));
    }

    private function privilegedGuard(): string
    {
        return config('auth.defaults.guard', 'web');
    }

    /** A fresh pivot query, so a cached (empty) roles relation on the form's record cannot hide a just-synced grant. */
    private function userHasPrivilegedRole(?User $user): bool
    {
        return $user instanceof User
            && $user->roles()->whereIn('name', $this->privilegedRoleNames())->where('guard_name', $this->privilegedGuard())->exists();
    }

    protected function actorIsSuperAdmin(): bool
    {
        return $this->actorMayGrantPrivileged
            ??= $this->userHasPrivilegedRole(Auth::user() instanceof User ? Auth::user() : null);
    }

    /** Call at the start of a save request (before the write) so self-elevation cannot flip this snapshot. */
    protected function snapshotActorGrantAuthority(): void
    {
        $this->actorMayGrantPrivileged = $this->userHasPrivilegedRole(Auth::user() instanceof User ? Auth::user() : null);
    }

    /**
     * Before editing: a non-super-admin cannot open (and therefore cannot modify) an account that already holds a
     * privileged role. A super admin's account is managed only by another super admin.
     */
    protected function assertMayManagePrivilegedTarget(User $target): void
    {
        if ($this->actorIsSuperAdmin()) {
            return;
        }
        if ($this->userHasPrivilegedRole($target)) {
            abort(403);
        }
    }

    /**
     * After save: if the actor is not a super admin, detach any privileged role that reached the record (a forged
     * `roles` relationship, a crafted id, or the option left in the payload). The ordinary roles stay, so a permitted
     * staff save still succeeds; the attempt to elevate is dropped and reported.
     */
    protected function stripPrivilegedRoleGrants(User $record): void
    {
        if ($this->actorIsSuperAdmin()) {
            return;
        }
        $grantedIds = $record->roles()
            ->whereIn('name', $this->privilegedRoleNames())
            ->where('guard_name', $this->privilegedGuard())
            ->pluck('roles.id')
            ->all();
        if ($grantedIds === []) {
            return;
        }
        $record->roles()->detach($grantedIds);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $record->unsetRelation('roles');

        Notification::make()
            ->warning()
            ->title('No se asignó un rol con privilegios')
            ->body('Solo un Super Admin puede otorgar ese rol. El resto de los cambios se guardó.')
            ->persistent()
            ->send();
    }
}
