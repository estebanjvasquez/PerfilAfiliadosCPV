<?php

namespace App\Filament\Pages;

use App\Exports\Capacity;
use Filament\Pages\Page;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Actions\Action;
use Maatwebsite\Excel\Facades\Excel;

class GenCapacity extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Capacidad Técnica General';

    protected static ?string $title = 'Capacidad Técnica General';

    protected static ?string $slug = 'gen-capacity';

    protected static ?string $navigationGroup = 'Reportes';

    protected static string $view = 'filament.pages.gen-capacity';

    protected static ?int $navigationSort = 2;

    use HasPageShield;

    public $capacidades;

    protected function getActions(): array
    {
        return [
            Action::make('Excel')->action('exportAllCapacityXls'),
            Action::make('Pdf')->action('exportAllCapacityPdf'),
        ];
    }

    public function exportAllCapacityXls()
    {
        return Excel::download(new Capacity, 'capacity.xlsx');
    }

    public function exportAllCapacityPdf()
    {
        $export = new Capacity();
        return Excel::download($export, 'capacity.pdf', \Maatwebsite\Excel\Excel::MPDF);
        //return Excel::download(new Capacity, 'capacity.pdf');
    }
}
