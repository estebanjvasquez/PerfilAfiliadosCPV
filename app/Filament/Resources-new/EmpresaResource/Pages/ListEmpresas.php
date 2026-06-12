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

    protected function getHeaderWidgets(): array
    {
        return [
            StatsOverview::class,
            //CustomerResource\Widgets\CustomerOverview::class,
        ];
    }
}
