<?php

namespace App\Filament\Pages\GerenciaDashboard\Widgets;

use App\Filament\Support\GerenciaMetrics;
use Filament\Widgets\DoughnutChartWidget;

class AlcanceInternacionalWidget extends DoughnutChartWidget
{
    protected static ?string $heading = 'Alcance Internacional';

    protected function getData(): array
    {
        $buckets = GerenciaMetrics::alcanceInternacional(GerenciaMetrics::filtersFromRequest());

        return [
            'datasets' => [[
                'data' => array_values($buckets),
                'backgroundColor' => ['#22c55e', '#94a3b8'],
            ]],
            'labels' => array_keys($buckets),
        ];
    }
}
