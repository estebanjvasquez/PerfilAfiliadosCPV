<?php

namespace App\Filament\Resources\Shield\RoleResource\Pages;

use App\Filament\Resources\Shield\RoleResource;
use BezhanSalleh\FilamentShield\Resources\RoleResource\Pages\CreateRole as ShieldCreateRole;

class CreateRole extends ShieldCreateRole
{
    protected static string $resource = RoleResource::class;
}
