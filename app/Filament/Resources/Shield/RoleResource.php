<?php

namespace App\Filament\Resources\Shield;

use App\Filament\Resources\Shield\RoleResource\Pages;
use BezhanSalleh\FilamentShield\Resources\RoleResource as ShieldRoleResource;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Component;

/**
 * CPV-SEC-01 F-SEC01-R4-01 (Issue #2 `6320241495`): Shield's role editor, with the app's pages.
 *
 * Shield's supported way to customise its resource is to publish it: when the panel already has a `RoleResource`, the
 * plugin does not register its own (Utils::isResourcePublished). This one keeps Shield's form, table, slug, labels and
 * navigation, and only swaps the pages, so that saving a role writes just the permissions the form renders
 * (RolePermissionSync). Authorization is unchanged: RolePolicy (only a current super_admin administers roles).
 *
 * TASK-0022 / F-SEC01-T21-01 (Issue #2 `6829151868`): the Role section shows the real `*_role` names (key pinned by
 * App\Support\Security\RolePermissionIdentifier) READ-ONLY, for every role. Role administration belongs to super_admin
 * only (owner rule 2026-10-21), so the editor must neither grant `*_role` to another role (it would undo the R4
 * containment, even if RolePolicy still refused) nor let a super_admin uncheck them from the privileged role and lock
 * every administrator out. A disabled field is not part of the editable set (WritesRenderedPermissionsOnly), so these
 * names are always preserved as they are and any submitted value for them is ignored.
 */
class RoleResource extends ShieldRoleResource
{
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'view' => Pages\ViewRole::route('/{record}'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }

    public static function getCheckBoxListComponentForResource(array $entity): Component
    {
        $component = parent::getCheckBoxListComponentForResource($entity);

        if (is_a($entity['fqcn'] ?? '', static::class, true) && $component instanceof CheckboxList) {
            $component
                ->disabled()
                ->bulkToggleable(false)
                ->helperText('Solo lectura: la administración de roles es exclusiva de super_admin y no se cambia desde este editor.');
        }

        return $component;
    }
}
