<?php

namespace App\Http\Livewire;

use Rappasoft\LaravelLivewireTables\DataTableComponent;
use Rappasoft\LaravelLivewireTables\Views\Column;
use App\Models\GenCatalog;

class CatalogTable extends DataTableComponent
{
    protected $model = GenCatalog::class;

    public function configure(): void
    {
        //$this->setPrimaryKey('id');
    }

    public function columns(): array
    {
        return [
            Column::make("Rif", "rif")
                ->sortable(),
            Column::make("Name", "name")
                ->sortable(),
            /*Column::make("Updated at", "updated_at")
                ->sortable(),*/
        ];
    }
}
