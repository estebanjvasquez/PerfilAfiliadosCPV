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

    /**
     * TASK-0003 (cierre de gate, Issue #2 comentario `5877665979`): `$authorizationReference`/
     * `$targetEnvironment` son opcionales con default `null` - todos los call sites existentes
     * (humanos, vía Filament) siguen funcionando sin cambios. Solo `CanonicalConceptApplyService`
     * los pasa hoy, porque es la única escritura que corre sin un usuario autenticado detrás.
     */
    public static function record(
        string $entityType,
        int|string $entityId,
        string $field,
        mixed $oldValue,
        mixed $newValue,
        ?string $reason = null,
        string $actorType = self::ACTOR_USER,
        ?string $algorithmVersion = null,
        ?string $authorizationReference = null,
        ?string $targetEnvironment = null,
    ): void {
        if (self::stringify($oldValue) === self::stringify($newValue)) {
            return;
        }

        self::write($entityType, $entityId, $field, $oldValue, $newValue, $reason, $actorType, $algorithmVersion, $authorizationReference, $targetEnvironment);
    }

    /**
     * Registra un EVENTO que ocurrió, sin el guard de no-op de `record()`.
     *
     * POR QUÉ EXISTE (corrección de la revisión `6167931953`, punto 4). `record()` descarta la fila
     * cuando el valor viejo y el nuevo son iguales, lo cual es correcto para un CAMBIO DE VALOR: un
     * "guardar" que no cambió nada no debe ensuciar la bitácora. Pero hay escrituras que no son un
     * cambio de valor sino un HECHO - "esta confirmación fue anulada" - y ahí la igualdad de dos
     * timestamps no significa que no pasó nada, significa que pasó dentro del mismo segundo.
     *
     * Medido: Laravel liga las fechas con el formato `Y-m-d H:i:s` del grammar, sin microsegundos,
     * así que una confirmación y su anulación en el mismo segundo producían dos cadenas idénticas y
     * el guard DESCARTABA EN SILENCIO el rastro de auditoría. Un evento de gobernanza que
     * desaparece porque ocurrió rápido es un defecto, no ruido.
     *
     * Deliberadamente es un método APARTE y no un parámetro de `record()`: el comportamiento
     * genérico de no-op se conserva intacto para los 20+ call sites que sí registran cambios de
     * valor, y quien quiera saltárselo tiene que decirlo explícitamente en el nombre que invoca.
     * No se "formatea un lado distinto" para esquivar la igualdad: el hecho se declara como hecho.
     */
    public static function recordEvent(
        string $entityType,
        int|string $entityId,
        string $field,
        mixed $oldValue,
        mixed $newValue,
        ?string $reason = null,
        string $actorType = self::ACTOR_USER,
        ?string $algorithmVersion = null,
        ?string $authorizationReference = null,
        ?string $targetEnvironment = null,
    ): void {
        self::write($entityType, $entityId, $field, $oldValue, $newValue, $reason, $actorType, $algorithmVersion, $authorizationReference, $targetEnvironment);
    }

    private static function write(
        string $entityType,
        int|string $entityId,
        string $field,
        mixed $oldValue,
        mixed $newValue,
        ?string $reason,
        string $actorType,
        ?string $algorithmVersion,
        ?string $authorizationReference,
        ?string $targetEnvironment,
    ): void {
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
            'authorization_reference' => $authorizationReference,
            'target_environment' => $targetEnvironment,
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
