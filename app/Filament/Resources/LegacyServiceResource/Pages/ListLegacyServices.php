<?php

namespace App\Filament\Resources\LegacyServiceResource\Pages;

use App\Filament\Resources\LegacyServiceResource;
use Filament\Resources\Pages\ListRecords;

class ListLegacyServices extends ListRecords
{
    protected static string $resource = LegacyServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
