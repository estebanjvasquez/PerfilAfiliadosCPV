<?php

namespace App\Filament\Resources\ManagementResource\Pages;

use App\Filament\Resources\ManagementResource;
use App\Models\Empresa;
use Filament\Pages\Actions;
use Filament\Forms\Components;
use Filament\Resources\Pages\CreateRecord;
use Filament\Forms;

class CreateManagement extends CreateRecord
{
    protected static string $resource = ManagementResource::class;

    protected function get(): array
    {
        $vemp = 'presences';

        $emps = Empresa::get();
        foreach ($emps as $key => $valuemp) {
        }

        return [];
    }
}
