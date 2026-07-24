<?php

namespace App\Filament\Pages;

use App\Exports\CompletionExport;
use App\Models\Empresa;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Facades\Excel;

class CompletionView extends Page implements Tables\Contracts\HasTable
{
    use Tables\Concerns\InteractsWithTable;
    use HasPageShield;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Completitud de Perfiles';

    protected static ?string $title = 'Completitud de Perfiles por Empresa';

    protected static ?string $slug = 'completion-view';

    protected static ?string $navigationGroup = 'Reportes';

    protected static string $view = 'filament.pages.completion-view';

    protected static ?int $navigationSort = 0;

    /**
     * Etiquetas cortas de columna para cada renglon de Empresa::moduleBreakdown(),
     * en el mismo orden en que ese metodo las devuelve.
     */
    private const MODULE_COLUMNS = [
        'datos_generales' => 'Datos Grales.',
        'sectores' => 'Sectores',
        'contactos' => 'Contactos',
        'recursos' => 'Recursos',
        'gestion' => 'Gestión',
        'presencia' => 'Presencia',
        'experiencias' => 'Experiencia',
        'sostenibilidad' => 'Sostenibilidad',
    ];

    /**
     * Empresa::moduleBreakdown() dispara varias consultas por empresa; se
     * memoiza por fila para no recalcularlo una vez por cada columna de % .
     */
    protected array $breakdownCache = [];

    protected function breakdownFor(Empresa $empresa): array
    {
        return $this->breakdownCache[$empresa->id] ??= $empresa->moduleBreakdown();
    }

    private static function percentageColor(int $percentage): string
    {
        return match (true) {
            $percentage >= 100 => 'success',
            $percentage > 0 => 'warning',
            default => 'danger',
        };
    }

    protected function getTableQuery(): Builder
    {
        return Empresa::query();
    }

    protected function getTableColumns(): array
    {
        $columns = [
            Tables\Columns\TextColumn::make('name')
                ->label('Empresa')
                ->searchable()
                ->sortable(),

            Tables\Columns\TextColumn::make('completion_total')
                ->label('% Total')
                ->getStateUsing(fn (Empresa $record) => $record->completionPercentage())
                ->formatStateUsing(fn ($state) => "{$state}%")
                ->color(fn ($state) => static::percentageColor((int) $state))
                ->weight('bold'),
        ];

        foreach (self::MODULE_COLUMNS as $key => $label) {
            $columns[] = Tables\Columns\TextColumn::make("module_{$key}")
                ->label($label)
                ->getStateUsing(fn (Empresa $record) => $this->breakdownFor($record)[$key]['percentage'])
                ->formatStateUsing(fn ($state) => "{$state}%")
                ->color(fn ($state) => static::percentageColor((int) $state));
        }

        return $columns;
    }

    protected function getActions(): array
    {
        return [
            Action::make('Excel')->action('exportCompletionXls'),
            Action::make('Pdf')->action('exportCompletionPdf'),
        ];
    }

    public function exportCompletionXls()
    {
        return Excel::download(new CompletionExport, 'completitud.xlsx');
    }

    public function exportCompletionPdf()
    {
        $export = new CompletionExport;

        return Excel::download($export, 'completitud.pdf', \Maatwebsite\Excel\Excel::MPDF);
    }
}
