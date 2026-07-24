<?php

namespace App\Filament\Pages\GerenciaDashboard\Widgets;

use App\Filament\Support\GerenciaMetrics;
use Filament\Widgets\BarChartWidget;

/**
 * 3.3 Composicion de capital: origen (nacional/internacional) y propiedad
 * (privado/publico). Se muestran como 4 barras en un solo eje en vez de una
 * "dona doble" concentrica: Chart.js comparte el array de "labels" entre
 * todos los datasets de una dona, y origen/propiedad tienen categorias
 * distintas — una dona concentrica etiquetaria mal el segundo anillo.
 */
class CapitalWidget extends BarChartWidget
{
    protected static ?string $heading = 'Composición de Capital';

    // Polling desactivado: pisa el filtrado por GET, ver ResumenStatsWidget.
    protected static ?string $pollingInterval = null;

    protected static ?array $options = [
        'plugins' => ['legend' => ['display' => false]],
    ];

    protected function getData(): array
    {
        $data = GerenciaMetrics::composicionCapital(GerenciaMetrics::filtersFromRequest());

        $labels = [...array_keys($data['origen']), ...array_keys($data['propiedad'])];
        $values = [...array_values($data['origen']), ...array_values($data['propiedad'])];

        return [
            'datasets' => [[
                'label' => 'Empresas',
                'data' => $values,
                'backgroundColor' => ['#0284c7', '#0284c7', '#22c55e', '#22c55e'],
            ]],
            'labels' => $labels,
        ];
    }
}
