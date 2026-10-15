<?php

namespace App\Filament\Resources\TaxonomyCategoryRequestResource\Pages;

use App\Filament\Resources\TaxonomyCategoryRequestResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewTaxonomyCategoryRequest extends ViewRecord
{
    protected static string $resource = TaxonomyCategoryRequestResource::class;

    public function getTitle(): string
    {
        return 'Solicitud de categoría #'.$this->getRecord()->getKey();
    }

    protected function getHeaderActions(): array
    {
        return [
            TaxonomyCategoryRequestResource::configureRetry(Action::make('reintentarAviso'))
                // Tras el reintento el estado y el historial cambian: se recarga el registro.
                ->after(fn () => $this->getRecord()->refresh()),
        ];
    }
}
