<?php

namespace App\Support\Security;

use Illuminate\Database\Eloquent\Model;

/**
 * CPV-SEC-01 F-SEC01-R4-01 (Issue #2 `6320241495`): the role editor writes ONLY the permissions it renders.
 *
 * Shield 3.9.10's EditRole::afterSave / CreateRole::afterCreate turn every submitted checkbox value into a permission
 * (firstOrCreate) and then syncPermissions() with exactly that list. So:
 * - a permission the form does not render (custom tab disabled, a page/widget/resource that is no longer registered, a
 *   legacy name) is silently detached whenever the role is saved;
 * - Filament's CheckboxList does not check submitted values against its options, so a forged name is created and granted.
 *
 * Here the result is computed at the write boundary, from fresh persisted state:
 *   final = (submitted ∩ editable) ∪ (permissions the role has NOW, outside editable)
 * `editable` is the server-side set of options the page's visible permission fields render, never a client list. The
 * role row is locked first, so two saves of the same role are serialized and the preserved part is read after the other
 * one commits. Only an editable name may be created (Shield's behaviour for a newly registered resource), and always under
 * the role's persisted guard, not the submitted one. The caller runs this inside the page's write transaction.
 */
final class RolePermissionSync
{
    /**
     * @param  iterable<mixed>  $submitted  values collected from the form (checkbox states)
     * @param  array<int, string>  $editable  permission names the editor renders for this role
     */
    public static function apply(Model $role, iterable $submitted, array $editable): void
    {
        $editableSet = array_flip($editable);

        $fresh = $role->newQuery()->whereKey($role->getKey())->lockForUpdate()->firstOrFail();

        $requested = collect($submitted)->flatten()
            ->filter(fn ($name) => is_string($name) && isset($editableSet[$name]))
            ->unique()
            ->values();

        $permissionModel = config('permission.models.permission');
        $granted = $requested->map(fn (string $name) => $permissionModel::firstOrCreate([
            'name' => $name,
            'guard_name' => $fresh->guard_name,
        ]));

        $preserved = $fresh->permissions()->get()->reject(fn (Model $permission) => isset($editableSet[$permission->name]));

        $fresh->syncPermissions($granted->merge($preserved)->unique(fn (Model $permission) => $permission->getKey())->values()->all());

        $role->unsetRelation('permissions');
    }
}
