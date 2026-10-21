<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Models\User;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Concerns\GuardsPrivilegedRoles;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions\DeleteAction;
use STS\FilamentImpersonate\Pages\Actions\Impersonate;

class EditUser extends EditRecord
{
    use GuardsPrivilegedRoles;

    protected static string $resource = UserResource::class;

    /**
     * CPV-SEC-01-R2-ACCOUNT-PATH: a non-super-admin cannot open a privileged account, so it cannot be modified through
     * this page. The check runs before the form is filled with the record's data.
     */
    protected function fillForm(): void
    {
        $this->assertMayManagePrivilegedTarget($this->getRecord());
        parent::fillForm();
    }

    /** After save, drop any privileged role a non-super-admin tried to grant. */
    protected function afterSave(): void
    {
        $this->stripPrivilegedRoleGrants($this->getRecord());
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
