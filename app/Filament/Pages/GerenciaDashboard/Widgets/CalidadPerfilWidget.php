<?php

namespace App\Filament\Pages\GerenciaDashboard\Widgets;

use App\Filament\Support\GerenciaMetrics;
use Filament\Widgets\DoughnutChartWidget;

class CalidadPerfilWidget extends DoughnutChartWidget
{
    protected static ?string $heading = 'Segmentación por Calidad de Perfil';

    // Polling desactivado: pisa el filtrado por GET, ver ResumenStatsWidget.
    protected static ?string $pollingInterval = null;

    protected function getData(): array
    {
        $buckets = GerenciaMetrics::calidadPerfil(GerenciaMetrics::filtersFromRequest());

        return [
            'datasets' => [[
                'data' => array_values($buckets),
                'backgroundColor' => ['#ef4444', '#f59e0b', '#22c55e'],
            ]],
            'labels' => array_keys($buckets),
        ];
    }
}
