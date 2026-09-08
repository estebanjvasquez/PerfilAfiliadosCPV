<?php

namespace App\Filament\Resources\TaxonomyGroupResource\Pages;

use App\Filament\Resources\Concerns\ManagesInlineTranslations;
use App\Filament\Resources\TaxonomyGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTaxonomyGroup extends CreateRecord
{
    use ManagesInlineTranslations;

    protected static string $resource = TaxonomyGroupResource::class;

    /** Mismo bug/fix que CreateTaxonomyCategory::mutateFormDataBeforeCreate() — ver ese docblock. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['source_version'] = 'panel';
        $this->pendingTranslations = $this->pullTranslationsFromData($data);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->saveTranslations($this->record, $this->pendingTranslations);
    }
}
