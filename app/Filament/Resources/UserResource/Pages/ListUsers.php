<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions\CreateAction;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    public function getTitle(): string
    {
        return trans('filament-user::user.resource.title.list');
    }

    /**
     * El stub publicado por 3x1io/filament-user (v1.1.11, 7 sep 2026) trae este metodo
     * como getActions() - nombre de Filament v2, en v3 el hook real es
     * getHeaderActions() (ver Filament\Resources\Pages\ListRecords). Con el nombre
     * viejo el boton "Crear" simplemente no aparecia, sin error visible.
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
