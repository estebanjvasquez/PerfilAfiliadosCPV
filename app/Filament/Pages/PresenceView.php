<?php

namespace App\Filament\Pages;

use App\Exports\PresenceExport;
use Filament\Pages\Page;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Maatwebsite\Excel\Facades\Excel;

class PresenceView extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Presencia internacional';

    protected static ?string $title = 'Presencia internacional';

    protected static ?string $slug = 'presence-view';

    protected static ?string $navigationGroup = 'Reportes';

    protected static string $view = 'filament.pages.presence-view';

    protected static ?int $navigationSort = 13;

    use HasPageShield;

    public $presence;

    protected function getViewData(): array
    {
        $naIds = \App\Models\EmpresaModuleStatus::noAplicaIdsFor(\App\Models\EmpresaModuleStatus::MODULE_PRESENCIA);

        return [
            'noAplicaIds' => array_flip($naIds),
        ];
    }

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
        $export = new PresenceExport(isPdf: true);
        return Excel::download($export, 'presence-detail.pdf', \Maatwebsite\Excel\Excel::MPDF);
    }
}
