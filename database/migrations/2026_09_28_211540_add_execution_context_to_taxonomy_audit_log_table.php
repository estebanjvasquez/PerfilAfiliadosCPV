<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-0003, cierre del gate de cierre (Issue #2, comentario `5877665979`), hallazgo de
 * autorización de escritura: `authorizedBy` (un string libre no vacío) era un campo de
 * atribución útil pero no lo que se pidió originalmente - "record the exact human authorization
 * AND target environment before a real --apply", persistido de forma estructurada, no solo
 * embebido en la prosa de `reason`.
 *
 * Mismo patrón que `add_actor_columns_to_taxonomy_audit_log_table` (TAXV3-1): columnas nuevas,
 * nullable, sin backfill de historial (las filas de `--apply` ya escritas por TASK-0001 quedan sin
 * estos dos campos - no se reescribe auditoría pasada).
 *
 * - `authorization_reference`: identificador de la autorización (ej. "Issue #2 comment
 *   5877665979", "TASK-0003"), no un nombre libre - `CanonicalConceptApplyService::apply()` exige
 *   que contenga al menos un dígito (nudge de formato, explícitamente NO un mecanismo de
 *   autenticación/RBAC - el propio hallazgo pidió no sobre-ingenierizar esto).
 * - `target_environment`: NUNCA provisto por quien llama a `apply()` - se auto-captura de
 *   `app()->environment()` dentro del propio servicio, precisamente para no repetir el mismo
 *   defecto señalado en `authorizedBy` (un string arbitrario que cualquiera podría inventar).
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            ALTER TABLE taxonomy_audit_log
                ADD COLUMN authorization_reference VARCHAR(255) NULL,
                ADD COLUMN target_environment VARCHAR(30) NULL
        SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            ALTER TABLE taxonomy_audit_log
                DROP COLUMN IF EXISTS authorization_reference,
                DROP COLUMN IF EXISTS target_environment
        SQL);
    }
};
