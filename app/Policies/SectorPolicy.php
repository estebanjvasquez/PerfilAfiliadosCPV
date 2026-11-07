<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Sectores: se mantienen y se editan como cualquier catálogo del panel, según los permisos del rol.
 * Decisión del propietario (2026-11-07): sectores y servicios siguen siendo dato vigente y la taxonomía
 * CPV es información adicional, no su reemplazo. Antes (desde `8352cad`) estaban fijados en solo lectura.
 * Borrar un sector con servicios lo impide SectorResource (aviso de dependencias).
 */
class SectorPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user)
    {
        return $user->can('view_any_sector');
    }

    public function view(User $user)
    {
        return $user->can('view_sector');
    }

    public function create(User $user)
    {
        return $user->can('create_sector');
    }

    public function update(User $user)
    {
        return $user->can('update_sector');
    }

    public function delete(User $user)
    {
        return $user->can('delete_sector');
    }

    public function deleteAny(User $user)
    {
        return $user->can('delete_any_sector');
    }
}
