<?php

namespace App\Filament\Resources\TaxonomyCandidateTermResource\Pages;

use App\Filament\Resources\Concerns\LogsAuditOnSave;
use App\Filament\Resources\TaxonomyCandidateTermResource;
use Filament\Resources\Pages\EditRecord;

class EditTaxonomyCandidateTerm extends EditRecord
{
    use LogsAuditOnSave;

    protected static string $resource = TaxonomyCandidateTermResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
