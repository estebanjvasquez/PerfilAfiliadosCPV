<?php

namespace App\Filament\Resources\ManagementResource\Pages;

use App\Filament\Resources\ManagementResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\ListRecords;

class ListManagement extends ListRecords
{
    protected static string $resource = ManagementResource::class;

    //public static ?string $label = 'Sistemas de Gestión';

    protected function getActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
