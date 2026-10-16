<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;

/**
 * CPV-HOME-BRANDING-AND-LAYOUT (Issue #2 `6210321522`): la página de inicio del panel.
 *
 * Reemplaza al `Filament\Pages\Dashboard` registrado en el panel, que es la vía que Filament ofrece para
 * personalizarlo: misma ruta (`/`), mismo nombre de ruta, mismos widgets y mismo acceso. Lo único que
 * cambia es la presentación:
 *  - «Escritorio» pasa a llamarse «Inicio» (menú lateral y título del navegador);
 *  - el título visible es el logo existente de Perfil de Afiliados, dentro del mismo `<h1>` de Filament,
 *    con «Perfil de Afiliados» como texto alternativo (es el nombre accesible del encabezado);
 *  - las tarjetas se ordenan en 3 columnas en pantallas anchas: la cuenta (1) junto a la ayuda (2), y los
 *    avisos debajo a todo el ancho. En pantallas más angostas se apilan en ese mismo orden.
 *
 * Los estilos del logo y de los avisos están en `filament.pages.home-styles`, que el panel solo inyecta en
 * esta página (render hook con scope).
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Inicio';

    public const LOGO_PATH = 'images/Perfil-Afiliados.png';

    public function getHeading(): string|Htmlable
    {
        return view('filament.pages.home-logo', ['src' => asset(self::LOGO_PATH)]);
    }

    public function getColumns(): int|string|array
    {
        return ['default' => 1, 'lg' => 3];
    }
}
