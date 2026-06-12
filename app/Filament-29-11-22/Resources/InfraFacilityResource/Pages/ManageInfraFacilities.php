<?php

namespace App\Filament\Resources\InfraFacilityResource\Pages;

use App\Filament\Resources\InfraFacilityResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageInfraFacilities extends ManageRecords
{
    protected static string $resource = InfraFacilityResource::class;

    protected function getActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
