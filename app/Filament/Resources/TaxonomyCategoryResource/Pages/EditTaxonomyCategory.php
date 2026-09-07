<?php

namespace App\Filament\Resources\TaxonomyCategoryResource\Pages;

use App\Filament\Resources\TaxonomyCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTaxonomyCategory extends EditRecord
{
    protected static string $resource = TaxonomyCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
