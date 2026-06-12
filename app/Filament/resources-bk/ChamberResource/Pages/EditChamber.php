<?php

namespace App\Filament\Resources\ChamberResource\Pages;

use App\Filament\Resources\ChamberResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\EditRecord;

class EditChamber extends EditRecord
{
    protected static string $resource = ChamberResource::class;

    protected function getActions(): array
    {
        return [
            //Actions\DeleteAction::make(),
        ];
    }
}
