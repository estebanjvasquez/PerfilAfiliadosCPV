<?php

namespace App\Filament\Resources\TaxonomyCategoryResource\Pages;

use App\Filament\Resources\TaxonomyCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTaxonomyCategories extends ListRecords
{
    protected static string $resource = TaxonomyCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
