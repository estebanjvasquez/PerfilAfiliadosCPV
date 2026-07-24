<?php

namespace App\Filament\Pages\GerenciaDashboard\Widgets;

use App\Filament\Support\GerenciaMetrics;
use Filament\Widgets\BarChartWidget;

class NoAplicaWidget extends BarChartWidget
{
    protected static ?string $heading = 'Tasa de "No Aplica" por Módulo';

    // Polling desactivado: pisa el filtrado por GET, ver ResumenStatsWidget.
    protected static ?string $pollingInterval = null;

    protected function getData(): array
    {
        $data = GerenciaMetrics::noAplicaPorModulo(GerenciaMetrics::filtersFromRequest());

        return [
            'datasets' => [
                [
                    'label' => 'Módulo completo',
                    'data' => $data['completo'],
                    'backgroundColor' => '#ef4444',
                ],
                [
                    'label' => 'Sub-tipo parcial',
                    'data' => $data['parcial'],
                    'backgroundColor' => '#f59e0b',
                ],
            ],
            'labels' => $data['labels'],
        ];
    }
}
