<?php

namespace App\Filament\Pages;

use App\Exports\ResourceExport;
use Filament\Pages\Page;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Actions\Action;
use Maatwebsite\Excel\Facades\Excel;

class ResourceView extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Detalle de RR.HH.';

    protected static ?string $title = 'Detalle de Recursos Humanos';

    protected static ?string $slug = 'resource-view';

    protected static ?string $navigationGroup = 'Reportes';

    protected static string $view = 'filament.pages.resource-view';

    protected static ?int $navigationSort = 8;

    use HasPageShield;

    public $resources;

    protected function getActions(): array
    {
        return [
            Action::make('Excel')->action('exportAllResourcesXls'),
            Action::make('Pdf')->action('exportAllResourcesPdf'),
        ];
    }

    public function exportAllResourcesXls()
    {
        return Excel::download(new ResourceExport, 'resources.xlsx');
        //return (new Catalog)->download('catalogo.xlsx');
    }

    public function exportAllResourcesPdf()
    {
        //return Excel::download(new FinanceExport, 'finance.pdf');
        $export = new ResourceExport;
        return Excel::download($export, 'resources.pdf', \Maatwebsite\Excel\Excel::MPDF);
    }
}
