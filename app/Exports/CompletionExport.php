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
        // withCompletionData() eager-carga lo que necesita moduleBreakdown()/
        // completionPercentage() - sin esto, cada empresa exportada dispara ~8 queries lazy
        // propias (mismo problema que en CompletionView.php y GerenciaMetrics::baseQuery()).
        // principalUser() sigue siendo 1 query por empresa (hace su propia consulta
        // personalizada sobre la relacion, no es cacheable por eager-load simple) - queda como
        // mejora pendiente, menor que el x8 que se resuelve aca.
        return Empresa::query()->withCompletionData()->orderBy('name')->get()->map(function (Empresa $empresa) {
            $breakdown = $empresa->moduleBreakdown();

            return array_merge(
                [
                    $empresa->id,
                    $empresa->name,
                    $empresa->principalUser()?->name ?? 'Sin usuario asignado',
                    $empresa->completionPercentage(),
                ],
                array_column($breakdown, 'percentage')
            );
        });
    }

    public function headings(): array
    {
        return [
            'ID',
            'Nombre',
            'Usuario principal',
            '% Total',
            'Datos generales',
            'Sectores y servicios',
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
