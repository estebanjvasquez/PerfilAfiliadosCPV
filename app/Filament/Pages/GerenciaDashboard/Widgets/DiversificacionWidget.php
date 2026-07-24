<?php

namespace App\Filament\Pages\GerenciaDashboard\Widgets;

use App\Filament\Support\GerenciaMetrics;
use Filament\Widgets\DoughnutChartWidget;

class DiversificacionWidget extends DoughnutChartWidget
{
    protected static ?string $heading = 'Índice de Diversificación Sectorial';

    // Polling desactivado: pisa el filtrado por GET, ver ResumenStatsWidget.
    protected static ?string $pollingInterval = null;

    protected function getData(): array
    {
        $buckets = GerenciaMetrics::diversificacionSectorial(GerenciaMetrics::filtersFromRequest());

        return [
            'datasets' => [[
                'data' => array_values($buckets),
                'backgroundColor' => ['#0284c7', '#22c55e'],
            ]],
            'labels' => array_keys($buckets),
        ];
    }
}
