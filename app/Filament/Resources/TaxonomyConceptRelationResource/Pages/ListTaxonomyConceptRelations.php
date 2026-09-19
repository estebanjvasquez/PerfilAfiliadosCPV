<?php

namespace App\Filament\Resources\TaxonomyConceptRelationResource\Pages;

use App\Filament\Resources\TaxonomyConceptRelationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTaxonomyConceptRelations extends ListRecords
{
    protected static string $resource = TaxonomyConceptRelationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
