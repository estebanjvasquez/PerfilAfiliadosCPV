<?php

namespace App\Services\Taxonomy;

use App\Models\TaxonomyCategory;
use App\Models\TaxonomyTerm;
use App\Models\TaxonomyTermCpvRelation;
use App\Models\User;
use App\Services\TaxonomyAuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * TASK-0014 (Issue #2 `6736283026`, punto 4): asocia un término EXISTENTE del diccionario a una
 * categoría desde la ficha de la categoría. Nunca crea términos.
 *
 * **Reglas:**
 * - **Permiso:** crear relaciones término→CPV (`create` de `TaxonomyTermCpvRelationPolicy`), el mismo
 *   que exige el alta manual en «Relaciones Término↔CPV».
 * - **Relación nueva:** queda `needs_review` (en revisión), tipo `manual`, fuente
 *   `manual_category_link`. No llega al buscador de la empresa hasta que alguien con
 *   `taxonomy_publish` la apruebe por el flujo existente, que pide motivo y registra auditoría.
 * - **Duplicados:** si el término ya tiene una relación viva con la categoría (candidata,
 *   auto-mapeada, en revisión o aprobada), no se crea otra ni se toca la que existe; se informa su
 *   estado. Se compara por `category_id` y por `cpv_code`.
 * - **Relación previa rechazada o descartada:** no se duplica. Solo se reabre a «en revisión» si quien
 *   asocia lo pide expresamente y tiene `taxonomy_edit_relations`, el permiso de rechazar. Así una
 *   decisión editorial no se deshace sin querer.
 * - **Auditoría:** el alta y la reapertura quedan en `taxonomy_audit_logs` con el motivo.
 */
class TermCategoryLinker
{
    public const SOURCE = 'manual_category_link';

    public const RELATION_TYPE = 'manual';

    public const RESULT_CREATED = 'created';

    public const RESULT_EXISTS = 'exists';

    public const RESULT_NEEDS_REOPEN = 'needs_reopen';

    public const RESULT_REOPENED = 'reopened';

    private const CLOSED_STATUSES = [
        TaxonomyTermCpvRelation::STATUS_REJECTED,
        TaxonomyTermCpvRelation::STATUS_DEPRECATED,
    ];

    /**
     * @return array{result: string, relation: TaxonomyTermCpvRelation}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function link(User $user, TaxonomyCategory $category, TaxonomyTerm $term, float $weight, string $reason, bool $reopen = false): array
    {
        if (! Gate::forUser($user)->allows('create', TaxonomyTermCpvRelation::class)) {
            throw new AuthorizationException('No tiene permiso para crear relaciones término↔CPV.');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['motivo' => 'Indique el motivo de la asociación.']);
        }

        if ($weight < 0 || $weight > 1) {
            throw ValidationException::withMessages(['weight' => 'El peso debe estar entre 0 y 1.']);
        }

        try {
            return DB::connection('pgsql')->transaction(fn () => $this->linkLocked($user, $category, $term, $weight, $reason, $reopen));
        } catch (UniqueConstraintViolationException) {
            // Otra persona la creó al mismo tiempo: se informa la que quedó, sin duplicar.
            return ['result' => self::RESULT_EXISTS, 'relation' => $this->existing($category, $term)->firstOrFail()];
        }
    }

    /** Relaciones del término con esta categoría, la más reciente primero. */
    public function existing(TaxonomyCategory $category, TaxonomyTerm $term)
    {
        return TaxonomyTermCpvRelation::query()
            ->where('term_id', $term->id)
            ->where(fn ($q) => $q->where('category_id', $category->id)->orWhere('cpv_code', $category->code))
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
    }

    public static function isClosed(string $status): bool
    {
        return in_array($status, self::CLOSED_STATUSES, true);
    }

    /** @return array{result: string, relation: TaxonomyTermCpvRelation} */
    private function linkLocked(User $user, TaxonomyCategory $category, TaxonomyTerm $term, float $weight, string $reason, bool $reopen): array
    {
        $existing = $this->existing($category, $term)->lockForUpdate()->get();

        $live = $existing->first(fn (TaxonomyTermCpvRelation $r) => ! self::isClosed($r->status));
        if ($live) {
            return ['result' => self::RESULT_EXISTS, 'relation' => $live];
        }

        if ($existing->isNotEmpty()) {
            $closed = $existing->first();

            if (! $reopen) {
                return ['result' => self::RESULT_NEEDS_REOPEN, 'relation' => $closed];
            }

            if (! $user->can('taxonomy_edit_relations')) {
                throw new AuthorizationException('Reabrir una relación rechazada o descartada requiere el permiso de revisar relaciones.');
            }

            $oldStatus = $closed->status;
            $closed->update([
                'status' => TaxonomyTermCpvRelation::STATUS_NEEDS_REVIEW,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ]);

            TaxonomyAuditLogger::recordEvent(
                entityType: TaxonomyTermCpvRelation::class,
                entityId: $closed->id,
                field: 'status',
                oldValue: $oldStatus,
                newValue: TaxonomyTermCpvRelation::STATUS_NEEDS_REVIEW,
                reason: 'Reabierta desde la ficha de la categoría: '.$reason,
            );

            return ['result' => self::RESULT_REOPENED, 'relation' => $closed->refresh()];
        }

        $relation = TaxonomyTermCpvRelation::create([
            'term_id' => $term->id,
            'cpv_code' => $category->code,
            'category_id' => $category->id,
            'level' => match ($category->level) {
                TaxonomyCategory::LEVEL_GROUP => TaxonomyTermCpvRelation::LEVEL_GROUP,
                TaxonomyCategory::LEVEL_FAMILY => TaxonomyTermCpvRelation::LEVEL_FAMILY,
                default => TaxonomyTermCpvRelation::LEVEL_CATEGORY,
            },
            'relation_type' => self::RELATION_TYPE,
            'weight' => round($weight, 4),
            // Manual: no la propuso un algoritmo. La confianza no interviene en el buscador (usa el peso).
            'confidence' => 1.0,
            'matched_on' => 'Asociado a mano desde la ficha de la categoría: '.$reason,
            'evidence' => ['Asociación manual desde la ficha de la categoría (TASK-0014). Pendiente de revisión.'],
            'source' => self::SOURCE,
            'status' => TaxonomyTermCpvRelation::STATUS_NEEDS_REVIEW,
        ]);

        TaxonomyAuditLogger::recordEvent(
            entityType: TaxonomyTermCpvRelation::class,
            entityId: $relation->id,
            field: 'status',
            oldValue: null,
            newValue: TaxonomyTermCpvRelation::STATUS_NEEDS_REVIEW,
            reason: 'Asociada desde la ficha de la categoría: '.$reason,
        );

        return ['result' => self::RESULT_CREATED, 'relation' => $relation];
    }
}
