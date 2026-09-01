<?php

namespace App\Filament\Pages;

use App\Exports\ManagementDetExport;
use Filament\Pages\Page;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Maatwebsite\Excel\Facades\Excel;

class ManagementDetView extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Detalle de sistema de gestión';

    protected static ?string $title = 'Detalle de sistema de gestión';

    protected static ?string $slug = 'management-det-view';

    protected static ?string $navigationGroup = 'Reportes';

    protected static string $view = 'filament.pages.management-det-view';

    protected static ?int $navigationSort = 12;

    use HasPageShield;

    public $managementdet;

    protected function getViewData(): array
    {
        $wholeModuleIds = \App\Models\EmpresaModuleStatus::noAplicaIdsFor(\App\Models\EmpresaModuleStatus::MODULE_GESTION);
        $subTypesByEmpresa = \App\Models\EmpresaModuleStatus::where('module', \App\Models\EmpresaModuleStatus::MODULE_GESTION)
            ->where('sub_type', '!=', \App\Models\EmpresaModuleStatus::SUB_TYPE_WHOLE)
            ->where('no_aplica', true)
            ->get()
            ->groupBy('empresa_id')
            ->map(fn ($group) => $group->pluck('sub_type')->all());

        return [
            'noAplicaWholeIds' => array_flip($wholeModuleIds),
            'noAplicaSubTypesByEmpresa' => $subTypesByEmpresa,
        ];
    }

    protected function getActions(): array
    {
        return [
            Action::make('Excel')->action('exportAllManagementDetXls'),
            Action::make('Pdf')->action('exportAllManagementDetPdf'),
        ];
    }

    public function exportAllManagementDetXls()
    {
        return Excel::download(new ManagementDetExport, 'management-det-detail.xlsx');
        //return (new Catalog)->download('catalogo.xlsx');
    }

    public function exportAllManagementDetPdf()
    {
        //return Excel::download(new FinanceExport, 'finance.pdf');
        $export = new ManagementDetExport(isPdf: true);
        return Excel::download($export, 'management-det-detail.pdf', \Maatwebsite\Excel\Excel::MPDF);
    }
}
