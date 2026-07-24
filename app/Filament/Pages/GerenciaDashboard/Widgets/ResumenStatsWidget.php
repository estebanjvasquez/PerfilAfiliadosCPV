<?php

namespace App\Filament\Pages\GerenciaDashboard\Widgets;

use App\Filament\Support\GerenciaMetrics;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Card;

class ResumenStatsWidget extends BaseWidget
{
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
