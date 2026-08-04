<?php

namespace App\Exports\Concerns;

use App\Models\Empresa;
use Illuminate\Support\Collection;

trait AppendsNoAplicaRows
{
    /**
     * Agrega filas para las empresas marcadas "No Aplica" (módulo completo o un sub-tipo
     * específico, según lo que el caller haya resuelto en $naEmpresaIds vía
     * EmpresaModuleStatus::noAplicaIdsFor()) que no aparecen en la vista del reporte (las vistas
     * con INNER JOIN omiten a las empresas sin datos). Opcionalmente marca las filas existentes
     * de esas empresas mediante $markRow.
     *
     * $markerText es el texto a usar ("Marcado no aplica por el afiliado" en pantalla/Pdf,
     * "NA/afiliado" en Excel — ver EmpresaModuleStatus::NO_APLICA_LABEL_*), y $markerColumn el
     * índice (0-based) de la fila sintética donde va ese texto — normalmente la primera columna
     * de datos (índice 2), o una columna "Estado" dedicada al final cuando las columnas de datos
     * son numéricas (ver ResourceExport).
     */
    protected function appendNoAplicaRows(Collection $rows, array $naEmpresaIds, int $totalColumns, string $markerText, int $markerColumn = 2, ?callable $markRow = null): Collection
    {
        if (empty($naEmpresaIds)) {
            return $rows;
        }

        $presentIds = $rows->pluck('id')->filter()->unique()->all();

        if ($markRow !== null) {
            $rows->each(function ($row) use ($naEmpresaIds, $markRow) {
                if (in_array($row->id, $naEmpresaIds)) {
                    $markRow($row);
                }
            });
        }

        $missingIds = array_diff($naEmpresaIds, $presentIds);

        if (empty($missingIds)) {
            return $rows;
        }

        $names = Empresa::whereIn('id', $missingIds)->orderBy('name')->pluck('name', 'id');

        foreach ($names as $id => $name) {
            $row = array_fill(0, $totalColumns, '');
            $row[0] = $id;
            $row[1] = $name;
            $row[$markerColumn] = $markerText;

            $rows->push($row);
        }

        return $rows;
    }
}
