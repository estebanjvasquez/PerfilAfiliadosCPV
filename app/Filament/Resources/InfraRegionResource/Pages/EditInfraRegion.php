<?php

namespace App\Filament\Resources\InfraRegionResource\Pages;

use App\Filament\Resources\InfraRegionResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\EditRecord;

class EditInfraRegion extends EditRecord
{
    protected static string $resource = InfraRegionResource::class;

    protected function getActions(): array
    {
        return [
            //Actions\DeleteAction::make(),
        ];
    }
}
