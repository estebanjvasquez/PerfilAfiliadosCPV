<?php

namespace App\Exports;

use App\Models\User;
use App\Models\JoinViewsModel;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithDrawings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use Maatwebsite\Excel\Events\BeforeWriting;
use Maatwebsite\Excel\Events\BeforeExport;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Sheet;

class JoinViewExport implements FromCollection, ShouldAutoSize, WithHeadings, WithEvents, WithDrawings, WithColumnWidths
{
    /**
     * @return \Illuminate\Support\Collection
     */

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

    public function headings(): array
    {
        return [
            ["", "", "", "", "", "", "", "", "", "", ""],
            [
                "NOMBRE", "RIF", "DIRECCION", "CIUDAD", "WEBSITE", "TELEFONO", "CONTACTOS", "FUNDACION", "CAPITAL", "ORIGEN",
                "FACTURACION ANUAL", "ESTADO", "EMPLEADOS", "SECTOR", "SERVICIOS", "INSTALACIONES", "CLIENTES",
                "ISO9001", "ISO17025", "QUALITY OTROS", "ISO14001", "ISO50001", "AMBIENTE OTROS",
                "DUN", "ISO37001", "CREDIBILIDAD OTROS", "ISO45001", "OVID", "SEGURIDAD OTROS", "PMI", "PMI OTROS",
                "ISO27001", "INFO OTROS"
            ],
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
                $cellRange = 'A2:AG2'; // All headers
                $event->sheet->getDelegate()->getStyle($cellRange)->getFont()->setSize(14);
                $event->sheet->getDelegate()->getStyle($cellRange)->getFill()->applyFromArray(['fillType' => 'solid', 'rotation' => 0, 'color' => ['rgb' => 'D9D9D9'],]);
                $event->sheet->getDelegate()->getRowDimension('1')->setRowHeight(40);
                $event->sheet->getDelegate()->getRowDimension('2')->setRowHeight(20);

                $event->sheet->getDelegate()->getStyle('K8')->getAlignment()->setWrapText(true);
                Sheet::macro('styleCells', function (Sheet $sheet, string $cellRange, array $style) {
                    $sheet->getDelegate()->getStyle($cellRange)->applyFromArray($style);
                });

                $event->sheet->styleCells('A:A', [
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);

                $event->sheet->styleCells('AG:AG', [
                    'alignment' => [
                        'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                    ],
                ]);
            }


        ];
    }

    public function collection()
    {
        $record = explode("/", explode("/", $_SERVER['REQUEST_URI'])[2]);

        $data = JoinViewsModel::leftjoin('catalogoView', 'catalogoView.id', '=', 'capacityView.id')
            ->leftjoin('ClientsView', 'ClientsView.id', '=', 'capacityView.id')
            ->leftjoin('FinanceView', 'FinanceView.id', '=', 'capacityView.id')
            ->leftjoin('ManagementDetView', 'ManagementDetView.id', '=', 'capacityView.id')
            ->where('capacityView.id', '=', $record)
            ->get([
                'catalogoView.name as nombre', 'catalogoView.rif as rif', 'catalogoView.street as direccion',
                'catalogoView.CIUDAD as ciudad', 'catalogoView.website', 'catalogoView.phone as telefono', 'catalogoView.CONTACTOS as contactos',
                'catalogoView.fundacion', 'FinanceView.CAPITAL as capital', 'FinanceView.ORIGEN as origen',
                'FinanceView.BILLING as facturacion_anual', 'FinanceView.ESTADO as estado_actual', 'FinanceView.rrhh as empleados',
                'capacityView.Sector as sector', 'capacityView.Servicios as servicios',
                'capacityView.instalaciones as inst', 'ClientsView.cliente as clientes',
                'ManagementDetView.iso9001', 'ManagementDetView.iso17025', 'ManagementDetView.QUALITY_OTROS as quality_otros',
                'ManagementDetView.iso14001', 'ManagementDetView.iso50001', 'ManagementDetView.ENVIRONMENT_OTROS as environment_otros',
                'ManagementDetView.dun', 'ManagementDetView.iso37001', 'ManagementDetView.CREDIBILITY_OTROS as credibility_otros',
                'ManagementDetView.iso45001', 'ManagementDetView.ovid', 'ManagementDetView.SECURITY_OTROS as security_otros',
                'ManagementDetView.pmi', 'ManagementDetView.PMI_OTROS as pmi_otros', 'ManagementDetView.iso27001',
                'ManagementDetView.INFO_OTROS as info_otros',

            ]);
        return $data;
    }
}
