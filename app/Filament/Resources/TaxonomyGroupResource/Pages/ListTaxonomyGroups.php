<?php

namespace App\Filament\Resources\TaxonomyGroupResource\Pages;

use App\Filament\Resources\TaxonomyGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTaxonomyGroups extends ListRecords
{
    protected static string $resource = TaxonomyGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
