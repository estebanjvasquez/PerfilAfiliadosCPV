<?php

namespace App\Exports\Concerns;

use App\Models\EmpresaModuleStatus;
use Illuminate\Support\Collection;

trait AppendsNoAplicaRows
{
    /**
     * Agrega filas "NO APLICA" para las empresas que declararon el módulo
     * como no aplicable y que no aparecen en la vista del reporte (las vistas
     * con INNER JOIN omiten a las empresas sin datos). Opcionalmente marca
     * las filas existentes de esas empresas mediante $markRow.
     */
    protected function appendNoAplicaRows(Collection $rows, string $module, int $totalColumns, ?callable $markRow = null): Collection
    {
        $naEmpresas = EmpresaModuleStatus::noAplicaEmpresas($module)
            ->orderBy('empresas.name')
            ->get(['empresas.id as id', 'empresas.name as name']);

        if ($naEmpresas->isEmpty()) {
            return $rows;
        }

        $naIds = $naEmpresas->pluck('id')->all();
        $presentIds = $rows->pluck('id')->filter()->unique()->all();

        if ($markRow !== null) {
            $rows->each(function ($row) use ($naIds, $markRow) {
                if (in_array($row->id, $naIds)) {
                    $markRow($row);
                }
            });
        }

        foreach ($naEmpresas as $emp) {
            if (in_array($emp->id, $presentIds)) {
                continue;
            }

            $row = array_fill(0, $totalColumns, '');
            $row[0] = $emp->id;
            $row[1] = $emp->name;
            $row[2] = 'NO APLICA';

            $rows->push($row);
        }

        return $rows;
    }
}
