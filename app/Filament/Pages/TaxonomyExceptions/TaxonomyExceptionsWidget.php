<?php

namespace App\Filament\Pages\TaxonomyExceptions;

use App\Models\TaxonomyTermCpvRelation;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

use App\Filament\Resources\TaxonomyTermCpvRelationResource;

/**
 * TAXV3-4 (ajuste V3 puntos 9/10): "el administrador resuelve excepciones, el sistema hace el
 * trabajo masivo" - a propósito son 4 números CHICOS y reales, no los 1.776 términos
 * pending/curated (esos son cobertura, no tareas - ver `TaxonomyOverviewWidget`) ni el backlog
 * histórico de 9.288 `needs_review` de TAXV2-2 (anterior a esta fase, ya conocido, no se
 * resurface acá).
 *
 * `status='candidate'` es una señal LIMPIA por diseño: NINGUNA relación anterior a TAXV3-3 usa ese
 * valor (verificado contra datos reales) - filtrar solo por status ya excluye el backlog viejo sin
 * necesidad de comprobar también `source`.
 */
class TaxonomyExceptionsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $lowConfidenceCandidates = TaxonomyTermCpvRelation::query()
            ->where('status', TaxonomyTermCpvRelation::STATUS_CANDIDATE)
            ->count();

        $orphanCodes = TaxonomyTermCpvRelation::query()->whereNull('category_id')->distinct()->count('cpv_code');

        $duplicateConcepts = (int) (DB::connection('pgsql')->selectOne(<<<'SQL'
            SELECT count(*) c FROM (
                SELECT a.id
                FROM taxonomy_canonical_concepts a
                JOIN taxonomy_canonical_concepts b ON b.id != a.id
                WHERE similarity(coalesce(a.canonical_name_en,''), coalesce(b.canonical_name_en,'')) > 0.5
                   OR similarity(coalesce(a.canonical_name_es,''), coalesce(b.canonical_name_es,'')) > 0.5
                GROUP BY a.id
            ) x
        SQL)->c ?? 0);

        $missingBindings = DB::connection('pgsql')->table('taxonomy_term_source_bindings')
            ->where('verification_status', 'missing')
            ->count();

        return [
            Stat::make('Mappings CPV de baja confianza', $lowConfidenceCandidates)
                ->description('Candidatos del Auto Mapper - no urgente, revisar cuando haya tiempo')
                ->color($lowConfidenceCandidates > 0 ? 'warning' : 'success')
                ->url(TaxonomyTermCpvRelationResource::getUrl('index', ['tableFilters[status][value]' => TaxonomyTermCpvRelation::STATUS_CANDIDATE])),

            Stat::make('Códigos CPV huérfanos', $orphanCodes)
                ->description('Referenciados por el diccionario pero sin categoría existente')
                ->color($orphanCodes > 0 ? 'danger' : 'success')
                ->url(TaxonomyTermCpvRelationResource::getUrl('index', ['tableFilters[sin_categoria][isActive]' => 1])),

            Stat::make('Posibles duplicados de concepto', $duplicateConcepts)
                ->description('Ver el detalle abajo')
                ->color($duplicateConcepts > 0 ? 'warning' : 'success'),

            Stat::make('Bindings de fuente MISSING', $missingBindings)
                ->description('El Source Sync (próxima sub-fase) los detecta - nunca se borran solos')
                ->color($missingBindings > 0 ? 'danger' : 'success'),
        ];
    }
}
