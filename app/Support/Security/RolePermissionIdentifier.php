<?php

namespace App\Support\Security;

use App\Filament\Resources\Shield\RoleResource;
use BezhanSalleh\FilamentShield\FilamentShield;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;

/**
 * TASK-0022 / F-SEC01-T21-01 (Issue #2 `6829151868`): the role editor's "Role" section must offer the permission names
 * RolePolicy really checks (`view_any_role`, `update_role`, ...).
 *
 * Shield 3.9.10 derives a resource's permission key from its class name, after the last `Resources\`. Shield's own
 * `BezhanSalleh\FilamentShield\Resources\RoleResource` gives `role`; the published app resource lives in
 * `App\Filament\Resources\Shield\` (F-SEC01-R4-01), so the same rule gives `shield::role` and the section rendered six
 * names that do not exist. The key is pinned back to `role` through Shield's own hook (configurePermissionIdentifierUsing).
 * Every other resource keeps exactly Shield's default key (FilamentShield::getDefaultPermissionIdentifier, same steps).
 *
 * Shield binds its service as a SCOPED instance, so a callback set once on it would be lost when scoped instances are
 * flushed (queue worker between jobs). The hook is therefore applied on every resolution (afterResolving), and also to an
 * instance resolved before this runs.
 */
final class RolePermissionIdentifier
{
    public const ROLE_ENTITY = 'role';

    public static function register(Application $app): void
    {
        $configure = static function (FilamentShield $shield): void {
            $shield->configurePermissionIdentifierUsing(
                static fn (string $resource): string => RolePermissionIdentifier::for($resource)
            );
        };

        $app->afterResolving('filament-shield', $configure);

        if ($app->resolved('filament-shield')) {
            $configure($app->make('filament-shield'));
        }
    }

    public static function for(string $resource): string
    {
        if (is_a($resource, RoleResource::class, true)) {
            return self::ROLE_ENTITY;
        }

        // Shield 3.9.10 FilamentShield::getDefaultPermissionIdentifier(), unchanged.
        return Str::of($resource)
            ->afterLast('Resources\\')
            ->beforeLast('Resource')
            ->replace('\\', '')
            ->snake()
            ->replace('_', '::')
            ->toString();
    }
}
