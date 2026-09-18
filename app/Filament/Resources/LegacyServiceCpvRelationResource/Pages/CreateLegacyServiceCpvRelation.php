<?php

namespace App\Filament\Resources\LegacyServiceCpvRelationResource\Pages;

use App\Filament\Resources\LegacyServiceCpvRelationResource;
use App\Models\TaxonomyCategory;
use Filament\Resources\Pages\CreateRecord;

class CreateLegacyServiceCpvRelation extends CreateRecord
{
    protected static string $resource = LegacyServiceCpvRelationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['category_id'] = TaxonomyCategory::query()->where('code', $data['cpv_code'])->value('id');

        return $data;
    }
}
