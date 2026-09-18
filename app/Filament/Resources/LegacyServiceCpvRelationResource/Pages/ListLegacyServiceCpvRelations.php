<?php

namespace App\Filament\Resources\LegacyServiceCpvRelationResource\Pages;

use App\Filament\Resources\LegacyServiceCpvRelationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLegacyServiceCpvRelations extends ListRecords
{
    protected static string $resource = LegacyServiceCpvRelationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
