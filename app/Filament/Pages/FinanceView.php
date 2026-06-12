<?php

namespace App\Filament\Pages;

use App\Exports\FinanceExport;
use Filament\Pages\Page;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Actions\Action;
use Maatwebsite\Excel\Facades\Excel;

class FinanceView extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Capacidad Financiera';

    protected static ?string $title = 'Capacidad Financiera';

    protected static ?string $slug = 'finance-view';

    protected static ?string $navigationGroup = 'Reportes';

    protected static string $view = 'filament.pages.finance-view';

    protected static ?int $navigationSort = 4;

    use HasPageShield;

    public $finances;

    protected function getActions(): array
    {
        return [
            Action::make('Excel')->action('exportAllFinanceXls'),
            Action::make('Pdf')->action('exportAllFinancePdf'),
        ];
    }

    public function exportAllFinanceXls()
    {
        return Excel::download(new FinanceExport, 'finance.xlsx');
        //return (new Catalog)->download('catalogo.xlsx');
    }

    public function exportAllFinancePdf()
    {
        //return Excel::download(new FinanceExport, 'finance.pdf');
        $export = new FinanceExport;
        return Excel::download($export, 'finance.pdf', \Maatwebsite\Excel\Excel::MPDF);
    }
}
