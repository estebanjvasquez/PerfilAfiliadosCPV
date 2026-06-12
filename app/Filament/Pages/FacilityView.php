<?php

namespace App\Filament\Pages;

use App\Exports\FacilityExport;
use Filament\Pages\Page;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Actions\Action;
use Maatwebsite\Excel\Facades\Excel;

class FacilityView extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Detalle de Instalaciones';

    protected static ?string $title = 'Detalle de Instalaciones';

    protected static ?string $slug = 'facility-view';

    protected static ?string $navigationGroup = 'Reportes';

    protected static string $view = 'filament.pages.facility-view';

    protected static ?int $navigationSort = 10;

    use HasPageShield;

    public $facility;

    protected function getActions(): array
    {
        return [
            Action::make('Excel')->action('exportAllFacilityXls'),
            Action::make('Pdf')->action('exportAllFacilityPdf'),
        ];
    }

    public function exportAllFacilityXls()
    {
        return Excel::download(new FacilityExport, 'facility-detail.xlsx');
        //return (new Catalog)->download('catalogo.xlsx');
    }

    public function exportAllFacilityPdf()
    {
        //return Excel::download(new FinanceExport, 'finance.pdf');
        $export = new FacilityExport;
        return Excel::download($export, 'facility-detail.pdf', \Maatwebsite\Excel\Excel::MPDF);
    }
}
