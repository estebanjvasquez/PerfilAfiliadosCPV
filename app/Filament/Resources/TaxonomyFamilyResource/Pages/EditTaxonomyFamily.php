<?php

namespace App\Filament\Resources\TaxonomyFamilyResource\Pages;

use App\Filament\Resources\Concerns\ManagesInlineTranslations;
use App\Filament\Resources\TaxonomyFamilyResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTaxonomyFamily extends EditRecord
{
    use ManagesInlineTranslations;

    protected static string $resource = TaxonomyFamilyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

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
