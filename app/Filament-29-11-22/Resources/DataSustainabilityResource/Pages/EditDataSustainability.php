<?php

namespace App\Filament\Resources\DataSustainabilityResource\Pages;

use App\Filament\Resources\DataSustainabilityResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDataSustainability extends EditRecord
{
    protected static string $resource = DataSustainabilityResource::class;

    protected function getActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
