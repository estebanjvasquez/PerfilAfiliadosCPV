<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class SectorPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function viewAny(User $user)
    {
        return $user->can('view_any_sector');
    }

    /**
     * Determine whether the user can view the model.
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Auth\Access\Response|bool
     */
    public function view(User $user)
    {
        return $user->can('view_sector');
    }

    /**
     * Catálogo viejo puesto en SOLO LECTURA (15 sep 2026) al retomar la Fase 3 del proyecto de
     * taxonomía: la clasificación por Sector/Servicio sigue siendo la fuente de los reportes
     * actuales (por eso no se toca ni se inactiva ningún dato), pero deja de ser editable desde el
     * panel - la taxonomía CPV nueva (`TaxonomyCategoryResource`) es la que se sigue manteniendo
     * hacia adelante. Hardcodeado a `false` (ya no delega en `$user->can(...)`) para que ni
     * siquiera Super Admin pueda crear/editar/borrar un Sector por error - `viewAny`/`view` no se
     * tocan, siguen dependiendo del permiso de siempre.
     */
    public function create(User $user)
    {
        return false;
    }

    public function update(User $user)
    {
        return false;
    }

    public function delete(User $user)
    {
        return false;
    }

    public function deleteAny(User $user)
    {
        return false;
    }

}
