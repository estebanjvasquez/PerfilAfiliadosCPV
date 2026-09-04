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
        $empresas = Empresa::query()->withCompletionData()->orderBy('name')->get();

        // Contacto principal (pedido del cliente, reemplaza al viejo "Usuario principal"
        // inferido por antiguedad de vinculacion - ver Empresa::principalContactNamesFor()):
        // 1 sola consulta en bloque para TODAS las empresas exportadas, no 1 por fila.
        $principalContacts = Empresa::principalContactNamesFor($empresas->pluck('id'));

        return $empresas->map(function (Empresa $empresa) use ($principalContacts) {
            $breakdown = $empresa->moduleBreakdown();

            return array_merge(
                [
                    $empresa->id,
                    $empresa->name,
                    $principalContacts[$empresa->id] ?? 'Falta por asignar',
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
            'Contacto principal',
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
