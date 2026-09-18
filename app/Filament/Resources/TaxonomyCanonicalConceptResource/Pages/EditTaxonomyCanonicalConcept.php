<?php

namespace App\Filament\Resources\TaxonomyCanonicalConceptResource\Pages;

use App\Filament\Resources\Concerns\LogsAuditOnSave;
use App\Filament\Resources\TaxonomyCanonicalConceptResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTaxonomyCanonicalConcept extends EditRecord
{
    use LogsAuditOnSave;

    protected static string $resource = TaxonomyCanonicalConceptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
