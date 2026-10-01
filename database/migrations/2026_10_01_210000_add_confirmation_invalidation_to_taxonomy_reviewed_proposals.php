<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-0006C — reparación de procedencia de confirmación (Issue #2 comentarios
 * `5939882569` «CONFIRMATION-PROVENANCE CORRECTION DESIGN — PASS FOR IMPLEMENTATION» y
 * `5939903005` «EXPLICIT HUMAN AUTHORIZATION», punto 1).
 *
 * POR QUÉ EXISTE. El re-audit `5938949812` dictaminó que la confirmación almacenada de #492–#495 no
 * es procedencia humana válida: el agente las ejecutó desde consola autenticando la cuenta #3 con
 * `Auth::login()`, satisfaciendo `Auth::id() === $confirmer->id` y derrotando justamente el
 * invariante anti-suplantación que ese chequeo existe para sostener. Una referencia de gobernanza
 * prueba QUÉ decidió el dueño, no que el usuario #3 de la aplicación EJECUTÓ personalmente la
 * confirmación.
 *
 * El trigger de TASK-0006B hace esos campos inmutables **a propósito**, así que la atribución
 * incorrecta no se puede sobrescribir por ningún camino existente. Esta migración agrega el único
 * camino sancionado, y es deliberadamente estrecho.
 *
 * LA ASIMETRÍA ES EL NÚCLEO DEL DISEÑO:
 *
 *   - ANULAR una confirmación (los cuatro campos a NULL)  -> permitido SOLO bajo una autorización de
 *     corrección declarada, con rastro obligatorio;
 *   - REASIGNAR una confirmación (otro confirmador/fecha/referencia) -> SIGUE PROHIBIDO, sin
 *     excepción.
 *
 * Por eso el único desenlace posible de una corrección es «esta propuesta vuelve a estar sin
 * confirmar», y la única forma de volver a confirmarla es la acción autenticada de Filament.
 * NINGUNA ruta permite inventar un confirmador. Es exactamente el punto 2 del contrato aceptado:
 * «Invalidation may only transition a confirmed proposal back to UNCONFIRMED; it must never assign a
 * replacement confirmer».
 *
 * ESTRICTAMENTE ADITIVA: solo `ADD COLUMN` nulables, un `CHECK` y un `CREATE OR REPLACE FUNCTION`
 * del trigger ya existente. Cero `DROP COLUMN`, cero `ALTER TYPE`, cero reescritura de datos. Esta
 * migración **no toca ninguna fila**: la corrección de #492–#495 es una operación de servicio
 * posterior, con lista blanca y verificación previa (punto 5 del contrato).
 *
 * NOTA DE ATRIBUCIÓN, aprendida del propio defecto que se corrige: `confirmation_invalidated_by_id`
 * queda **NULL** cuando la corrección la ejecuta el agente. Poner ahí la cuenta #3 repetiría el
 * error exacto que se está reparando - atribuir a una persona una acción que no realizó. El actor
 * real se registra con la verdad en `confirmation_invalidation_actor_type` (`agent`) y
 * `confirmation_invalidation_channel` (auto-capturado), y la autorización del dueño vive en
 * `confirmation_invalidation_reference`.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        $c = DB::connection('pgsql');

        $c->statement(<<<'SQL'
            ALTER TABLE taxonomy_reviewed_proposals
                ADD COLUMN confirmation_invalidated_at TIMESTAMP NULL,
                ADD COLUMN confirmation_invalidated_by_id BIGINT NULL,
                ADD COLUMN confirmation_invalidation_actor_type VARCHAR(30) NULL,
                ADD COLUMN confirmation_invalidation_channel VARCHAR(20) NULL,
                ADD COLUMN confirmation_invalidation_reference VARCHAR(255) NULL,
                ADD COLUMN confirmation_invalidation_reason TEXT NULL,
                ADD COLUMN invalidated_confirmation_snapshot JSONB NULL
        SQL);

        // Una anulación es todo-o-nada, igual que `taxonomy_reviewed_proposals_confirmation_complete`
        // para la confirmación. `confirmation_invalidated_by_id` queda FUERA del CHECK a propósito:
        // es NULL cuando la corrección no la ejecutó una persona (ver nota de atribución arriba).
        $c->statement(<<<'SQL'
            ALTER TABLE taxonomy_reviewed_proposals
                ADD CONSTRAINT taxonomy_reviewed_proposals_invalidation_complete CHECK (
                    (confirmation_invalidated_at IS NULL
                        AND confirmation_invalidation_actor_type IS NULL
                        AND confirmation_invalidation_channel IS NULL
                        AND confirmation_invalidation_reference IS NULL
                        AND invalidated_confirmation_snapshot IS NULL)
                    OR (confirmation_invalidated_at IS NOT NULL
                        AND confirmation_invalidation_actor_type IS NOT NULL
                        AND confirmation_invalidation_channel IS NOT NULL
                        AND confirmation_invalidation_reference IS NOT NULL
                        AND invalidated_confirmation_snapshot IS NOT NULL)
                )
        SQL);

        $c->statement('CREATE INDEX taxonomy_reviewed_proposals_invalidated_idx ON taxonomy_reviewed_proposals (id) WHERE confirmation_invalidated_at IS NOT NULL');

        $this->replaceConfirmationGuardTrigger($c);
    }

    /**
     * Reemplaza SOLO el cuerpo de la función del trigger (el trigger en sí sigue siendo el mismo,
     * creado en TASK-0006B). Las dos reglas originales quedan intactas y se les antepone la única
     * excepción nueva.
     *
     * La excepción exige las SEIS condiciones a la vez, y además que ningún campo de decisión cambie
     * en el mismo UPDATE: así este camino privilegiado no puede usarse para colar una modificación
     * de la decisión. El alcance del trigger sigue NO cubriendo
     * `decision`/`decision_payload`/`payload_fingerprint` en el camino NORMAL - eso es deliberado y
     * ya documentado en TASK-0006B, porque los tests aprobados de tamper-detection de TASK-0004 los
     * modifican a propósito para probar que `apply()` lo detecta, y guardarlos ahí volvería
     * inalcanzable un guard aprobado. Acá se exigen iguales SOLO dentro de la excepción.
     *
     * La GUC `app.taxonomy_confirmation_correction` la fija el servicio con `SET LOCAL`, así que vive
     * únicamente dentro de esa transacción: no puede quedar encendida por accidente para una
     * transacción posterior.
     */
    private function replaceConfirmationGuardTrigger($c): void
    {
        $c->statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION taxonomy_reviewed_proposals_guard_confirmation()
            RETURNS trigger AS $$
            BEGIN
                -- ================================================================================
                -- TASK-0006C: UNICA excepcion sancionada - ANULACION auditada de una confirmacion.
                -- Nunca reasignacion: los cuatro campos de confirmacion tienen que quedar en NULL.
                -- ================================================================================
                IF OLD.confirmed_at IS NOT NULL
                   AND NEW.confirmed_at IS NULL
                   AND NEW.confirmed_by_id IS NULL
                   AND NEW.confirmation_reference IS NULL
                   AND NEW.confirmation_channel IS NULL
                   AND NEW.confirmation_invalidated_at IS NOT NULL
                   AND NEW.status = 'PENDING_APPLY'
                   AND NEW.requires_human_confirmation = TRUE
                   AND coalesce(current_setting('app.taxonomy_confirmation_correction', true), '') <> ''
                THEN
                    -- El camino privilegiado NO puede tocar la decision ni su procedencia de revision.
                    IF NEW.decision IS DISTINCT FROM OLD.decision
                       OR NEW.decision_payload::text IS DISTINCT FROM OLD.decision_payload::text
                       OR NEW.payload_fingerprint IS DISTINCT FROM OLD.payload_fingerprint
                       OR NEW.taxonomy_state_fingerprint IS DISTINCT FROM OLD.taxonomy_state_fingerprint
                       OR NEW.payload_version IS DISTINCT FROM OLD.payload_version
                       OR NEW.reviewer_id IS DISTINCT FROM OLD.reviewer_id
                       OR NEW.reviewed_at IS DISTINCT FROM OLD.reviewed_at
                       OR NEW.proposal_type IS DISTINCT FROM OLD.proposal_type
                       OR NEW.candidate_link_id IS DISTINCT FROM OLD.candidate_link_id
                       OR NEW.concept_relation_id IS DISTINCT FROM OLD.concept_relation_id
                       OR NEW.proposal_group_id IS DISTINCT FROM OLD.proposal_group_id
                       OR NEW.prepared_by_actor_type IS DISTINCT FROM OLD.prepared_by_actor_type
                       OR NEW.prepared_via IS DISTINCT FROM OLD.prepared_via
                       OR NEW.applied_at IS DISTINCT FROM OLD.applied_at
                    THEN
                        RAISE EXCEPTION 'taxonomy_reviewed_proposals: la anulacion de una confirmacion (propuesta %) no puede modificar la decision, sus fingerprints, su procedencia de revision ni su origen. Se intento cambiar al menos uno de esos campos en el mismo UPDATE.', OLD.id;
                    END IF;

                    RETURN NEW;
                END IF;

                -- ================================================================================
                -- Reglas originales de TASK-0006B, intactas.
                -- ================================================================================
                IF OLD.requires_human_confirmation = TRUE AND NEW.requires_human_confirmation = FALSE THEN
                    RAISE EXCEPTION 'taxonomy_reviewed_proposals: requires_human_confirmation no puede apagarse una vez encendido (propuesta %). Quitar la compuerta de confirmacion humana exige una autorizacion de gobernanza explicita, no un UPDATE.', OLD.id;
                END IF;

                IF OLD.confirmed_at IS NOT NULL AND (
                    NEW.confirmed_at IS DISTINCT FROM OLD.confirmed_at
                    OR NEW.confirmed_by_id IS DISTINCT FROM OLD.confirmed_by_id
                    OR NEW.confirmation_reference IS DISTINCT FROM OLD.confirmation_reference
                    OR NEW.confirmation_channel IS DISTINCT FROM OLD.confirmation_channel
                ) THEN
                    RAISE EXCEPTION 'taxonomy_reviewed_proposals: una confirmacion humana ya grabada es inmutable (propuesta %). No se puede reasignar a otro confirmador ni reescribir su momento/referencia.', OLD.id;
                END IF;

                -- Una anulacion ya grabada tampoco se reescribe ni se borra.
                IF OLD.confirmation_invalidated_at IS NOT NULL AND (
                    NEW.confirmation_invalidated_at IS DISTINCT FROM OLD.confirmation_invalidated_at
                    OR NEW.confirmation_invalidation_reference IS DISTINCT FROM OLD.confirmation_invalidation_reference
                    OR NEW.invalidated_confirmation_snapshot::text IS DISTINCT FROM OLD.invalidated_confirmation_snapshot::text
                ) THEN
                    RAISE EXCEPTION 'taxonomy_reviewed_proposals: el rastro de una anulacion ya grabada es inmutable (propuesta %).', OLD.id;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }

    public function down(): void
    {
        $c = DB::connection('pgsql');

        // Restaura la función del trigger a su forma de TASK-0006B (sin la excepción de anulación).
        $c->statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION taxonomy_reviewed_proposals_guard_confirmation()
            RETURNS trigger AS $$
            BEGIN
                IF OLD.requires_human_confirmation = TRUE AND NEW.requires_human_confirmation = FALSE THEN
                    RAISE EXCEPTION 'taxonomy_reviewed_proposals: requires_human_confirmation no puede apagarse una vez encendido (propuesta %).', OLD.id;
                END IF;

                IF OLD.confirmed_at IS NOT NULL AND (
                    NEW.confirmed_at IS DISTINCT FROM OLD.confirmed_at
                    OR NEW.confirmed_by_id IS DISTINCT FROM OLD.confirmed_by_id
                    OR NEW.confirmation_reference IS DISTINCT FROM OLD.confirmation_reference
                    OR NEW.confirmation_channel IS DISTINCT FROM OLD.confirmation_channel
                ) THEN
                    RAISE EXCEPTION 'taxonomy_reviewed_proposals: una confirmacion humana ya grabada es inmutable (propuesta %).', OLD.id;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        $c->statement('DROP INDEX IF EXISTS taxonomy_reviewed_proposals_invalidated_idx');
        $c->statement('ALTER TABLE taxonomy_reviewed_proposals DROP CONSTRAINT IF EXISTS taxonomy_reviewed_proposals_invalidation_complete');
        $c->statement(<<<'SQL'
            ALTER TABLE taxonomy_reviewed_proposals
                DROP COLUMN IF EXISTS confirmation_invalidated_at,
                DROP COLUMN IF EXISTS confirmation_invalidated_by_id,
                DROP COLUMN IF EXISTS confirmation_invalidation_actor_type,
                DROP COLUMN IF EXISTS confirmation_invalidation_channel,
                DROP COLUMN IF EXISTS confirmation_invalidation_reference,
                DROP COLUMN IF EXISTS confirmation_invalidation_reason,
                DROP COLUMN IF EXISTS invalidated_confirmation_snapshot
        SQL);
    }
};
