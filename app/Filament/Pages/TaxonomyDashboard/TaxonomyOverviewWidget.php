<?php

namespace App\Filament\Pages\TaxonomyDashboard;

use App\Models\LegacyService;
use App\Models\TaxonomySource;
use App\Models\TaxonomyTerm;
use App\Models\TaxonomyTermCpvRelation;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * TAXV2-8 (sección 4.1 del documento de instrucciones): conteos reales del diccionario V2.
 * Deliberadamente son "Stat" simples (no gráficos) - lo que pide la sección 4.1 son conteos, no
 * series de tiempo; replicar el patrón de `GerenciaDashboard` (multi-widget con BarChartWidget) acá
 * hubiera sido sobre-ingeniería para 10 números.
 *
 * Lo que la sección 4.1 pide y todavía NO se puede mostrar (queda para cuando existan esas fases):
 * "candidatos encontrados por crawler" y "última actualización por fuente" -> TAXV2-12.
 */
class TaxonomyOverviewWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $termsTotal = TaxonomyTerm::query()->count();
        $termsUnmapped = TaxonomyTerm::query()->where('mapping_review_status', TaxonomyTerm::MAPPING_UNMAPPED)->count();

        $relationsByStatus = TaxonomyTermCpvRelation::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $orphanCodes = TaxonomyTermCpvRelation::query()->whereNull('category_id')->distinct()->count('cpv_code');

        $servicesByStatus = LegacyService::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $avgWeight = round((float) TaxonomyTermCpvRelation::query()->where('status', TaxonomyTermCpvRelation::STATUS_APPROVED)->avg('weight'), 3);

        $sourcesEnabled = TaxonomySource::query()->where('enabled', true)->count();
        $sourcesTotal = TaxonomySource::query()->count();

        return [
            Stat::make('Términos en el diccionario', $termsTotal)
                ->description("{$termsUnmapped} sin ningún mapeo a CPV")
                ->color($termsUnmapped > 0 ? 'warning' : 'success'),

            Stat::make('Relaciones Término↔CPV', array_sum($relationsByStatus->all()))
                ->description(sprintf(
                    '%d aprobadas · %d en revisión · %d descartadas',
                    $relationsByStatus[TaxonomyTermCpvRelation::STATUS_APPROVED] ?? 0,
                    $relationsByStatus[TaxonomyTermCpvRelation::STATUS_NEEDS_REVIEW] ?? 0,
                    $relationsByStatus[TaxonomyTermCpvRelation::STATUS_DEPRECATED] ?? 0,
                ))
                ->color(($relationsByStatus[TaxonomyTermCpvRelation::STATUS_NEEDS_REVIEW] ?? 0) > 0 ? 'warning' : 'success'),

            Stat::make('Códigos CPV huérfanos', $orphanCodes)
                ->description('Referenciados por el diccionario pero sin categoría existente')
                ->color($orphanCodes > 0 ? 'danger' : 'success'),

            Stat::make('Servicios legacy', $servicesByStatus->sum())
                ->description(sprintf(
                    '%d activos · %d deprecados · %d deshabilitados',
                    $servicesByStatus[LegacyService::STATUS_LEGACY_ACTIVE] ?? 0,
                    $servicesByStatus[LegacyService::STATUS_DEPRECATED] ?? 0,
                    $servicesByStatus[LegacyService::STATUS_DISABLED] ?? 0,
                )),

            Stat::make('Peso promedio (aprobadas)', $avgWeight ?: '—')
                ->description('Solo relaciones Término↔CPV con status=approved'),

            Stat::make('Fuentes habilitadas', "{$sourcesEnabled} / {$sourcesTotal}")
                ->description('Ver "Fuentes y actualización" para el detalle'),
        ];
    }
}
