<?php

namespace App\Filament\Support;

use App\Models\EmpresaModuleStatus;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;

/**
 * Acción de cabecera "No Aplica" para los módulos del perfil.
 * Permite a la empresa declarar que un módulo no aplica a su actividad,
 * de modo que el perfil cuente como completo aunque no cargue datos.
 * Se usa dentro de RelationManagers anclados a una empresa concreta
 * (via ownerRecord), por lo que no necesita pedir seleccionar la empresa.
 */
class NoAplicaAction
{
    public static function make(string $module): Action
    {
        $label = EmpresaModuleStatus::MODULES[$module];

        return Action::make('no_aplica')
            ->label('No Aplica')
            ->icon('heroicon-o-x-circle')
            ->color('warning')
            ->modalHeading("No Aplica — {$label}")
            ->modalButton('Guardar')
            ->form([
                Toggle::make('no_aplica')
                    ->label("\"{$label}\" No Aplica para esta empresa")
                    ->helperText('Si lo activa, este módulo contará como completado en el perfil. Si luego carga datos en el módulo, la marca se elimina automáticamente.')
                    ->default(true),
            ])
            ->action(function (array $data, RelationManager $livewire) use ($module, $label) {
                EmpresaModuleStatus::setStatus((int) $livewire->ownerRecord->id, $module, (bool) $data['no_aplica']);

                Notification::make()
                    ->success()
                    ->title($data['no_aplica']
                        ? "\"{$label}\" marcado como No Aplica"
                        : "Se eliminó la marca No Aplica de \"{$label}\"")
                    ->send();
            });
    }
}
