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
        // TASK-0011: es un estado, no una acción. Se dibuja como insignia para que no se confunda con los botones
        // de la sección («No aplica», «Calidad», ...), que antes tenían el mismo aspecto.
        return Action::make('completitud')
            ->label(fn (RelationManager $livewire) => 'Completado: ' . static::percentage($livewire, $module) . '%')
            ->icon('heroicon-o-chart-pie')
            ->color(fn (RelationManager $livewire) => static::color(static::percentage($livewire, $module)))
            ->view(Action::BADGE_VIEW)
            ->tooltip('Avance de esta sección del perfil')
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
