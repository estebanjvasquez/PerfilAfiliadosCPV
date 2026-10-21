<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Concerns\UsesAccountWriteTransaction;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    use UsesAccountWriteTransaction;

    protected static string $resource = UserResource::class;

    public function getTitle(): string
    {
        return trans('filament-user::user.resource.title.create');
    }

    // CPV-SEC-01-R2-ACCOUNT-PATH: the privileged-role grant is authorized before the sync, in the roles field's
    // saveRelationshipsUsing (UserResource::form). The account and its roles are written in one transaction, enabled
    // explicitly for this page (UsesAccountWriteTransaction; Filament's panel default is off). No after-create cleanup.
}
