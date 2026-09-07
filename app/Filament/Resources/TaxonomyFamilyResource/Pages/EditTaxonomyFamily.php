<?php

namespace App\Filament\Resources\TaxonomyFamilyResource\Pages;

use App\Filament\Resources\TaxonomyFamilyResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTaxonomyFamily extends EditRecord
{
    protected static string $resource = TaxonomyFamilyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
