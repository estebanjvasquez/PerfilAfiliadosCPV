<?php

namespace App\Filament\Resources\CompanyCrawlRunResource\Pages;

use App\Filament\Resources\CompanyCrawlRunResource;
use Filament\Resources\Pages\EditRecord;

class EditCompanyCrawlRun extends EditRecord
{
    protected static string $resource = CompanyCrawlRunResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
