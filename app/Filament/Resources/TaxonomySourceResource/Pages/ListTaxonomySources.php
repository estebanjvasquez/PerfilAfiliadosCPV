<?php

namespace App\Filament\Resources\TaxonomySourceResource\Pages;

use App\Filament\Resources\TaxonomySourceResource;
use Filament\Resources\Pages\ListRecords;

class ListTaxonomySources extends ListRecords
{
    protected static string $resource = TaxonomySourceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
