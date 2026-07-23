<?php

namespace App\Exports;

use Maatwebsite\Excel\Sheet;
use App\Models\ManagementDetView;
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

class ManagementDetExport implements FromCollection, ShouldAutoSize, WithHeadings, WithEvents, WithDrawings, WithColumnWidths
{
    /**
     * @return \Illuminate\Support\Collection
     */
    use Exportable;
    use \App\Exports\Concerns\AppendsNoAplicaRows;

    public function collection()
    {
        //return GenCatalog::query()->where('empresa_user.user_id', Auth::User()->id);
        $rows = ManagementDetView::query()->get();

        // La vista omite a las empresas sin sistemas de gestión: se agregan las "No Aplica"
        return $this->appendNoAplicaRows($rows, \App\Models\EmpresaModuleStatus::MODULE_GESTION, 18);
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
            'C' => 15,
            'D' => 15,
            'E' => 20,
            'G' => 15,
            'I' => 15,
            'K' => 20,
            'M' => 15,
            'O' => 18,
            'Q' => 18,
        ];
    }
    public function headings(): array
    {
        return [
            ["", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", ""],
            ["", "", "Calidad", "", "", "Ambiente", "", "", "Credibilidad y Transparencia", "", "", "Seguridad", "", "", "Gestión de Proyectos", "", "Seguridad de la Información", "", ""],
            ["ID", "NOMBRE", "ISO9001", "ISO17025", "CALIDAD: OTROS", "ISO14001", "ISO50001", "AMBIENTE: OTROS", "DUN", "ISO37001", "CREDIBILIDAD: OTROS", "ISO45001", "COVID", "SEGURIDAD: OTROS", "PMI", "PMI: OTROS", "ISO27001", "SEGURIDAD: OTROS"],
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
                $cellRange = 'A2:R2'; // FIRST HEADERS
                $cellRange2 = 'A3:R3'; // SECOND HEADERS
                $event->sheet->getDelegate()->getStyle($cellRange)->getFont()->setSize(12);
                $event->sheet->getDelegate()->getStyle($cellRange)->getFill()->applyFromArray(['fillType' => 'solid', 'rotation' => 0, 'color' => ['rgb' => 'C9C9C9'],]);

                $event->sheet->getDelegate()->getStyle($cellRange2)->getFont()->setSize(13);
                $event->sheet->getDelegate()->getStyle($cellRange2)->getFill()->applyFromArray(['fillType' => 'solid', 'rotation' => 0, 'color' => ['rgb' => 'D9D9D9'],]);

                $event->sheet->getDelegate()->getRowDimension('1')->setRowHeight(40);
                $event->sheet->getDelegate()->getRowDimension('2')->setRowHeight(20);
                $event->sheet->getDelegate()->getRowDimension('3')->setRowHeight(20);

                $event->sheet->mergeCells('C2:E2');
                $event->sheet->mergeCells('F2:H2');
                $event->sheet->mergeCells('I2:K2');
                $event->sheet->mergeCells('L2:N2');
                $event->sheet->mergeCells('O2:P2');
                $event->sheet->mergeCells('Q2:R2');


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

                $event->sheet->styleCells('F:F', [
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

                $event->sheet->styleCells('R:R', [
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);
            }
        ];
    }
}
