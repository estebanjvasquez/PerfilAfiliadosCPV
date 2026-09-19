<?php

namespace App\Policies;

use App\Models\TaxonomyConceptRelation;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Phase 3: mismo patrón/convención de permisos que TaxonomyCanonicalConceptPolicy - un admin con
 * acceso al panel debe recibir estos permisos vía el flujo de roles/Shield ya existente en el
 * proyecto (misma housekeeping que cualquier resource nuevo).
 */
class TaxonomyConceptRelationPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_taxonomy::concept::relation');
    }

    public function view(User $user, TaxonomyConceptRelation $taxonomyConceptRelation): bool
    {
        return $user->can('view_taxonomy::concept::relation');
    }

    public function create(User $user): bool
    {
        return $user->can('create_taxonomy::concept::relation');
    }

    public function update(User $user, TaxonomyConceptRelation $taxonomyConceptRelation): bool
    {
        return $user->can('update_taxonomy::concept::relation');
    }

    public function delete(User $user, TaxonomyConceptRelation $taxonomyConceptRelation): bool
    {
        return $user->can('delete_taxonomy::concept::relation');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_taxonomy::concept::relation');
    }
}
