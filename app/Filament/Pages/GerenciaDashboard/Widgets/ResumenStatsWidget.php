<?php

namespace App\Filament\Pages\GerenciaDashboard\Widgets;

use App\Filament\Support\GerenciaMetrics;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Card;

class ResumenStatsWidget extends BaseWidget
{
    /**
     * El polling por defecto de Filament (5s) re-renderiza el widget via
     * una peticion AJAX de Livewire que NO lleva el querystring de la
     * pagina original, asi que GerenciaMetrics::filtersFromRequest() leeria
     * los filtros vacios y pisaria lo que el usuario acaba de aplicar.
     * Este dashboard se filtra por recarga de pagina (GET), no en vivo.
     */
    protected static ?string $pollingInterval = null;

    protected function getCards(): array
    {
        $data = GerenciaMetrics::resumen(GerenciaMetrics::filtersFromRequest());

        return [
            Card::make('Empresas Activas', $data['total_empresas'])
                ->icon('heroicon-o-office-building')
                ->color('primary'),

            Card::make('% Completitud Promedio', $data['completitud_promedio'] . '%')
                ->icon('heroicon-o-chart-pie')
                ->color($this->colorFor($data['completitud_promedio'])),

            Card::make('Frescura del Dato', $data['frescura_dato'] . '%')
                ->description('Actualizado en los últimos 12 meses')
                ->icon('heroicon-o-clock')
                ->color($this->colorFor($data['frescura_dato'])),

            Card::make('Sedes con Infraestructura', $data['sedes'])
                ->icon('heroicon-o-office-building')
                ->color('primary'),

            Card::make('Historial de Proyectos', $data['proyectos'])
                ->description('Total de experiencias registradas')
                ->icon('heroicon-o-briefcase')
                ->color('primary'),
        ];
    }

    private function colorFor(int $percentage): string
    {
        return match (true) {
            $percentage >= 100 => 'success',
            $percentage > 0 => 'warning',
            default => 'danger',
        };
    }
}
