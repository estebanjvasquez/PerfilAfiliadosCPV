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
     *
     * Rendimiento (hallazgo de Fase 2): sin `withCompletionData()` + el mapa de
     * `principalUserNamesFor()`, este export dispara ~12 consultas por empresa (una por cada
     * relacion que moduleBreakdown() toca sin eager-load, mas principalUser()) - invisible en
     * MySQL local, pero ~30 minutos contra Supabase por la latencia de red real medida (~400ms
     * por consulta desde este entorno). Con el eager-load + el mapa en bloque, el export entero
     * pasa a un puñado de consultas totales en vez de ~4800 (402 empresas x ~12).
     */
    public function collection()
    {
        $empresas = Empresa::query()->withCompletionData()->orderBy('name')->get();
        $principalUserNames = Empresa::principalUserNamesFor($empresas->pluck('id'));

        return $empresas->map(function (Empresa $empresa) use ($principalUserNames) {
            $breakdown = $empresa->moduleBreakdown();
            $totalPercentage = (int) round(collect($breakdown)->avg('percentage'));

            return array_merge(
                [
                    $empresa->id,
                    $empresa->name,
                    $principalUserNames[$empresa->id] ?? 'Sin usuario asignado',
                    $totalPercentage,
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
