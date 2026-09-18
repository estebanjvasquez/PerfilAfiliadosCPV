<?php

namespace App\Services;

use App\Models\TaxonomyAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * TAXV2-9: helper invocado EXPLÍCITAMENTE desde las acciones sensibles del diccionario V2 (guardar
 * peso/threshold, aprobar/rechazar una relación, editar alias/fuente) - no es un Observer genérico
 * ciego, porque `reason` solo tiene sentido cuando el propio formulario de esa acción lo pidió (ver
 * docblock de la migración `create_taxonomy_audit_log_table`).
 *
 * No registra si `$oldValue == $newValue` (evita ruido de un "guardar" que no cambió nada - ej.
 * volver a correr `taxonomy:seed-settings` no genera filas si no había admin loggeado).
 *
 * TAXV3-1: `$actorType`/`$algorithmVersion` son opcionales con default `'user'`/`null` - TODOS los
 * call sites existentes (TAXV2-9 a TAXV2-13) siguen funcionando sin cambios y quedan correctamente
 * clasificados como decisión humana. Las fases nuevas que generan decisiones automáticas (TAXV3-3
 * Auto Mapper, TAXV3-5 Source Sync) pasan `actorType: 'system'` y su propio `algorithmVersion`.
 */
class TaxonomyAuditLogger
{
    public const ACTOR_USER = 'user';

    public const ACTOR_SYSTEM = 'system';

    public static function record(
        string $entityType,
        int|string $entityId,
        string $field,
        mixed $oldValue,
        mixed $newValue,
        ?string $reason = null,
        string $actorType = self::ACTOR_USER,
        ?string $algorithmVersion = null,
    ): void {
        if (self::stringify($oldValue) === self::stringify($newValue)) {
            return;
        }

        TaxonomyAuditLog::query()->create([
            'user_id' => Auth::id(),
            'entity_type' => $entityType,
            'entity_id' => (string) $entityId,
            'field' => $field,
            'old_value' => self::stringify($oldValue),
            'new_value' => self::stringify($newValue),
            'reason' => $reason,
            'actor_type' => $actorType,
            'algorithm_version' => $algorithmVersion,
            'created_at' => now(),
        ]);
    }

    /**
     * Registra cada campo modificado de un modelo ya guardado, comparando contra un snapshot
     * tomado ANTES de guardar (`$before`, típicamente `$record->getOriginal()` capturado en un hook
     * `beforeSave()`). No usar `$record->getOriginal($field)` después de guardar - Eloquent
     * resincroniza "original" al terminar `save()`/`update()` (`finishSave()` llama a
     * `syncOriginal()`), así que en `afterSave()` ya devuelve el valor NUEVO, no el viejo - bug real
     * encontrado al verificar esta fase: el guard de no-op de `record()` terminaba comparando el
     * valor nuevo contra sí mismo y descartaba la fila silenciosamente, sin loggear nada.
     *
     * `$entityId` es opcional porque algunas tablas (`taxonomy_sources`) no usan `id` como
     * identificador visible para el admin, pero sí como clave primaria real (necesaria para poder
     * revertir por `Model::find()` en TAXV2-11).
     */
    public static function recordModelChanges(Model $record, array $before, int|string|null $entityId = null, array $ignoreFields = ['updated_at', 'created_at']): void
    {
        foreach ($record->getChanges() as $field => $newValue) {
            if (in_array($field, $ignoreFields, true)) {
                continue;
            }

            self::record(
                entityType: get_class($record),
                entityId: $entityId ?? $record->getKey(),
                field: $field,
                oldValue: $before[$field] ?? null,
                newValue: $newValue,
            );
        }
    }

    private static function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        return (string) $value;
    }
}
