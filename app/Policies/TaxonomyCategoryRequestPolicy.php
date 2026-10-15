<?php

namespace App\Policies;

use App\Models\TaxonomyCategoryRequest;
use App\Models\User;

/**
 * TASK-0010C: las solicitudes de revisión de categoría las opera SOLO Super Admin.
 *
 * Se decide por rol y no por permisos de Shield a propósito: un permiso generado
 * (`view_any_taxonomy::category::request`) quedaría asignable a cualquier rol desde la pantalla de
 * roles, y la instrucción pide que los afiliados no puedan ver solicitudes ajenas ni sus detalles
 * administrativos. La solicitud incluye el contexto completo de otra empresa y de otro usuario.
 *
 * Nadie crea, edita ni borra solicitudes desde el panel: se crean sólo desde el formulario del
 * afiliado (TaxonomyCategoryRequestService::submit) y son el registro auditable.
 */
class TaxonomyCategoryRequestPolicy
{
    private function isSuperAdmin(User $user): bool
    {
        return $user->hasRole(config('filament-shield.super_admin.name'));
    }

    public function viewAny(User $user): bool
    {
        return $this->isSuperAdmin($user);
    }

    public function view(User $user, TaxonomyCategoryRequest $request): bool
    {
        return $this->isSuperAdmin($user);
    }

    /** Reintento manual del aviso por correo. */
    public function retryNotice(User $user, TaxonomyCategoryRequest $request): bool
    {
        return $this->isSuperAdmin($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, TaxonomyCategoryRequest $request): bool
    {
        return false;
    }

    public function delete(User $user, TaxonomyCategoryRequest $request): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, TaxonomyCategoryRequest $request): bool
    {
        return false;
    }

    public function forceDelete(User $user, TaxonomyCategoryRequest $request): bool
    {
        return false;
    }
}
