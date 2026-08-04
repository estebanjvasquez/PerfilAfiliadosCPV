<?php

namespace App\Exports;

use Maatwebsite\Excel\Sheet;
use App\Models\ManagementView;
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

class ManagementExport implements FromCollection, ShouldAutoSize, WithHeadings, WithEvents, WithDrawings, WithColumnWidths
{
    /**
     * @return \Illuminate\Support\Collection
     */
    use Exportable;
    use \App\Exports\Concerns\AppendsNoAplicaRows;

    /**
     * Columna del reporte => sub_type correspondiente en EmpresaModuleStatus::SUB_TYPES[MODULE_GESTION].
     */
    protected const COLUMN_SUB_TYPES = [
        'Calidad' => 'calidad',
        'Ambiente' => 'ambiente',
        'Seguridad' => 'seguridad',
        'Gestion' => 'proyectos',
        'Credibilidad' => 'credibilidad',
        'Informacion' => 'seguridad_info',
    ];

    public function __construct(protected bool $isPdf = false)
    {
    }

    public function collection()
    {
        //return GenCatalog::query()->where('empresa_user.user_id', Auth::User()->id);
        $rows = ManagementView::query()->get();

        $marker = $this->isPdf
            ? \App\Models\EmpresaModuleStatus::NO_APLICA_LABEL_LARGO
            : \App\Models\EmpresaModuleStatus::NO_APLICA_LABEL_CORTO;

        // Módulo completo (para la fila faltante) y, en batch, todos los sub-tipos de gestión
        // marcados No Aplica por empresa (evita 1 query por fila/columna).
        $wholeModuleIds = \App\Models\EmpresaModuleStatus::noAplicaIdsFor(\App\Models\EmpresaModuleStatus::MODULE_GESTION);
        $subTypesByEmpresa = \App\Models\EmpresaModuleStatus::where('module', \App\Models\EmpresaModuleStatus::MODULE_GESTION)
            ->where('sub_type', '!=', \App\Models\EmpresaModuleStatus::SUB_TYPE_WHOLE)
            ->where('no_aplica', true)
            ->get()
            ->groupBy('empresa_id')
            ->map(fn ($group) => $group->pluck('sub_type')->all());

        // Cada columna se marca de forma independiente: si el módulo completo está en No
        // Aplica, se marcan las 6; si solo un sub-tipo puntual lo está, se marca solo esa columna.
        return $this->appendNoAplicaRows(
            $rows,
            $wholeModuleIds,
            9,
            $marker,
            2,
            function ($row) use ($wholeModuleIds, $subTypesByEmpresa, $marker) {
                $isWholeModuleNA = in_array($row->id, $wholeModuleIds);
                $rowSubTypes = $subTypesByEmpresa->get($row->id, []);

                foreach (self::COLUMN_SUB_TYPES as $attr => $subType) {
                    if ($isWholeModuleNA || in_array($subType, $rowSubTypes)) {
                        $row->setAttribute($attr, $marker);
                    }
                }
            }
        );
    }

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
            'B' => 55,
            'C' => 55,
            'G' => 35,
            'H' => 35,
            'I' => 35,

        ];
    }

    public function headings(): array
    {
        return [
            ["", "", "", "", "", "", "", "", ""],
            ["ID", "NOMBRE", "SECTOR", "CALIDAD", "AMBIENTE", "SEGURIDAD", "GESTION DE PROYECTOS", "CREDIBILIDAD Y TRANSPARENCIA", "SEGURIDAD DE LA INFORMACION"]
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
                $cellRange = 'A2:I2'; // All headers
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
            }


        ];
    }
}
