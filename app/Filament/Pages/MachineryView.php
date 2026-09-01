<?php

namespace App\Filament\Pages;

use App\Exports\MachineryExport;
use Filament\Pages\Page;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Maatwebsite\Excel\Facades\Excel;

class MachineryView extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Detalle de maquinaria y equipos';

    protected static ?string $title = 'Detalle de maquinaria y equipos';

    protected static ?string $slug = 'machinery-view';

    protected static ?string $navigationGroup = 'Reportes';

    protected static string $view = 'filament.pages.machinery-view';

    protected static ?int $navigationSort = 9;

    use HasPageShield;

    public $machinery;

    protected function getViewData(): array
    {
        $naIds = \App\Models\EmpresaModuleStatus::noAplicaIdsFor(\App\Models\EmpresaModuleStatus::MODULE_RECURSOS, 'machinery');

        return [
            'noAplicaIds' => array_flip($naIds),
        ];
    }

    protected function getActions(): array
    {
        return [
            Action::make('Excel')->action('exportAllMachineryXls'),
            Action::make('Pdf')->action('exportAllMachineryPdf'),
        ];
    }

    public function exportAllMachineryXls()
    {
        return Excel::download(new MachineryExport, 'machinery-detail.xlsx');
        //return (new Catalog)->download('catalogo.xlsx');
    }

    public function exportAllMachineryPdf()
    {
        //return Excel::download(new FinanceExport, 'finance.pdf');
        $export = new MachineryExport(isPdf: true);
        return Excel::download($export, 'machinery-detail.pdf', \Maatwebsite\Excel\Excel::MPDF);
    }
}
