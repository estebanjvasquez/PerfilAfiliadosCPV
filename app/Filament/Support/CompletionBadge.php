<?php

namespace App\Filament\Support;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;

/**
 * Indicador de solo lectura (botón deshabilitado) con el % de completitud
 * de un módulo, embebido como acción de cabecera dentro de su propia
 * pestaña. Reutiliza Empresa::moduleBreakdown() — la misma fuente de
 * verdad que usa el reporte de administradores (CompletionView) — para
 * que nunca queden desincronizados.
 */
class CompletionBadge
{
    public static function make(string $module): Action
    {
        return Action::make('completitud')
            ->label(fn (RelationManager $livewire) => 'Completitud: ' . static::percentage($livewire, $module) . '%')
            ->icon('heroicon-o-chart-pie')
            ->color(fn (RelationManager $livewire) => static::color(static::percentage($livewire, $module)))
            ->disabled();
    }

    private static function percentage(RelationManager $livewire, string $module): int
    {
        return $livewire->ownerRecord->moduleBreakdown()[$module]['percentage'];
    }

    private static function color(int $percentage): string
    {
        return match (true) {
            $percentage >= 100 => 'success',
            $percentage > 0 => 'warning',
            default => 'danger',
        };
    }
}
