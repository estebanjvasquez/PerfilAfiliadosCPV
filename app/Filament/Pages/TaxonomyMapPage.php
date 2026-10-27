<?php

namespace App\Filament\Pages;

use App\Support\Taxonomy\TaxonomyMapBuilder;
use Filament\Actions\Action;
use Filament\Pages\Page;

/**
 * CPV-TAXONOMY-MAP: «Mapa de la taxonomía», la Taxonomía CPV publicada completa para que el
 * afiliado se ubique (Grupo > Familia > Categoría). Solo lectura: desde aquí no se agrega ninguna
 * categoría; eso sigue en la pestaña «Categorías CPV» de la empresa.
 *
 * ACCESO. Es exactamente el del manual de usuario (revisión `6191485610`, punto C): `canAccess()`
 * delega en `UserManual::canAccess()`, y la página vive en el mismo panel, con los mismos
 * middleware: Authenticate y la verificación de correo del panel. Si el acceso al manual cambia, este cambia
 * con él. La taxonomía publicada es global: no depende de la empresa del usuario, así que no se lee
 * ningún dato de empresas.
 *
 * NAVEGACIÓN. Sin ítem propio en el menú lateral: se llega desde el manual y desde la pestaña de
 * Categorías CPV, y la página tiene su enlace de vuelta al manual.
 *
 * ESTADO. El árbol se pasa por `getViewData()`, no como propiedad pública, así que no viaja en el
 * estado de Livewire.
 */
class TaxonomyMapPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?string $title = 'Mapa de la taxonomía';

    protected static ?string $slug = 'mapa-de-la-taxonomia';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.taxonomy-map';

    public static function canAccess(): bool
    {
        return UserManual::canAccess();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('volverAlManual')
                ->label('Volver al manual')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(UserManual::getUrl().'#taxonomia-cpv'),
        ];
    }

    protected function getViewData(): array
    {
        $map = app(TaxonomyMapBuilder::class)->build();

        return [
            'groups' => $map['groups'],
            'totals' => $map['totals'],
            'manualUrl' => UserManual::getUrl().'#mapa-taxonomia',
        ];
    }
}
