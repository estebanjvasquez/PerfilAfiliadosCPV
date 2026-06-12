<?php

namespace App\Filament\Pages;

use App\Exports\InventoryExport;
use Filament\Pages\Page;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Actions\Action;
use Maatwebsite\Excel\Facades\Excel;

class InventoryView extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Detalle de Inventario';

    protected static ?string $title = 'Detalle de Inventario';

    protected static ?string $slug = 'inventory-view';

    protected static ?string $navigationGroup = 'Reportes';

    protected static string $view = 'filament.pages.inventory-view';

    protected static ?int $navigationSort = 11;

    use HasPageShield;

    public $inventory;

    protected function getActions(): array
    {
        return [
            Action::make('Excel')->action('exportAllInventoryXls'),
            Action::make('Pdf')->action('exportAllInventoryPdf'),
        ];
    }

    public function exportAllInventoryXls()
    {
        return Excel::download(new InventoryExport, 'inventory-detail.xlsx');
        //return (new Catalog)->download('catalogo.xlsx');
    }

    public function exportAllInventoryPdf()
    {
        //return Excel::download(new FinanceExport, 'finance.pdf');
        $export = new InventoryExport;
        return Excel::download($export, 'inventory-detail.pdf', \Maatwebsite\Excel\Excel::MPDF);
    }
}
