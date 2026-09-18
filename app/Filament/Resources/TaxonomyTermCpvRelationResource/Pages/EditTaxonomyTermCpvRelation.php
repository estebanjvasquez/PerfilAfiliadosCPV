<?php

namespace App\Filament\Resources\TaxonomyTermCpvRelationResource\Pages;

use App\Filament\Resources\Concerns\LogsAuditOnSave;
use App\Filament\Resources\TaxonomyTermCpvRelationResource;
use App\Models\TaxonomyCategory;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditTaxonomyTermCpvRelation extends EditRecord
{
    use LogsAuditOnSave;

    protected static string $resource = TaxonomyTermCpvRelationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /** Re-resuelve category_id si el admin corrige un cpv_code huérfano (ver TAXV2-2). */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['category_id'] = TaxonomyCategory::query()->where('code', $data['cpv_code'])->value('id');

        return $data;
    }
}
