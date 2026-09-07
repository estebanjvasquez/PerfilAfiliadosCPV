<?php

namespace App\Filament\Resources\TaxonomyGroupResource\Pages;

use App\Filament\Resources\TaxonomyGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTaxonomyGroup extends EditRecord
{
    protected static string $resource = TaxonomyGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
