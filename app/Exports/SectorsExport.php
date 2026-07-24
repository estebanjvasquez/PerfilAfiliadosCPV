<?php

namespace App\Exports;

use App\Models\Empresa;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\BeforeWriting;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

class SectorsExport implements FromCollection, ShouldAutoSize, WithHeadings, WithEvents
{
    use Exportable;

    public function collection()
    {
        return Empresa::query()
            ->with(['sectorPrincipal', 'sectorSecundario'])
            ->orderBy('name')
            ->get()
            ->map(fn (Empresa $empresa) => [
                $empresa->id,
                $empresa->name,
                $empresa->sectorPrincipal?->name ?? 'Sin configurar',
                $empresa->sectorSecundario?->name ?? 'Sin configurar',
                count($empresa->distinctSectorIds()),
            ]);
    }

    public function headings(): array
    {
        return ['ID', 'Nombre', 'Sector Principal', 'Sector Secundario', 'Cantidad de Sectores'];
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
        ];
    }
}
