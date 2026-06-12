<?php
//ESTE ES EL CONTROLADOR..............................
namespace App\Exports;

use PDF;
use App\Models\GenCatalog;
use Maatwebsite\Excel\Sheet;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View as ViewView;
use Maatwebsite\Excel\Concerns\FromView;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Events\BeforeSheet;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\BeforeExport;
use PhpOffice\PhpSpreadsheet\Style\Border;
use Maatwebsite\Excel\Events\BeforeWriting;
use Illuminate\Database\Eloquent\Collection;
use Maatwebsite\Excel\Concerns\WithDrawings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class Catalog implements FromQuery, ShouldAutoSize, WithHeadings, WithEvents, WithDrawings, WithColumnWidths
// ESTE!!! class Catalog implements FromQuery, ShouldAutoSize, WithHeadings
//class Catalog implements FromView, ShouldAutoSize
//class Catalog implements FromCollection, ShouldAutoSize, WithHeadings
{
    use Exportable;


    //EN CASO DE QUE QUIERA USAR COLLECTION...........
    /* public function collection()
    {
        return GenCatalog::all();
        //return view('filament.pages.gen-catalog', ['GenCatalog' => GenCatalog::all()]);
    } */


    // ESTOY USANDO ESTE!
    public function query()
    {
        //SI QUIERO HACERLO DE UNA EMPRESA ESPECIFICA.....................................
        return GenCatalog::query(); //->where('empresa_user.user_id', Auth::User()->id);
        //COMENTADO 25/10....
        //return GenCatalog::query();
    }

    //EN CASO DE QUE QUIERA USAR VIEW.................
    /* public function view(): View
    {
        return view('filament.pages.gen-catalog', ['catalogos' => GenCatalog::all()]);
    } */


    //ESTE USARLO CUANDO TENGA LOS NOMBRES DE COLUMNAS CORRECTOS...........
    /* public function headings(): array
    {
        return array_keys($this->query()->first()->toArray());
    } */

    //PARA AGREGAR IMAGEN DE LOGO.......................................
    public function drawings()
    {
        $drawing = new Drawing();
        $drawing->setName('Logo');
        $drawing->setDescription('This is my logo');
        $drawing->setPath(public_path('/images/capet.jpg'));
        $drawing->setHeight(55);
        $drawing->setCoordinates('A1');

        return $drawing;
    }

    public function columnWidths(): array
    {
        return [
            'B' => 60,
            'C' => 60,
            'D' => 60,
            'J' => 55,
            'K' => 50,
        ];
    }

    //ESTE USARLO CUANDO QUIERO CUSTOMIZAR LOS NOMBRES DE COLUMNAS.............
    public function headings(): array
    {
        return [
            ["", "", "", "", "", "", "", "", "", "", ""],
            ["ID", "NOMBRE", "SECTOR", "DIRECCIÓN", "CIUDAD", "AÑO DE FUNDACIÓN", "RIF", "TELEFONO", "WEBSITE", "CONTACTOS", "CAMARAS"],
        ];
    }

    public function registerEvents(): array
    {
        return [
            BeforeWriting::class => function (BeforeWriting $event) {
                $event->getWriter()
                    ->getDelegate()
                    ->getActiveSheet()
                    ->getPageSetup()
                    ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
            },

            // Handle by a closure.
            BeforeExport::class => function (BeforeExport $event) {
                $event->writer->getProperties()->setCreator('Eric');
            },


            AfterSheet::class => function (AfterSheet $event) {
                $cellRange = 'A2:K2'; // All headers
                $event->sheet->getDelegate()->getStyle($cellRange)->getFont()->setSize(14);
                $event->sheet->getDelegate()->getStyle($cellRange)->getFill()->applyFromArray(['fillType' => 'solid', 'rotation' => 0, 'color' => ['rgb' => 'D9D9D9'],]);
                $event->sheet->getDelegate()->getRowDimension('1')->setRowHeight(40);
                $event->sheet->getDelegate()->getRowDimension('2')->setRowHeight(20);

                $event->sheet->getDelegate()->getStyle('K8')->getAlignment()->setWrapText(true);

                /*  // Apply array of styles to B2:G8 cell range
                $styleArray = [
                    'borders' => [
                        'outline' => [
                            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                            'color' => ['argb' => '000000'],
                        ]
                    ]
                ];

                $event->sheet->getDelegate()->getStyle('A1:E10')->applyFromArray($styleArray);

                //$event->sheet->getDelegate()->getStyle('B2:G8')->applyFromArray($styleArray);
 */

                /* $rightBorder = array(
                    'borders' => [
                        'outline' => [
                            'borderStyle' => Border::BORDER_THIN,
                        ]
                    ],
                );
                //$event->sheet->getStyle("A2:J2")->applyFromArray($rightBorder);

                $event->sheet->getStyle('A1')->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                            'color' => ['argb' => '000000'],
                        ],
                    ],
                ]);*/

                Sheet::macro('styleCells', function (Sheet $sheet, string $cellRange, array $style) {
                    $sheet->getDelegate()->getStyle($cellRange)->applyFromArray($style);
                });

                $event->sheet->styleCells('A:A', [
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);

                $event->sheet->styleCells('F:F', [
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);
            }


        ];
    }
}
