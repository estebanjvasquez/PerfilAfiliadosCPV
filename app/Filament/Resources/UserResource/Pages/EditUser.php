<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Models\User;
use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions\DeleteAction;
use STS\FilamentImpersonate\Pages\Actions\Impersonate;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    public function mutateFormDataBeforeSave(array $data): array
    {
        $getUser = User::where('email', $data['email'])->first();
        if ($getUser) {
            if (empty($data['password'])) {
                $data['password'] = $getUser->password;
            }
        }
        return $data;
    }

    public function getTitle(): string
    {
        return trans('filament-user::user.resource.title.edit');
    }

    /**
     * Mismo rename de Filament v2 -> v3 que en ListUsers::getHeaderActions(): con
     * getActions() (nombre viejo) ni "Eliminar" ni "Impersonate" aparecian en Editar.
     */
    protected function getHeaderActions(): array
    {
        $ret = [];
        !config('filament-user.impersonate') ?: $ret[] = Impersonate::make()->record($this->getRecord());
        $ret[] = DeleteAction::make();

        return $ret;
    }
}
