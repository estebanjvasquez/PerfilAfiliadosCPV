<?php

namespace App\Filament\Resources\TaxonomyFamilyResource\Pages;

use App\Filament\Resources\TaxonomyFamilyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTaxonomyFamily extends CreateRecord
{
    protected static string $resource = TaxonomyFamilyResource::class;

    /** Mismo bug/fix que CreateTaxonomyCategory::mutateFormDataBeforeCreate() — ver ese docblock. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['source_version'] = 'panel';

        return $data;
    }
}
