<?php

namespace App\Filament\Pages\GerenciaDashboard\Widgets;

use App\Filament\Support\GerenciaMetrics;
use Filament\Widgets\BarChartWidget;

class EmpleoWidget extends BarChartWidget
{
    protected static ?string $heading = 'Generación de Empleo Directo';

    protected static ?array $options = [
        'plugins' => ['legend' => ['display' => false]],
    ];

    protected function getData(): array
    {
        $data = GerenciaMetrics::empleoPorRango(GerenciaMetrics::filtersFromRequest());

        return [
            'datasets' => [[
                'label' => 'Empresas',
                'data' => $data['values'],
                'backgroundColor' => '#6366f1',
            ]],
            'labels' => $data['labels'],
        ];
    }
}
