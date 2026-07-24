<?php

namespace App\Filament\Pages\GerenciaDashboard\Widgets;

use App\Filament\Support\GerenciaMetrics;
use Filament\Widgets\RadarChartWidget;

class RecursosRadarWidget extends RadarChartWidget
{
    protected static ?string $heading = 'Cobertura de Recursos Industriales';

    // Polling desactivado: pisa el filtrado por GET, ver ResumenStatsWidget.
    protected static ?string $pollingInterval = null;

    protected function getData(): array
    {
        $data = GerenciaMetrics::coberturaRecursos(GerenciaMetrics::filtersFromRequest());

        return [
            'datasets' => [[
                'label' => '% de empresas con datos cargados',
                'data' => $data['values'],
                'backgroundColor' => 'rgba(2, 132, 199, 0.2)',
                'borderColor' => '#0284c7',
            ]],
            'labels' => $data['labels'],
        ];
    }
}
