<?php

namespace App\Filament\Resources\TaxonomyTermResource\Pages;

use App\Filament\Resources\Concerns\LogsAuditOnSave;
use App\Filament\Resources\TaxonomyTermResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTaxonomyTerm extends EditRecord
{
    use LogsAuditOnSave;

    protected static string $resource = TaxonomyTermResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
