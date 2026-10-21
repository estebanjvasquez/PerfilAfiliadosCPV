<?php

namespace App\Filament\Resources\Shield;

use App\Filament\Resources\Shield\RoleResource\Pages;
use BezhanSalleh\FilamentShield\Resources\RoleResource as ShieldRoleResource;

/**
 * CPV-SEC-01 F-SEC01-R4-01 (Issue #2 `6320241495`): Shield's role editor, with the app's pages.
 *
 * Shield's supported way to customise its resource is to publish it: when the panel already has a `RoleResource`, the
 * plugin does not register its own (Utils::isResourcePublished). This one keeps Shield's form, table, slug, labels and
 * navigation, and only swaps the pages, so that saving a role writes just the permissions the form renders
 * (RolePermissionSync). Authorization is unchanged: RolePolicy (only a current super_admin administers roles).
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
}
