<?php

namespace App\Policies;

use App\Models\TaxonomyCandidateConceptLink;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Phase 3: mismo patrón/convención de permisos que TaxonomyCanonicalConceptPolicy.
 */
class TaxonomyCandidateConceptLinkPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_taxonomy::candidate::concept::link');
    }

    public function view(User $user, TaxonomyCandidateConceptLink $taxonomyCandidateConceptLink): bool
    {
        return $user->can('view_taxonomy::candidate::concept::link');
    }

    public function update(User $user, TaxonomyCandidateConceptLink $taxonomyCandidateConceptLink): bool
    {
        return $user->can('update_taxonomy::candidate::concept::link');
    }
}
