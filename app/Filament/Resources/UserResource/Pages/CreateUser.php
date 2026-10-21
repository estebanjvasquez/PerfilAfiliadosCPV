<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    public function getTitle(): string
    {
        return trans('filament-user::user.resource.title.create');
    }

    // CPV-SEC-01-R2-ACCOUNT-PATH: the privileged-role grant is authorized before the sync, in the roles field's
    // saveRelationshipsUsing (UserResource::form), inside Filament's create transaction. No after-create cleanup.
}
