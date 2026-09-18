<?php

namespace App\Filament\Resources\TaxonomySourceResource\Pages;

use App\Filament\Resources\Concerns\LogsAuditOnSave;
use App\Filament\Resources\TaxonomySourceResource;
use Filament\Resources\Pages\EditRecord;

class EditTaxonomySource extends EditRecord
{
    use LogsAuditOnSave;

    protected static string $resource = TaxonomySourceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
