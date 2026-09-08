<?php

namespace App\Filament\Resources\TaxonomyCategoryResource\Pages;

use App\Filament\Resources\TaxonomyCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTaxonomyCategory extends CreateRecord
{
    protected static string $resource = TaxonomyCategoryResource::class;

    /**
     * Bug real encontrado el 8 sep 2026 al probar de punta a punta el flujo guiado de creación
     * (nunca se había creado una categoría nueva desde el panel hasta ahora, solo editado
     * existentes): `source_version` es NOT NULL en la base pero el campo del formulario está
     * `disabled()->dehydrated(false)` (es de solo lectura, muestra de dónde vino la fila) - sin
     * esto, CUALQUIER alta desde el panel fallaba con una violación de NOT NULL. 'panel' distingue
     * las filas creadas a mano acá de las que trae un Excel de Lorenzo (`taxonomy:import` usa la
     * fecha de la carga).
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['source_version'] = 'panel';

        return $data;
    }
}
