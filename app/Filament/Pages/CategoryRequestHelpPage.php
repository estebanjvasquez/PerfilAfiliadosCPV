<?php

namespace App\Filament\Pages;

use App\Support\UserManual\UserManualRenderer;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * TASK-0010C: ayuda para ADMINISTRADORES sobre las solicitudes de revisión de categoría.
 *
 * Va aparte del manual de usuario a propósito. El manual lo leen todos los afiliados, y esta ayuda
 * explica estados de aviso, modos de envío y reintentos que no son asunto del afiliado. Solo Super
 * Admin, igual que las pantallas que describe. Se llega desde "Solicitudes de categoría" y
 * "Configuración de solicitudes"; no ocupa un lugar propio en el menú.
 *
 * Reutiliza el renderizador del manual (mismo Markdown seguro, mismo índice, misma vista).
 */
class CategoryRequestHelpPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $title = 'Ayuda: solicitudes de categoría';

    protected static ?string $slug = 'ayuda-solicitudes-de-categoria';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.user-manual';

    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole(config('filament-shield.super_admin.name')) ?? false;
    }

    public static function sourcePath(): string
    {
        return resource_path('manual/ayuda_solicitudes_admin.md');
    }

    protected function getViewData(): array
    {
        $help = app(UserManualRenderer::class)->renderMarkdown((string) file_get_contents(self::sourcePath()));

        return [
            'manualHtml' => $help['html'],
            'toc' => $help['toc'],
            'tocAnchor' => UserManualRenderer::TOC_ANCHOR,
        ];
    }
}
