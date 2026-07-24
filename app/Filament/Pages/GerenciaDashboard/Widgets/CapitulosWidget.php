<?php

namespace App\Filament\Pages\GerenciaDashboard\Widgets;

use App\Filament\Support\GerenciaMetrics;
use Filament\Widgets\BarChartWidget;

class CapitulosWidget extends BarChartWidget
{
    protected static ?string $heading = 'Distribución por Cámara / Capítulo';

    protected static ?array $options = [
        'plugins' => ['legend' => ['display' => false]],
    ];

    protected function getData(): array
    {
        $data = GerenciaMetrics::distribucionCamaras(GerenciaMetrics::filtersFromRequest());

        return [
            'datasets' => [[
                'label' => 'Empresas',
                'data' => $data['values'],
                'backgroundColor' => '#d946ef',
            ]],
            'labels' => $data['labels'],
        ];
    }
}
