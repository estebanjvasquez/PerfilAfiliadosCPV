<?php

namespace App\Filament\Pages;

use App\Exports\PresenceExport;
use Filament\Pages\Page;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Actions\Action;
use Maatwebsite\Excel\Facades\Excel;

class PresenceView extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Presencia Internacional';

    protected static ?string $title = 'Presencia Internacional';

    protected static ?string $slug = 'presence-view';

    protected static ?string $navigationGroup = 'Reportes';

    protected static string $view = 'filament.pages.presence-view';

    protected static ?int $navigationSort = 13;

    use HasPageShield;

    public $presence;

    protected function getActions(): array
    {
        return [
            Action::make('Excel')->action('exportAllPresenceXls'),
            Action::make('Pdf')->action('exportAllPresencePdf'),
        ];
    }

    public function exportAllPresenceXls()
    {
        return Excel::download(new PresenceExport, 'presence-detail.xlsx');
        //return (new Catalog)->download('catalogo.xlsx');
    }

    public function exportAllPresencePdf()
    {
        //return Excel::download(new FinanceExport, 'finance.pdf');
        $export = new PresenceExport;
        return Excel::download($export, 'presence-detail.pdf', \Maatwebsite\Excel\Excel::MPDF);
    }
}
