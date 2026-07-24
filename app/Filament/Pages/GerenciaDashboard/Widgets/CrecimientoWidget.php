<?php

namespace App\Filament\Pages\GerenciaDashboard\Widgets;

use App\Filament\Support\GerenciaMetrics;
use Filament\Widgets\LineChartWidget;

class CrecimientoWidget extends LineChartWidget
{
    protected static ?string $heading = 'Crecimiento de Afiliación (últimos 12 meses)';

    protected int | string | array $columnSpan = 'full';

    protected function getData(): array
    {
        $data = GerenciaMetrics::crecimientoAfiliacion(GerenciaMetrics::filtersFromRequest());

        return [
            'datasets' => [[
                'label' => 'Nuevas Empresas',
                'data' => $data['values'],
                'borderColor' => '#0284c7',
                'backgroundColor' => 'rgba(2, 132, 199, 0.2)',
                'fill' => true,
            ]],
            'labels' => $data['labels'],
        ];
    }
}
