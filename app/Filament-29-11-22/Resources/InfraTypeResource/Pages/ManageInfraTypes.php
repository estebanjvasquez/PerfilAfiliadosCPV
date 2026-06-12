<?php

namespace App\Filament\Resources\InfraTypeResource\Pages;

use App\Filament\Resources\InfraTypeResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageInfraTypes extends ManageRecords
{
    protected static string $resource = InfraTypeResource::class;

    protected function getActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
