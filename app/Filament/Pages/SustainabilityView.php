<?php

namespace App\Filament\Pages;

use App\Exports\SustainabilityExport;
use Filament\Pages\Page;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Actions\Action;
use Maatwebsite\Excel\Facades\Excel;

class SustainabilityView extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Sistemas de Sostenibilidad';

    protected static ?string $title = 'Sistemas de Sostenibilidad';

    protected static ?string $slug = 'sustainability-view';

    protected static ?string $navigationGroup = 'Reportes';

    protected static string $view = 'filament.pages.sustainability-view';

    protected static ?int $navigationSort = 5;

    use HasPageShield;

    public $sustainabilities;

    protected function getActions(): array
    {
        return [
            Action::make('Excel')->action('exportAllSustainabilityXls'),
            Action::make('Pdf')->action('exportAllSustainabilityPdf'),
        ];
    }

    public function exportAllSustainabilityXls()
    {
        return Excel::download(new SustainabilityExport, 'sustainability.xlsx');
        //return (new Catalog)->download('catalogo.xlsx');
    }

    public function exportAllSustainabilityPdf()
    {
        $export = new SustainabilityExport;
        return Excel::download($export, 'sustainability.pdf', \Maatwebsite\Excel\Excel::MPDF);
    }
}
