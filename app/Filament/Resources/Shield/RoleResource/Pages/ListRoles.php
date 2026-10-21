<?php

namespace App\Filament\Resources\Shield\RoleResource\Pages;

use App\Filament\Resources\Shield\RoleResource;
use BezhanSalleh\FilamentShield\Resources\RoleResource\Pages\ListRoles as ShieldListRoles;

class ListRoles extends ShieldListRoles
{
    protected static string $resource = RoleResource::class;
}
