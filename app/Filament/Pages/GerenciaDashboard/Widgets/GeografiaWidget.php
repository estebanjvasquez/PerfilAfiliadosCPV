<?php

namespace App\Filament\Pages\GerenciaDashboard\Widgets;

use App\Filament\Support\GerenciaMetrics;
use Filament\Widgets\BarChartWidget;

class GeografiaWidget extends BarChartWidget
{
    protected static ?string $heading = 'Distribución Geográfica (Top 10 Estados)';

    // Polling desactivado: pisa el filtrado por GET, ver ResumenStatsWidget.
    protected static ?string $pollingInterval = null;

    protected static ?array $options = [
        'indexAxis' => 'y',
        'plugins' => ['legend' => ['display' => false]],
    ];

    protected function getData(): array
    {
        $data = GerenciaMetrics::distribucionGeografica(GerenciaMetrics::filtersFromRequest(), 10);

        return [
            'datasets' => [[
                'label' => 'Empresas',
                'data' => $data['values'],
                'backgroundColor' => '#f97316',
            ]],
            'labels' => $data['labels'],
        ];
    }
}
