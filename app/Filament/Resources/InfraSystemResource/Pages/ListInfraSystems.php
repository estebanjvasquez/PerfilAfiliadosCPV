<?php

namespace App\Filament\Resources\InfraSystemResource\Pages;

use App\Filament\Resources\InfraSystemResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInfraSystems extends ListRecords
{
    protected static string $resource = InfraSystemResource::class;

    protected function getActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
