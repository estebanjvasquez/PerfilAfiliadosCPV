<?php

namespace App\Exports;

use Maatwebsite\Excel\Sheet;
use App\Models\ResourceView;
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


class ResourceExport implements FromCollection, ShouldAutoSize, WithHeadings, WithEvents, WithDrawings, WithColumnWidths
{

    /**
     * @return \Illuminate\Support\Collection
     */
    use Exportable;
    use \App\Exports\Concerns\AppendsNoAplicaRows;

    public function __construct(protected bool $isPdf = false)
    {
    }

    public function collection()
    {
        //return GenCatalog::query()->where('empresa_user.user_id', Auth::User()->id);
        $rows = ResourceView::query()->get();

        $marker = $this->isPdf
            ? \App\Models\EmpresaModuleStatus::NO_APLICA_LABEL_LARGO
            : \App\Models\EmpresaModuleStatus::NO_APLICA_LABEL_CORTO;

        // 'employee' = sub-tipo "Recursos Humanos", el que cubre este reporte puntual
        $naIds = \App\Models\EmpresaModuleStatus::noAplicaIdsFor(\App\Models\EmpresaModuleStatus::MODULE_RECURSOS, 'employee');

        // Empresas presentes en la vista (con datos reales) que igual están marcadas No Aplica:
        // se deja intacta la data numérica y solo se anota en la columna "Estado" dedicada, para
        // no romper el tipo numérico de las columnas de conteo (ver PLAN_DESPLIEGUE... Fase 5).
        $rows->each(function ($row) use ($naIds, $marker) {
            if (in_array($row->id, $naIds)) {
                $row->setAttribute('Estado', $marker);
            }
        });

        // La vista omite a las empresas sin recursos cargados: se agregan las "No Aplica"
        return $this->appendNoAplicaRows($rows, $naIds, 22, $marker, 21);
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

        ];
    }
    public function headings(): array
    {
        return [
            ["", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", "", ""],
            ["ID", "NOMBRE", "BACHILLERES JUNIOR", "BACHILLERES MEDIUM", "BACHILLERES SENIOR", "TÉCNICOS JUNIOR", "TÉCNICOS MEDIUM", "TÉCNICOS SENIOR", "INGENIEROS JUNIOR", "INGENIEROS MEDIUM", "INGENIEROS SENIOR", "ADMINISTRATIVOS JUNIOR", "ADMINISTRATIVOS MEDIUM", "ADMINISTRATIVOS SENIOR", "GERENTES JUNIOR", "GERENTES MEDIUM", "GERENTES SENIOR", "DIRECTIVOS JUNIOR", "DIRECTIVOS MEDIUM", "DIRECTIVOS SENIOR", "TOTAL", "ESTADO"],
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
                $cellRange = 'A2:V2'; // All headers
                $event->sheet->getDelegate()->getStyle($cellRange)->getFont()->setSize(14);
                $event->sheet->getDelegate()->getStyle($cellRange)->getFill()->applyFromArray(['fillType' => 'solid', 'rotation' => 0, 'color' => ['rgb' => 'D9D9D9'],]);
                $event->sheet->getDelegate()->getRowDimension('1')->setRowHeight(40);
                $event->sheet->getDelegate()->getRowDimension('2')->setRowHeight(20);


                //$event->sheet->getDelegate()->getStyle('K8')->getAlignment()->setWrapText(true);


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

                $event->sheet->styleCells('D:D', [
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);

                $event->sheet->styleCells('E:E', [
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

                $event->sheet->styleCells('H:H', [
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

                $event->sheet->styleCells('K:K', [
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

                $event->sheet->styleCells('N:N', [
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);

                $event->sheet->styleCells('O:O', [
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);

                $event->sheet->styleCells('P:P', [
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

                $event->sheet->styleCells('S:S', [
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);

                $event->sheet->styleCells('T:T', [
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);

                $event->sheet->styleCells('U:U', [
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);

                /*  $event->sheet->styleCells('E:E', [
                    'borders' => [
                        'allborders' => [
                            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        ],
                    ],
                ]); */
            }


        ];
    }
}
