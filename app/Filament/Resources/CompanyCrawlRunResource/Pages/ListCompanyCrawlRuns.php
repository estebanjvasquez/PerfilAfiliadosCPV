<?php

namespace App\Filament\Resources\CompanyCrawlRunResource\Pages;

use App\Filament\Resources\CompanyCrawlRunResource;
use Filament\Resources\Pages\ListRecords;

class ListCompanyCrawlRuns extends ListRecords
{
    protected static string $resource = CompanyCrawlRunResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
