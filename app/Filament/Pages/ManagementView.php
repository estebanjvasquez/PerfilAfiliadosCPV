<?php

namespace App\Filament\Pages;

use App\Exports\ManagementExport;
use Filament\Pages\Page;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Actions\Action;
use Maatwebsite\Excel\Facades\Excel;

class ManagementView extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Sistemas de Gestión';

    protected static ?string $title = 'Sistemas de Gestión';

    protected static ?string $slug = 'management-view';

    protected static ?string $navigationGroup = 'Reportes';

    protected static string $view = 'filament.pages.management-view';

    protected static ?int $navigationSort = 3;

    use HasPageShield;

    public $managements;

    protected function getActions(): array
    {
        return [
            Action::make('Excel')->action('exportAllManagementXls'),
            Action::make('Pdf')->action('exportAllManagementPdf'),
        ];
    }

    public function exportAllManagementXls()
    {
        return Excel::download(new ManagementExport, 'management.xlsx');
        //return (new Catalog)->download('catalogo.xlsx');
    }

    public function exportAllManagementPdf()
    {

        $export = new ManagementExport;
        return Excel::download($export, 'management.pdf', \Maatwebsite\Excel\Excel::MPDF);
    }
}
