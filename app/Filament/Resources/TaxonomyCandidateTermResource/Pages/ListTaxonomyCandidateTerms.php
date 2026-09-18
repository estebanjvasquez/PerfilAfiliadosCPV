<?php

namespace App\Filament\Resources\TaxonomyCandidateTermResource\Pages;

use App\Filament\Resources\TaxonomyCandidateTermResource;
use Filament\Resources\Pages\ListRecords;

class ListTaxonomyCandidateTerms extends ListRecords
{
    protected static string $resource = TaxonomyCandidateTermResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
