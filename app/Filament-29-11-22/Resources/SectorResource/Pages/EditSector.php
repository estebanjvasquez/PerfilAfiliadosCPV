<?php

namespace App\Filament\Resources\SectorResource\Pages;

use Filament\Pages\Actions;
use Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\SectorResource;

class EditSector extends EditRecord
{
    protected static string $resource = SectorResource::class;

    protected function getActions(): array
    {
        return [
            //Actions\Action::make('impersonate')->action('impersonate'),
            //Actions\DeleteAction::make(),
        ];
    }
}
