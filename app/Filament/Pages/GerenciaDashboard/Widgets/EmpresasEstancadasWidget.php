<?php

namespace App\Filament\Pages\GerenciaDashboard\Widgets;

use App\Filament\Resources\EmpresaResource;
use App\Filament\Support\GerenciaMetrics;
use App\Models\Empresa;
use Closure;
use Filament\Tables;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * 1.4 Empresas Estancadas. Limitacion aceptada (igual que CompletionView):
 * el % de completitud se calcula en PHP por fila, no es una columna SQL, asi
 * que solo "Actualizado" es ordenable en BD — las empresas con menor % se
 * identifican visualmente por el color de la columna, no por un WHERE/ORDER
 * que replicaria la logica de moduleBreakdown() en SQL.
 */
class EmpresasEstancadasWidget extends BaseWidget
{
    protected static ?string $heading = 'Empresas Estancadas (ordenadas por antigüedad de actualización)';

    protected int | string | array $columnSpan = 'full';

    protected function getTableQuery(): Builder
    {
        return GerenciaMetrics::baseQuery(GerenciaMetrics::filtersFromRequest())->orderBy('updated_at');
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
                ->searchable(),

            Tables\Columns\TextColumn::make('updated_at')
                ->label('Última Actualización')
                ->dateTime('d/m/Y')
                ->sortable(),

            Tables\Columns\TextColumn::make('completion')
                ->label('% Completitud')
                ->getStateUsing(fn (Empresa $record) => $record->completionPercentage())
                ->formatStateUsing(fn ($state) => "{$state}%")
                ->color(fn (Empresa $record) => $record->completionPercentage() < 50 ? 'danger' : 'warning'),
        ];
    }
}
