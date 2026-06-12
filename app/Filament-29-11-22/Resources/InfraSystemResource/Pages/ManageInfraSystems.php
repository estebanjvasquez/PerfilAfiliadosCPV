<?php

namespace App\Filament\Resources\InfraSystemResource\Pages;

use App\Filament\Resources\InfraSystemResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageInfraSystems extends ManageRecords
{
    protected static string $resource = InfraSystemResource::class;

    protected function getActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
