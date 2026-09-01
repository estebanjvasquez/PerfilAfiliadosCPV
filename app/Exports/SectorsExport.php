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

    /**
     * Rendimiento (mismo hallazgo que CompletionExport): distinctSectorIds() dispara 1 consulta
     * por empresa; con distinctSectorCountsFor() se calcula para las 402 empresas en 1 sola
     * consulta agrupada en PHP, en vez de 402 - critico contra Supabase (latencia de red real).
     */
    public function collection()
    {
        $empresas = Empresa::query()
            ->with(['sectorPrincipal', 'sectorSecundario'])
            ->orderBy('name')
            ->get();

        $sectorCounts = Empresa::distinctSectorCountsFor($empresas->pluck('id'));

        return $empresas->map(fn (Empresa $empresa) => [
            $empresa->id,
            $empresa->name,
            $empresa->sectorPrincipal?->name ?? 'Sin configurar',
            $empresa->sectorSecundario?->name ?? 'Sin configurar',
            $sectorCounts[$empresa->id] ?? 0,
        ]);
    }

    public function headings(): array
    {
        return ['ID', 'Nombre', 'Sector principal', 'Sector secundario', 'Cantidad de sectores'];
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
