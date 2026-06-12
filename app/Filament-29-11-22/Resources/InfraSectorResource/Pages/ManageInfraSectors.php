<?php

namespace App\Filament\Resources\InfraSectorResource\Pages;

use App\Filament\Resources\InfraSectorResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageInfraSectors extends ManageRecords
{
    protected static string $resource = InfraSectorResource::class;

    protected function getActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
