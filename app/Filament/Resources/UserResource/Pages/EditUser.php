<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Models\User;
use App\Filament\Resources\UserResource;
use App\Support\Security\PrivilegedRoleGuard;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions\DeleteAction;
use STS\FilamentImpersonate\Pages\Actions\Impersonate;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * CPV-SEC-01-R2-ACCOUNT-PATH (`6310331211` R2-REV-01): a non-super-admin cannot open — and so cannot modify — an
     * account that already holds the privileged role. Checked fresh (a pivot query, not mounted/cached state), right
     * after the resource's own edit authorization. authorizeAccess() runs in mount() and again at the start of save(),
     * so the privileged target is rejected before any write. The role set itself is authorized before it is synced, in
     * the roles field's saveRelationshipsUsing (UserResource::form).
     */
    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        $record = $this->getRecord();
        if ($record instanceof User
            && ! PrivilegedRoleGuard::actorMayGrantPrivileged()
            && PrivilegedRoleGuard::userHasPrivilegedRole($record)) {
            abort(403);
        }
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
