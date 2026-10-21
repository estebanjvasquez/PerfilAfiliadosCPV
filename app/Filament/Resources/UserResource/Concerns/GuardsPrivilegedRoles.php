<?php

namespace App\Filament\Resources\UserResource\Concerns;

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;

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
 * This is scoped to these panel pages on purpose. It is not a global model event, so factories, seeders, the
 * registration flow and other trusted writers are untouched (the owner's guidance in `6310273754` / `6297739082`).
 */
trait GuardsPrivilegedRoles
{
    /** Role names that only a super admin may grant, remove or carry into an edited account. */
    protected function privilegedRoleNames(): array
    {
        return array_values(array_filter([
            config('filament-shield.super_admin.name'),
        ]));
    }

    protected function actorIsSuperAdmin(): bool
    {
        $actor = Auth::user();

        return $actor instanceof User && $actor->hasRole($this->privilegedRoleNames());
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
        if ($target->hasRole($this->privilegedRoleNames())) {
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
        $privileged = Role::query()
            ->whereIn('name', $this->privilegedRoleNames())
            ->where('guard_name', $record->guard_name ?? config('auth.defaults.guard'))
            ->get();
        $granted = $privileged->filter(fn (Role $role) => $record->hasRole($role));
        if ($granted->isEmpty()) {
            return;
        }
        foreach ($granted as $role) {
            $record->removeRole($role);
        }
        Notification::make()
            ->warning()
            ->title('No se asignó un rol con privilegios')
            ->body('Solo un Super Admin puede otorgar ese rol. El resto de los cambios se guardó.')
            ->persistent()
            ->send();
    }
}
