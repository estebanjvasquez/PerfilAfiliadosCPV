<?php

namespace App\Filament\Pages;

use App\Exports\ClientsExport;
use Filament\Pages\Page;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Actions\Action;
use Maatwebsite\Excel\Facades\Excel;

class ClientsView extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Experiencia - Clientes';

    protected static ?string $title = 'Experiencia - Clientes';

    protected static ?string $slug = 'clients-view';

    protected static ?string $navigationGroup = 'Reportes';

    protected static string $view = 'filament.pages.clients-view';

    protected static ?int $navigationSort = 6;

    use HasPageShield;

    public $clients;

    protected function getActions(): array
    {
        return [
            Action::make('Excel')->action('exportAllClientsXls'),
            //Action::make('Pdf')->action('exportAllClientsPdf'),
        ];
    }

    public function exportAllClientsXls()
    {
        return Excel::download(new ClientsExport, 'clientes.xlsx');
        //return (new Catalog)->download('catalogo.xlsx');
    }

    public function exportAllClientsPdf()
    {

        return Excel::download(new ClientsExport, 'clientes.pdf');
    }
}
