<?php

namespace App\Filament\Resources\Shield\RoleResource\Pages;

use App\Filament\Resources\Shield\RoleResource;
use App\Filament\Resources\Shield\RoleResource\Concerns\WritesRenderedPermissionsOnly;
use BezhanSalleh\FilamentShield\Resources\RoleResource\Pages\EditRole as ShieldEditRole;

class EditRole extends ShieldEditRole
{
    use WritesRenderedPermissionsOnly;

    protected static string $resource = RoleResource::class;

    /** Replaces Shield's create-and-sync of every submitted value (F-SEC01-R4-01). */
    protected function afterSave(): void
    {
        $this->writeRenderedPermissions();
    }
}
