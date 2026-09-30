<?php

namespace App\Models;

use App\Services\Taxonomy\CanonicalConceptBuilderService;
use App\Services\Taxonomy\ReviewedProposalService;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 3 (sección 5 del pedido): relación semántica TIPADA entre dos `taxonomy_canonical_concepts`.
 * Distinta de `taxonomy_term_concepts` (que es IDENTIDAD/EXPRESIÓN término->concepto) - esta tabla
 * vive exclusivamente al nivel concepto->concepto (ej. un futuro PART_OF entre "Torre y Mástil de
 * Perforación" y "Equipo de Perforación").
 *
 * Desde Phase C1 (`--apply`), esta tabla SÍ se puebla (`status=candidate`) - ya no queda
 * garantizado en 0 filas.
 *
 * TASK-0003, hallazgo 6: `TaxonomyConceptRelationResource` es un CRUD genérico de Filament con un
 * `Select` plano para `status` - nada revalidaba duplicado/simétrico/inverso/ciclo al pasar de
 * `candidate` a `approved` (una relación pudo ser válida cuando se encoló y quedar inválida para
 * cuando un humano la revisa - otra relación pudo aprobarse mientras tanto). El guard de abajo lo
 * hace a nivel de MODELO (no solo en el recurso de Filament) para que valga sin importar el punto
 * de entrada (Filament, tinker, una futura API) - mismo criterio que el resto del proyecto usa para
 * poner la lógica de seguridad en servicios/modelos, no solo en la capa de UI.
 */
class TaxonomyConceptRelation extends Model
{
    protected $connection = 'pgsql';

    public const STATUS_CANDIDATE = 'candidate';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'source_concept_id',
        'target_concept_id',
        'relation_type',
        'weight',
        'confidence',
        'status',
        'provenance',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'weight' => 'float',
        'confidence' => 'float',
        'provenance' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function sourceConcept()
    {
        return $this->belongsTo(TaxonomyCanonicalConcept::class, 'source_concept_id');
    }

    public function targetConcept()
    {
        return $this->belongsTo(TaxonomyCanonicalConcept::class, 'target_concept_id');
    }

    // TASK-0005 (Issue #2 comentario `5914793857`): visibilidad de la propuesta congelada C2 desde
    // la UI de la relación - `freeze()` NUNCA toca `status`/`reviewed_at` de esta fila.
    public function reviewedProposals()
    {
        return $this->hasMany(TaxonomyReviewedProposal::class, 'concept_relation_id');
    }

    /**
     * TASK-0003, hallazgo 6: bloquea CUALQUIER guardado (Filament, tinker, lo que sea) que deje
     * `status=approved` sin volver a pasar `validateConceptRelationProposal()` en ese momento -
     * mismas reglas que al proponer (duplicado exacto, simétrico, vía inverso, ciclos),
     * re-preguntadas contra el estado real, no el de cuando se encoló. `excludeId: $this->id` para
     * que la fila no se detecte a sí misma como su propio duplicado exacto al revalidarse. Solo
     * corre cuando `status` efectivamente cambia hacia `approved` (creación directa como approved,
     * o transición desde candidate) - no en cada guardado irrelevante.
     *
     * TASK-0004, re-audit HIGH-2 (Issue #2 comentario `5890113782`): además de la revalidación
     * semántica de arriba (que sigue corriendo igual), esta transición ahora exige que
     * `ReviewedProposalService::isApplyingC2Publication()` esté encendida - "approved" para esta
     * tabla ES "publicado" (mismo criterio que `taxonomy_candidate_concept_links.status=published`),
     * así que cualquier camino que intente esta transición por fuera del `apply()` autorizado de
     * Phase C2 (ej. editar la relación a mano desde `EditTaxonomyConceptRelation`) debe fallar acá,
     * no solo ser válida semánticamente.
     */
    protected static function booted(): void
    {
        static::saving(function (self $relation) {
            if (! $relation->isDirty('status') || $relation->status !== self::STATUS_APPROVED) {
                return;
            }

            if (! ReviewedProposalService::isApplyingC2Publication()) {
                throw new \RuntimeException(
                    'No se puede aprobar (status=approved) una relación fuera del apply() autorizado de Phase C2 '.
                    '(ReviewedProposalService::apply()) - ver TASK-0004, hallazgo HIGH-2. Congelá una decisión con '.
                    'freeze() y aplicala con una referencia de autorización explícita.'
                );
            }

            $validation = app(CanonicalConceptBuilderService::class)->validateConceptRelationProposal(
                $relation->source_concept_id,
                $relation->target_concept_id,
                $relation->relation_type,
                excludeId: $relation->exists ? $relation->id : null,
            );

            if (! $validation['valid']) {
                throw new \RuntimeException(
                    "No se puede aprobar: la relación ya no es válida ({$validation['reason']}). ".
                    'Puede que otra relación equivalente se haya aprobado mientras esta esperaba revisión. '.
                    'Revisar de nuevo antes de decidir.'
                );
            }
        });
    }
}
