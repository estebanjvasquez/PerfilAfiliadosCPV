<?php

namespace App\Filament\Resources\TaxonomyFamilyResource\Pages;

use App\Filament\Resources\TaxonomyFamilyResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTaxonomyFamilies extends ListRecords
{
    protected static string $resource = TaxonomyFamilyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
