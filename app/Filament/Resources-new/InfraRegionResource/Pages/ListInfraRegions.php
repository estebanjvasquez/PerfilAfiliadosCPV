<?php

namespace App\Filament\Resources\InfraRegionResource\Pages;

use App\Filament\Resources\InfraRegionResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInfraRegions extends ListRecords
{
    protected static string $resource = InfraRegionResource::class;

    protected function getActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
