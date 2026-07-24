<?php

namespace App\Filament\Pages;

use App\Filament\Resources\EmpresaResource;
use App\Models\Empresa;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Closure;
use Filament\Pages\Page;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reporte para que el administrador detecte de un vistazo dos problemas de
 * configuracion de sectores: empresas sin sector principal/secundario
 * definido, y empresas con mas sectores distintos vinculados (via
 * servicios) que el limite de 2 (ver Empresa::allowedSectorIds()/
 * distinctSectorIds() y ServicesRelationManager, donde se hace cumplir
 * ese limite hacia adelante).
 */
class SectorsView extends Page implements Tables\Contracts\HasTable
{
    use Tables\Concerns\InteractsWithTable;
    use HasPageShield;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'Sectores por Empresa';

    protected static ?string $title = 'Sectores por Empresa';

    protected static ?string $slug = 'sectors-view';

    protected static ?string $navigationGroup = 'Reportes';

    protected static string $view = 'filament.pages.sectors-view';

    protected static ?int $navigationSort = 14;

    private const SECTORS_COUNT_SUBQUERY = '(
        select count(distinct s.sectors_id)
        from empresa_sector_service ess
        inner join services s on s.id = ess.service_id
        where ess.empresa_id = empresas.id
    )';

    protected function getTableQuery(): Builder
    {
        return Empresa::query()
            ->with(['sectorPrincipal', 'sectorSecundario'])
            ->select('empresas.*')
            ->selectRaw(self::SECTORS_COUNT_SUBQUERY . ' as sectors_count');
    }

    protected function getTableRecordUrlUsing(): ?Closure
    {
        return fn (Empresa $record) => EmpresaResource::getUrl('edit', ['record' => $record]);
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('name')
                ->label('Empresa')
                ->searchable()
                ->sortable(),

            Tables\Columns\TextColumn::make('sectorPrincipal.name')
                ->label('Sector Principal')
                ->default('Sin configurar')
                ->color(fn (Empresa $record) => $record->sector_principal_id ? null : 'danger'),

            Tables\Columns\TextColumn::make('sectorSecundario.name')
                ->label('Sector Secundario')
                ->default('Sin configurar')
                ->color(fn (Empresa $record) => $record->sector_secundario_id ? null : 'danger'),

            Tables\Columns\TextColumn::make('sectors_count')
                ->label('Cantidad de Sectores')
                ->sortable()
                ->color(fn (Empresa $record) => ((int) $record->sectors_count) > 2 ? 'danger' : 'success'),
        ];
    }

    protected function getTableFilters(): array
    {
        return [
            Tables\Filters\Filter::make('sin_sector')
                ->label('Sin sector principal o secundario')
                ->toggle()
                ->query(fn (Builder $query) => $query->where(function (Builder $q) {
                    $q->whereNull('sector_principal_id')->orWhereNull('sector_secundario_id');
                })),

            Tables\Filters\Filter::make('exceso_sectores')
                ->label('Más de 2 sectores')
                ->toggle()
                ->query(fn (Builder $query) => $query->whereRaw(self::SECTORS_COUNT_SUBQUERY . ' > 2')),
        ];
    }
}
