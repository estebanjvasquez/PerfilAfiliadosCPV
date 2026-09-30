<?php

namespace App\Policies;

use App\Models\TaxonomyReviewedProposal;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * TASK-0005 (Issue #2 comentario `5914793857`), sección C: `TaxonomyReviewedProposal` no tenía
 * Policy propia (era una tabla nueva de TASK-0004, sin UI todavía). Filament exige un Policy real
 * para que `ListRecords`/`ViewRecord` autoricen la página (no alcanza con sobreescribir
 * `Resource::canAccess()` - eso solo gobierna la visibilidad del ítem de navegación, `canViewAny()`/
 * `canView()` siguen resolviéndose vía Policy por separado).
 *
 * Deliberadamente NO se generan permisos Shield nuevos (`*_taxonomy::reviewed::proposal`) para esto
 * - sembrar permisos nuevos es una acción de configuración/base de datos fuera del alcance mínimo de
 * esta tarea. En cambio, se reusan los permisos YA sembrados de
 * `TaxonomyCandidateConceptLinkPolicy`/`TaxonomyConceptRelationPolicy` (cualquiera de los dos
 * alcanza para ver esta tabla de auditoría - toda propuesta pertenece a una entidad de uno de esos
 * dos tipos). Solo lectura: sin `create`/`update`/`delete` (la escritura real vive exclusivamente en
 * `ReviewedProposalService::freeze()`/`apply()`, nunca en un formulario de Filament).
 */
class TaxonomyReviewedProposalPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_taxonomy::candidate::concept::link')
            || $user->can('view_any_taxonomy::concept::relation');
    }

    public function view(User $user, TaxonomyReviewedProposal $taxonomyReviewedProposal): bool
    {
        return $user->can('view_taxonomy::candidate::concept::link')
            || $user->can('view_taxonomy::concept::relation');
    }
}
