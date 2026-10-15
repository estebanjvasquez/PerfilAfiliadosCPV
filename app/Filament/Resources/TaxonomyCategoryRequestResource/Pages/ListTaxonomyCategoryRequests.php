<?php

namespace App\Filament\Resources\TaxonomyCategoryRequestResource\Pages;

use App\Filament\Pages\CategoryRequestHelpPage;
use App\Filament\Pages\CategoryRequestSettingsPage;
use App\Filament\Resources\TaxonomyCategoryRequestResource;
use App\Services\TaxonomyCategoryRequestService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListTaxonomyCategoryRequests extends ListRecords
{
    protected static string $resource = TaxonomyCategoryRequestResource::class;

    // Sin esto Filament pone en mayúscula cada palabra: "Solicitudes De Categoría".
    protected static ?string $title = 'Solicitudes de categoría';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('configurar')
                ->label('Configuración de solicitudes')
                ->icon('heroicon-o-envelope')
                ->color('gray')
                ->url(CategoryRequestSettingsPage::getUrl()),
            Action::make('ayuda')
                ->label('Ayuda')
                ->icon('heroicon-o-question-mark-circle')
                ->color('gray')
                ->url(CategoryRequestHelpPage::getUrl()),
        ];
    }

    /** Lo que el administrador tiene que saber antes de leer los estados de la tabla. */
    public function getSubheading(): ?string
    {
        $avisos = [];

        if (TaxonomyCategoryRequestService::configuredRecipient() === null) {
            $avisos[] = 'No hay correo responsable configurado: las solicitudes se registran, pero no se avisa a nadie. Configúrelo en «Configuración de solicitudes».';
        }

        if (! TaxonomyCategoryRequestService::transportSendsExternally()) {
            $avisos[] = 'El servidor está en modo de envío simulado: ningún aviso sale hacia un buzón.';
        }

        return $avisos === [] ? null : implode(' ', $avisos);
    }
}
