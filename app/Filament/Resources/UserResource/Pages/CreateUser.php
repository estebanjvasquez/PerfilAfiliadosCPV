<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Concerns\GuardsPrivilegedRoles;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    use GuardsPrivilegedRoles;

    protected static string $resource = UserResource::class;

    public function getTitle(): string
    {
        return trans('filament-user::user.resource.title.create');
    }

    /**
     * CPV-SEC-01-R2-ACCOUNT-PATH: a non-super-admin cannot create a privileged account. The role relationship is synced
     * after the record is created, so the privileged role is dropped here (not by `$fillable` or the UI).
     */
    protected function afterCreate(): void
    {
        $record = $this->getRecord();
        if ($record instanceof User) {
            $this->stripPrivilegedRoleGrants($record);
        }
    }
}
