<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Servicios: se mantienen y se editan según los permisos del rol (ver SectorPolicy).
 * Borrar un servicio con empresas vinculadas lo impide ServiceResource (aviso de dependencias).
 */
class ServicePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user)
    {
        return $user->can('view_any_service');
    }

    public function view(User $user)
    {
        return $user->can('view_service');
    }

    public function create(User $user)
    {
        return $user->can('create_service');
    }

    public function update(User $user)
    {
        return $user->can('update_service');
    }

    public function delete(User $user)
    {
        return $user->can('delete_service');
    }

    public function deleteAny(User $user)
    {
        return $user->can('delete_any_service');
    }
}
