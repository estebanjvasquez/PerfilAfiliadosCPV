<?php

namespace App\Filament\Resources\TaxonomyTermCpvRelationResource\Pages;

use App\Filament\Resources\TaxonomyTermCpvRelationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTaxonomyTermCpvRelation extends CreateRecord
{
    protected static string $resource = TaxonomyTermCpvRelationResource::class;

    /** Resuelve category_id automáticamente por cpv_code al crear a mano desde el panel. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['category_id'] = \App\Models\TaxonomyCategory::query()
            ->where('code', $data['cpv_code'])
            ->value('id');

        return $data;
    }
}
