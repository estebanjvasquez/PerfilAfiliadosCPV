<?php

namespace App\Filament\Resources\InfraRegionResource\Pages;

use App\Filament\Resources\InfraRegionResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Validation\ValidationException;
use Filament\Notifications\Notification;

class ManageInfraRegions extends ManageRecords
{
    protected static string $resource = InfraRegionResource::class;

    protected function getActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    /*  protected function onValidationError(ValidationException $exception): void
    {

        Notification::make()
            ->title($exception->getMessage())
            ->danger()
            ->send();
    } */
}
