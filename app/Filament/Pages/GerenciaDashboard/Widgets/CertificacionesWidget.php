<?php

namespace App\Filament\Pages\GerenciaDashboard\Widgets;

use App\Filament\Support\GerenciaMetrics;
use Filament\Widgets\BarChartWidget;

/**
 * 5.1 (ISO) + 5.2 (estandares especializados) fusionadas en un solo widget:
 * 7 certificaciones juntas son mas escaneables para gerencia que 2 widgets
 * separados con ejes distintos.
 */
class CertificacionesWidget extends BarChartWidget
{
    protected static ?string $heading = 'Penetración de Certificaciones y Estándares';

    protected static ?array $options = [
        'indexAxis' => 'y',
        'plugins' => ['legend' => ['display' => false]],
        'scales' => ['x' => ['suggestedMax' => 100]],
    ];

    protected function getData(): array
    {
        $data = GerenciaMetrics::certificaciones(GerenciaMetrics::filtersFromRequest());

        return [
            'datasets' => [[
                'label' => '% de adopción',
                'data' => array_values($data),
                'backgroundColor' => '#14b8a6',
            ]],
            'labels' => array_keys($data),
        ];
    }
}
