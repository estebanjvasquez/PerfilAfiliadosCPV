<?php

namespace App\Filament\Resources\TaxonomyTermCpvRelationResource\Pages;

use App\Filament\Resources\TaxonomyTermCpvRelationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTaxonomyTermCpvRelations extends ListRecords
{
    protected static string $resource = TaxonomyTermCpvRelationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
