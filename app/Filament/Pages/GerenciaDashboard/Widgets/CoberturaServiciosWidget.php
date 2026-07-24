<?php

namespace App\Filament\Pages\GerenciaDashboard\Widgets;

use App\Filament\Support\GerenciaMetrics;
use Filament\Widgets\BarChartWidget;

class CoberturaServiciosWidget extends BarChartWidget
{
    protected static ?string $heading = 'Cobertura de Servicios Técnicos (Top 10)';

    protected static ?array $options = [
        'indexAxis' => 'y',
        'plugins' => ['legend' => ['display' => false]],
    ];

    protected function getData(): array
    {
        $data = GerenciaMetrics::coberturaServicios(GerenciaMetrics::filtersFromRequest(), 10);

        return [
            'datasets' => [[
                'label' => 'Empresas',
                'data' => $data['values'],
                'backgroundColor' => '#0ea5e9',
            ]],
            'labels' => $data['labels'],
        ];
    }
}
