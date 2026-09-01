<?php

namespace App\Filament\Resources\EmpresaResource\Pages;

use App\Filament\Resources\EmpresaResource;
use App\Filament\Resources\EmpresaResource\Widgets\StatsOverview;
use Filament\Resources\Pages\ListRecords;
use App\Models\Empresa;
use App\Models\empresa_user;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;
use Filament\Tables;
use FIlament\Resources\Table;
use Livewire\Component;
use Illuminate\Contracts\View\View;
use Filament\Tables\Filter;
use Illuminate\Database\Eloquent\Collection;

class ListEmpresas extends ListRecords implements HasTable
{
    protected static string $resource = EmpresaResource::class;

    // Portado de feature/supplhi-postgres-buscador: reduce la pagina por defecto de 10 a 5
    // filas (menos filas visibles = tabla mas liviana para el cliente).
    // Tipo int|string|null (no solo int, como en v2) para respetar la firma de la propiedad
    // en Filament\Tables\Concerns\CanPaginateRecords (v3).
    protected int | string | null $defaultTableRecordsPerPageSelectOption = 5;

    protected function getHeaderWidgets(): array
    {
        return [
            StatsOverview::class,
            //CustomerResource\Widgets\CustomerOverview::class,
        ];
    }
}
