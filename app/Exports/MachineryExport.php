<?php

namespace App\Exports;

use Maatwebsite\Excel\Sheet;
use App\Models\MachineryView;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\BeforeExport;
use Maatwebsite\Excel\Events\BeforeWriting;
use Maatwebsite\Excel\Concerns\WithDrawings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

class MachineryExport implements FromCollection, ShouldAutoSize, WithHeadings, WithEvents, WithDrawings, WithColumnWidths
{
    /**
     * @return \Illuminate\Support\Collection
     */
    use Exportable;
    use \App\Exports\Concerns\AppendsNoAplicaRows;

    public function collection()
    {
        //return GenCatalog::query()->where('empresa_user.user_id', Auth::User()->id);
        $rows = MachineryView::query()->get();

        // La vista omite a las empresas sin recursos cargados: se agregan las "No Aplica"
        return $this->appendNoAplicaRows($rows, \App\Models\EmpresaModuleStatus::MODULE_RECURSOS, 20);
    }

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
            'B' => 50,
            'C' => 18,
            'E' => 18,
            'G' => 18,
            'I' => 18,
            'K' => 18,
            'M' => 18,
            'O' => 18,
            'Q' => 18,
            'S' => 18,

        ];
    }
    public function headings(): array
    {
        return [
            ["", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", ""],
            ["", "", "Equipos de medición, levantamiento (survey)", "", "Equipos marino-costeros fluviales o costa afuera", "", "Movimiento de tierra y construcción", "", "Equipos menores de construcción", "", "Fabricación metalmecánica / electromecánica / electrónica", "", "Montaje eléctrico/mecánico", "", "Máquinas herramientas / Metalmecánica", "", "Almacenamiento y transporte", "", "Servicios a pozos e instalaciones petroleras"],
            ["ID", "NOMBRE", "CANTIDAD", "VALOR ESTIMADO", "CANTIDAD", "VALOR ESTIMADO", "CANTIDAD", "VALOR ESTIMADO", "CANTIDAD", "VALOR ESTIMADO", "CANTIDAD", "VALOR ESTIMADO", "CANTIDAD", "VALOR ESTIMADO", "CANTIDAD", "VALOR ESTIMADO", "CANTIDAD", "VALOR ESTIMADO", "CANTIDAD", "VALOR ESTIMADO"],
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
                $cellRange = 'A2:T2'; // FIRST HEADERS
                $cellRange2 = 'A3:T3'; // SECOND HEADERS
                $event->sheet->getDelegate()->getStyle($cellRange)->getFont()->setSize(12);
                $event->sheet->getDelegate()->getStyle($cellRange)->getFill()->applyFromArray(['fillType' => 'solid', 'rotation' => 0, 'color' => ['rgb' => 'C9C9C9'],]);

                $event->sheet->getDelegate()->getStyle($cellRange2)->getFont()->setSize(13);
                $event->sheet->getDelegate()->getStyle($cellRange2)->getFill()->applyFromArray(['fillType' => 'solid', 'rotation' => 0, 'color' => ['rgb' => 'D9D9D9'],]);

                $event->sheet->getDelegate()->getRowDimension('1')->setRowHeight(40);
                $event->sheet->getDelegate()->getRowDimension('2')->setRowHeight(20);
                $event->sheet->getDelegate()->getRowDimension('3')->setRowHeight(20);

                $event->sheet->mergeCells('C2:D2');
                $event->sheet->mergeCells('E2:F2');
                $event->sheet->mergeCells('G2:H2');
                $event->sheet->mergeCells('I2:J2');
                $event->sheet->mergeCells('K2:L2');
                $event->sheet->mergeCells('M2:N2');
                $event->sheet->mergeCells('O2:P2');
                $event->sheet->mergeCells('Q2:R2');
                $event->sheet->mergeCells('S2:T2');

                Sheet::macro('styleCells', function (Sheet $sheet, string $cellRange, array $style) {
                    $sheet->getDelegate()->getStyle($cellRange)->applyFromArray($style);
                });

                $event->sheet->styleCells('A:A', [
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);

                $event->sheet->styleCells('C:C', [
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);

                $event->sheet->styleCells('E:E', [
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);

                $event->sheet->styleCells('G:G', [
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);

                $event->sheet->styleCells('I:I', [
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);

                $event->sheet->styleCells('J:J', [
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);

                $event->sheet->styleCells('L:L', [
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);

                $event->sheet->styleCells('M:M', [
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);

                $event->sheet->styleCells('O:O', [
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);

                $event->sheet->styleCells('Q:Q', [
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);

                $event->sheet->styleCells('S:S', [
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);
            }
        ];
    }
}
