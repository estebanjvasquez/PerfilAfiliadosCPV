<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\TaxonomyMapPage;
use App\Filament\Pages\UserManual;
use Filament\Widgets\Widget;

/**
 * CPV-HOME-USEFUL-HELP (Issue #2 `6195707471`): reemplaza en el Escritorio la tarjeta promocional
 * de Filament (`FilamentInfoWidget`: logo, versión, enlaces a su documentación y a GitHub) por
 * accesos que el afiliado sí necesita: el manual de usuario y el mapa de la taxonomía.
 *
 * Por qué aporta y no es relleno: el mapa no tiene ítem en el menú lateral (se llega desde el
 * manual o la pestaña de Categorías CPV), y en el teléfono el menú lateral está oculto.
 *
 * Sin consultas a la base, sin métricas y sin servicios externos. Ocupa el mismo lugar que la
 * tarjeta que reemplaza (`$sort = -2`, junto a la de la cuenta) y no es diferida, igual que ella.
 * URLs generadas por las páginas y acceso según sus propias comprobaciones: el widget solo se ve
 * si el usuario puede abrir el manual, y el enlace al mapa solo si puede abrir el mapa.
 */
class ProfileHelpWidget extends Widget
{
    protected static ?int $sort = -2;

    protected static bool $isLazy = false;

    protected static string $view = 'filament.widgets.profile-help-widget';

    public static function canView(): bool
    {
        return UserManual::canAccess();
    }

    protected function getViewData(): array
    {
        return [
            'manualUrl' => UserManual::getUrl(),
            'mapUrl' => TaxonomyMapPage::canAccess() ? TaxonomyMapPage::getUrl() : null,
        ];
    }
}
