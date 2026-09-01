<?php

namespace App\Filament\Resources\EmpresaResource\Pages;

use App\Filament\Resources\EmpresaResource;
use App\Filament\Resources\EmpresaResource\Widgets\StatsOverview;
use App\Http\Livewire\Empresa as LivewireEmpresa;
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

    // Filament v2 no tiene metodo en el Table builder para esto (eso es v3) -
    // se controla con esta propiedad en la pagina. Las opciones del selector
    // (5/10/25/50/-1) ya vienen asi por defecto via config('tables.pagination').
    protected int $defaultTableRecordsPerPageSelectOption = 5;

    protected function getHeaderWidgets(): array
    {
        return [
            StatsOverview::class,
            //CustomerResource\Widgets\CustomerOverview::class,
        ];
    }
}
