<?php

namespace App\Filament\Resources\TaxonomyCategoryResource\Pages;

use App\Filament\Resources\Concerns\ManagesInlineTranslations;
use App\Filament\Resources\TaxonomyCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTaxonomyCategory extends EditRecord
{
    use ManagesInlineTranslations;

    protected static string $resource = TaxonomyCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /** Precarga name_es/name_en/description_es/description_en desde las traducciones ya guardadas. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->mergeExistingTranslationsIntoData($data, $this->getRecord());
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingTranslations = $this->pullTranslationsFromData($data);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->saveTranslations($this->record, $this->pendingTranslations);
    }
}
