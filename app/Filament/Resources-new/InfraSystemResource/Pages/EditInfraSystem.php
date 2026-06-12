<?php

namespace App\Filament\Resources\InfraSystemResource\Pages;

use App\Filament\Resources\InfraSystemResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\EditRecord;

class EditInfraSystem extends EditRecord
{
    protected static string $resource = InfraSystemResource::class;

    protected function getActions(): array
    {
        return [
            //Actions\DeleteAction::make(),
        ];
    }
}
