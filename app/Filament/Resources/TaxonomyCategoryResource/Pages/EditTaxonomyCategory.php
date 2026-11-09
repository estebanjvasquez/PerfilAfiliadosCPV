<?php

namespace App\Filament\Resources\TaxonomyCategoryResource\Pages;

use App\Filament\Resources\Concerns\ChangesTaxonomyCode;
use App\Filament\Resources\Concerns\ManagesInlineTranslations;
use App\Filament\Resources\TaxonomyCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTaxonomyCategory extends EditRecord
{
    use ChangesTaxonomyCode;
    use ManagesInlineTranslations;

    protected static string $resource = TaxonomyCategoryResource::class;

    public const CHANGE_CODE_LABEL = 'Cambiar código o familia';

    protected function getHeaderActions(): array
    {
        return [
            $this->changeCodeAction(),
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

        // TASK-0015: el guardado normal nunca cambia código, familia ni tipo, aunque alguien envíe esos
        // campos a mano. La única vía es la acción «Cambiar código o familia».
        return $this->withoutLockedCodeFields($data);
    }

    protected function afterSave(): void
    {
        $this->saveTranslations($this->record, $this->pendingTranslations);
    }
}
