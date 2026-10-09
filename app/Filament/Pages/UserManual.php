<?php

namespace App\Filament\Pages;

use App\Support\UserManual\UserManualRenderer;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * TASK-0010B: manual de usuario en línea, dentro del panel autenticado.
 *
 * Acceso: cualquier usuario autenticado del panel (afiliados y administradores). A propósito NO usa
 * `HasPageShield`: con Shield, la página quedaría oculta hasta que alguien le asigne un permiso
 * `page_UserManual` a cada rol, y el manual tiene que estar disponible para todo afiliado. El
 * acceso de invitados lo bloquea el middleware `Authenticate` del panel (más `canAccess()` acá).
 *
 * El contenido vive en `resources/manual/manual_usuario.md` (ver `UserManualRenderer`).
 */
class UserManual extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Manual de usuario';

    protected static ?string $title = 'Manual de usuario';

    protected static ?string $slug = 'manual-de-usuario';

    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.pages.user-manual';

    public static function canAccess(): bool
    {
        return Auth::check();
    }

    protected function getViewData(): array
    {
        $manual = app(UserManualRenderer::class)->render();

        return [
            'manualHtml' => $manual['html'],
            'toc' => $manual['toc'],
            'tocAnchor' => UserManualRenderer::TOC_ANCHOR,
        ];
    }
}
