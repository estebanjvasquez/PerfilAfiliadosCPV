<?php

namespace App\Filament\Resources\TaxonomyCanonicalConceptResource\Pages;

use App\Filament\Resources\TaxonomyCanonicalConceptResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTaxonomyCanonicalConcepts extends ListRecords
{
    protected static string $resource = TaxonomyCanonicalConceptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
