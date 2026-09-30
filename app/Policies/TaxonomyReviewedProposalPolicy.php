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
 * `TaxonomyCandidateConceptLinkPolicy`/`TaxonomyConceptRelationPolicy`. Solo lectura: sin
 * `create`/`update`/`delete` (la escritura real vive exclusivamente en
 * `ReviewedProposalService::freeze()`/`apply()`, nunca en un formulario de Filament).
 *
 * TASK-0005 re-audit (comentario `5917275454`, corrección 1): la versión anterior usaba semántica OR
 * sobre AMBOS permisos para `view()`, así que quien podía ver candidatos también podía abrir el
 * detalle de una propuesta de RELACIÓN y viceversa - una fuga de autorización entre tipos de origen.
 * Ahora la autorización se resuelve SEGÚN EL TIPO DE ORIGEN de la propuesta:
 * `TYPE_TERM_CONCEPT_LINK` exige el permiso de candidatos, `TYPE_CONCEPT_RELATION` el de relaciones.
 *
 * `viewAny()` sigue siendo OR a propósito: es el permiso de ENTRAR al listado, y quien puede ver al
 * menos un tipo debe poder abrirlo. Lo que evita la fuga es el filtrado del query, no este booleano -
 * ver `TaxonomyReviewedProposalResource::getEloquentQuery()`, que restringe las filas a los tipos que
 * el usuario efectivamente puede ver (y con eso también cubre el acceso por URL directa al detalle,
 * porque `ViewRecord` resuelve el registro contra ese mismo query).
 *
 * El comportamiento de super-admin NO se trata de forma especial acá: se hereda del modelo de
 * permisos normal del proyecto (Shield concede todos los permisos al rol `super_admin`), así que
 * ambos chequeos dan verdadero por la vía habitual. No se agrega ningún bypass que debilite la
 * policy.
 */
class TaxonomyReviewedProposalPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return self::canListCandidateLinkProposals($user)
            || self::canListRelationProposals($user);
    }

    public function view(User $user, TaxonomyReviewedProposal $taxonomyReviewedProposal): bool
    {
        return match ($taxonomyReviewedProposal->proposal_type) {
            TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK => $user->can('view_taxonomy::candidate::concept::link'),
            TaxonomyReviewedProposal::TYPE_CONCEPT_RELATION => $user->can('view_taxonomy::concept::relation'),
            default => false,
        };
    }

    public static function canListCandidateLinkProposals(User $user): bool
    {
        return $user->can('view_any_taxonomy::candidate::concept::link');
    }

    public static function canListRelationProposals(User $user): bool
    {
        return $user->can('view_any_taxonomy::concept::relation');
    }

    /**
     * Tipos de propuesta que este usuario puede listar. Devuelve `[]` cuando no puede ver ninguno -
     * `whereIn('proposal_type', [])` compila a `0 = 1` en Laravel, o sea cero filas, que es
     * exactamente el comportamiento buscado (no hay tipo centinela ni caso especial).
     */
    public static function visibleProposalTypes(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return array_values(array_filter([
            self::canListCandidateLinkProposals($user) ? TaxonomyReviewedProposal::TYPE_TERM_CONCEPT_LINK : null,
            self::canListRelationProposals($user) ? TaxonomyReviewedProposal::TYPE_CONCEPT_RELATION : null,
        ]));
    }
}
