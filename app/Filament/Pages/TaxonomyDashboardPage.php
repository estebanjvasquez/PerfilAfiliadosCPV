<?php

namespace App\Filament\Pages;

use App\Filament\Pages\TaxonomyDashboard\TaxonomyOverviewWidget;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * TAXV2-8 (sección 4.1 del documento de instrucciones): panel de conteos del diccionario V2.
 * TAXV2-10: requiere el permiso `taxonomy_view` de la sección 13, asignado al rol `super_admin` por
 * `TaxonomyV2PermissionsSeeder` (no hay ningún `Gate::before` automático en este proyecto - Shield
 * le da acceso a `super_admin` asignándole explícitamente todos los permisos existentes, así que un
 * permiso creado por fuera de `shield:generate` necesita este seeder para llegarle).
 */
class TaxonomyDashboardPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationGroup = 'Taxonomía CPV';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.taxonomy-dashboard';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('taxonomy_view') ?? false;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            TaxonomyOverviewWidget::class,
        ];
    }
}
