<?php

namespace App\Filament\Resources\ManagementResource\Pages;

use App\Filament\Resources\ManagementResource;
use Filament\Pages\Actions;
use Filament\Resources\Pages\EditRecord;
use App\Models\Management;
use Filament\Forms;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Card;
use Filament\Resources\Form;
use Filament\Resources\Resource;
use Filament\Resources\Table;
use Filament\Tables;
use Livewire\Component;

class EditManagement extends EditRecord
{
    protected static string $resource = ManagementResource::class;

    //use Forms\Concerns\InteractsWithForms;

    protected function getActions(): array
    {
        return [];
    }

    protected function get(): array
    {
        return [

            Actions\DeleteAction::make(),
            Actions\RestoreAction::make(),
            Actions\ForceDeleteAction::make(),
        ];
    }
}
