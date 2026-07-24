<?php

namespace App\Exports;

use App\Models\Empresa;
use App\Models\EmpresaModuleStatus;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\BeforeWriting;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

class CompletionExport implements FromCollection, ShouldAutoSize, WithHeadings, WithEvents
{
    use Exportable;

    /**
     * A diferencia de los demas Export (que leen de una vista SQL ya armada),
     * el % de completitud se calcula en PHP por empresa via
     * Empresa::moduleBreakdown() — ver app/Models/Empresa.php.
     */
    public function collection()
    {
        return Empresa::query()->orderBy('name')->get()->map(function (Empresa $empresa) {
            $breakdown = $empresa->moduleBreakdown();

            return array_merge(
                [$empresa->id, $empresa->name, $empresa->completionPercentage()],
                array_column($breakdown, 'percentage')
            );
        });
    }

    public function headings(): array
    {
        return [
            'ID',
            'Nombre',
            '% Total',
            'Datos Generales',
            'Sectores y Servicios',
            'Contactos',
            EmpresaModuleStatus::MODULES[EmpresaModuleStatus::MODULE_RECURSOS],
            EmpresaModuleStatus::MODULES[EmpresaModuleStatus::MODULE_GESTION],
            EmpresaModuleStatus::MODULES[EmpresaModuleStatus::MODULE_PRESENCIA],
            EmpresaModuleStatus::MODULES[EmpresaModuleStatus::MODULE_EXPERIENCIAS],
            EmpresaModuleStatus::MODULES[EmpresaModuleStatus::MODULE_SOSTENIBILIDAD],
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
        ];
    }
}
