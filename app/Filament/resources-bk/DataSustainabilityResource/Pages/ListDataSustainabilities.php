<?php

namespace App\Filament\Resources\DataSustainabilityResource\Pages;

use App\Filament\Resources\DataSustainabilityResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDataSustainabilities extends ListRecords
{
    protected static string $resource = DataSustainabilityResource::class;

    protected function getActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
