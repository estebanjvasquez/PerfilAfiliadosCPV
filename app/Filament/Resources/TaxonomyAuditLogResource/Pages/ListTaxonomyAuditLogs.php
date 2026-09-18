<?php

namespace App\Filament\Resources\TaxonomyAuditLogResource\Pages;

use App\Filament\Resources\TaxonomyAuditLogResource;
use Filament\Resources\Pages\ListRecords;

class ListTaxonomyAuditLogs extends ListRecords
{
    protected static string $resource = TaxonomyAuditLogResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
