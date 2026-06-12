<?php
//ESTA ES LA PAGINA QUE LLAMA A LA VISTA..........................
namespace App\Filament\Pages;

use App\Exports\Catalog;
use Filament\Pages\Page;
use Filament\Resources\Table;
use Tables\Columns\TextColumn;
use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\Dompdf as DompdfDompdf;
use Filament\Pages\Actions\Action;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\GenCatalog as Catalogos;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Events\AfterSheet;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Events\BeforeSheet;
use PhpOffice\PhpSpreadsheet\Writer\Pdf\Dompdf;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use AlperenErsoy\FilamentExport\Actions\FilamentExportBulkAction;

class GenCatalog extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Catalogo General CPV';

    protected static ?string $title = 'Catálogo General CPV';

    protected static ?string $slug = 'gen-catalog';

    protected static ?string $navigationGroup = 'Reportes';

    protected static string $view = 'filament.pages.gen-catalog';

    protected static ?int $navigationSort = 1;

    use HasPageShield;

    public $catalogos;


    protected function getActions(): array
    {
        return [
            Action::make('Excel')->action('exportAllCatalogXls'),
            //Action::make('Pdf')->action('exportAllCatalogPdf'),

            /*  BeforeSheet::class => function (BeforeSheet $event) {
                $event->sheet
                    ->getPageSetup()
                    ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
            }, */
        ];
    }

    public function exportAllCatalogXls()
    {
        return Excel::download(new Catalog, 'catalogo.xlsx');
        //return (new Catalog)->download('catalogo.xlsx');
    }


    /* public function exportAllCatalogPdf()
    {
        // retreive all records from db
        $data = GenCatalog::all();

        // share data to view
        //view()->share('catalogo', $data);
        $pdf = Pdf::loadView('filament.pages.gen-catalog', $data);
        // download PDF file with download method
        return $pdf->download('pdf_file.pdf');
    } */


    public function exportAllCatalogPdf()
    {
        /* return Excel::download(new Catalog, 'catalogo.pdf', \Maatwebsite\Excel\Excel::TCPDF, [
            'title' => 'dsafds',
            'format' => 'A4-P',
            'orientation' => 'L'
        ]); */

        //return PDF::loadFile('gen-catalog')->save('my_stored_file.pdf');

        //return Excel::download(new Catalog, 'catalogo.pdf');

        return (new Catalog)->download('catalogo.pdf', \Maatwebsite\Excel\Excel::MPDF);
    }
}
