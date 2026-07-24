<?php

namespace App\Filament\Pages\GerenciaDashboard\Widgets;

use App\Filament\Support\GerenciaMetrics;
use Filament\Widgets\BarChartWidget;

class TopSectoresWidget extends BarChartWidget
{
    protected static ?string $heading = 'Top 10 Sectores de Afiliados';

    // Polling desactivado: pisa el filtrado por GET, ver ResumenStatsWidget.
    protected static ?string $pollingInterval = null;

    protected static ?array $options = [
        'indexAxis' => 'y',
        'plugins' => ['legend' => ['display' => false]],
    ];

    protected function getData(): array
    {
        $data = GerenciaMetrics::topSectores(GerenciaMetrics::filtersFromRequest(), 10);

        return [
            'datasets' => [[
                'label' => 'Empresas',
                'data' => $data['values'],
                'backgroundColor' => '#0284c7',
            ]],
            'labels' => $data['labels'],
        ];
    }
}
