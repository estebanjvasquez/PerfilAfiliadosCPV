<?php

namespace App\Filament\Pages\GerenciaDashboard\Widgets;

use App\Filament\Support\GerenciaMetrics;
use Filament\Widgets\BarChartWidget;

class FacturacionWidget extends BarChartWidget
{
    protected static ?string $heading = 'Estratificación Financiera';

    protected static ?array $options = [
        'plugins' => ['legend' => ['display' => false]],
    ];

    protected function getData(): array
    {
        $data = GerenciaMetrics::facturacionPorRango(GerenciaMetrics::filtersFromRequest());

        return [
            'datasets' => [[
                'label' => 'Empresas',
                'data' => $data['values'],
                'backgroundColor' => '#8b5cf6',
            ]],
            'labels' => $data['labels'],
        ];
    }
}
