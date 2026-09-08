<?php

namespace App\Filament\Resources\TaxonomyGroupResource\Pages;

use App\Filament\Resources\TaxonomyGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTaxonomyGroup extends CreateRecord
{
    protected static string $resource = TaxonomyGroupResource::class;

    /** Mismo bug/fix que CreateTaxonomyCategory::mutateFormDataBeforeCreate() — ver ese docblock. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['source_version'] = 'panel';

        return $data;
    }
}
