<?php

namespace App\Filament\Resources\LegacyServiceCpvRelationResource\Pages;

use App\Filament\Resources\Concerns\LogsAuditOnSave;
use App\Filament\Resources\LegacyServiceCpvRelationResource;
use App\Models\TaxonomyCategory;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLegacyServiceCpvRelation extends EditRecord
{
    use LogsAuditOnSave;

    protected static string $resource = LegacyServiceCpvRelationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['category_id'] = TaxonomyCategory::query()->where('code', $data['cpv_code'])->value('id');

        return $data;
    }
}
