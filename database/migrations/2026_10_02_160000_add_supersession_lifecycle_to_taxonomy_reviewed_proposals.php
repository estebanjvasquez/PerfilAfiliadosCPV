<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-0006E (Issue #2 comentario `5955148859`, autorización explícita del dueño de la taxonomía tras
 * el PASS de TASK-0006D en `5954835892`): implementa el ciclo de vida **SUPERSEDED** no destructivo —
 * la arquitectura de la OPCIÓN A diseñada en
 * `docs/orquestador/designs/0006d-stale-proposal-supersession.md` y aceptada en ese re-audit.
 *
 * EL PROBLEMA QUE RESUELVE. Tres propuestas reales (#420/#421/#422, los términos `petroleum`,
 * `crude oil` y `oil and gas`) quedaron OBSOLETAS: el grafo de conceptos cambió después de que se
 * revisaran (se crearon `oleoducto` #2890 y `gasoducto` #2891), así que su `taxonomy_state_fingerprint`
 * ya no coincide con el estado actual y `apply()` las rechazaría. Pero `apply()` registra esa
 * obsolescencia con `abort()`, que es TERMINAL, y re-congelar está bloqueado por el índice único
 * parcial `WHERE status = 'PENDING_APPLY'`. Es decir: hasta esta migración no existía NINGUNA forma de
 * volver a pedir la decisión humana sin destruir la anterior.
 *
 * ESTRICTAMENTE ADITIVA. Solo `ADD COLUMN ... NULL`, `ADD CONSTRAINT`, `CREATE INDEX` y un
 * `CREATE OR REPLACE` de la función del trigger que CONSERVA sus cuatro reglas anteriores y les suma
 * dos. Cero `DROP`, cero `ALTER TYPE`, cero columnas obligatorias nuevas, y **la migración no toca una
 * sola fila**: no hay backfill. Las 12 propuestas existentes quedan exactamente como estaban, con
 * todas las columnas nuevas en NULL.
 *
 * POR QUÉ NO HIZO FALTA TOCAR EL CHECK DE `status`: la tabla original declara
 * `status VARCHAR(30) NOT NULL DEFAULT 'PENDING_APPLY'` **sin** CHECK de valores permitidos (ver
 * `create_taxonomy_reviewed_proposals_table`), así que agregar `SUPERSEDED` (10 caracteres) no exige
 * modificar ninguna restricción existente. Y como los dos índices únicos parciales filtran por
 * `status = 'PENDING_APPLY'`, pasar una propuesta a `SUPERSEDED` **libera automáticamente** el slot
 * único de su candidato: eso es precisamente lo que devuelve al candidato a la cola de revisión normal,
 * sin debilitar el índice ni tocarlo.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        $c = DB::connection('pgsql');

        // ---------------------------------------------------------------------------------------
        // Rastro de supersesión + linaje bidireccional + delta de estado durable.
        //
        // `supersession_state_delta` NO es opcional en el diseño aceptado, y la razón es concreta: el
        // `taxonomy_state_fingerprint` es un HASH, así que el delta exacto no se puede reconstruir
        // desde él más tarde. Si no se persiste en el instante de la transición, la evidencia de POR
        // QUÉ la revisión quedó obsoleta se pierde para siempre.
        // ---------------------------------------------------------------------------------------
        $c->statement(<<<'SQL'
            ALTER TABLE taxonomy_reviewed_proposals
                ADD COLUMN superseded_at TIMESTAMP NULL,
                ADD COLUMN superseded_by_proposal_id BIGINT NULL REFERENCES taxonomy_reviewed_proposals(id) ON DELETE SET NULL,
                ADD COLUMN supersedes_proposal_id BIGINT NULL REFERENCES taxonomy_reviewed_proposals(id) ON DELETE SET NULL,
                ADD COLUMN inherited_decision_from_id BIGINT NULL REFERENCES taxonomy_reviewed_proposals(id) ON DELETE SET NULL,
                ADD COLUMN supersession_reference VARCHAR(255) NULL,
                ADD COLUMN supersession_reason TEXT NULL,
                ADD COLUMN supersession_actor_type VARCHAR(32) NULL,
                ADD COLUMN supersession_by_id BIGINT NULL,
                ADD COLUMN supersession_channel VARCHAR(20) NULL,
                ADD COLUMN supersession_state_delta JSONB NULL
        SQL);

        // Una supersesión es todo-o-nada, mismo criterio que los CHECK de confirmación/anulación de
        // TASK-0006B/0006C. `supersession_by_id` queda FUERA a propósito y por la misma lección de
        // TASK-0006C: cuando la corrección la ejecuta el agente bajo autorización del dueño, ahí va
        // NULL — poner la cuenta de una persona que no ejecutó la acción repetiría exactamente el
        // error de procedencia que TASK-0006C vino a reparar. El actor queda registrado con la verdad
        // en `supersession_actor_type` y la autorización vive en `supersession_reference`.
        $c->statement(<<<'SQL'
            ALTER TABLE taxonomy_reviewed_proposals
                ADD CONSTRAINT taxonomy_reviewed_proposals_supersession_complete CHECK (
                    (superseded_at IS NULL
                        AND supersession_reference IS NULL
                        AND supersession_reason IS NULL
                        AND supersession_actor_type IS NULL
                        AND supersession_channel IS NULL
                        AND supersession_state_delta IS NULL)
                    OR (superseded_at IS NOT NULL
                        AND supersession_reference IS NOT NULL
                        AND supersession_reason IS NOT NULL
                        AND supersession_actor_type IS NOT NULL
                        AND supersession_channel IS NOT NULL
                        AND supersession_state_delta IS NOT NULL)
                )
        SQL);

        // La regla de integridad que hace que una confirmación de sucesor NO pueda ser ceremonial: si
        // existe un sucesor, el delta de estado tiene que estar persistido. Es la contraparte en base
        // de datos del requisito del diseño («si hay sucesor, tiene que haber diff»), y no se puede
        // sortear desde la aplicación.
        $c->statement(<<<'SQL'
            ALTER TABLE taxonomy_reviewed_proposals
                ADD CONSTRAINT taxonomy_reviewed_proposals_successor_needs_delta CHECK (
                    superseded_by_proposal_id IS NULL OR supersession_state_delta IS NOT NULL
                )
        SQL);

        // Una propuesta no puede sucederse a sí misma ni declararse heredera de sí misma.
        $c->statement(<<<'SQL'
            ALTER TABLE taxonomy_reviewed_proposals
                ADD CONSTRAINT taxonomy_reviewed_proposals_supersession_not_self CHECK (
                    (superseded_by_proposal_id IS NULL OR superseded_by_proposal_id <> id)
                    AND (supersedes_proposal_id IS NULL OR supersedes_proposal_id <> id)
                    AND (inherited_decision_from_id IS NULL OR inherited_decision_from_id <> id)
                )
        SQL);

        $c->statement('CREATE INDEX taxonomy_reviewed_proposals_superseded_idx ON taxonomy_reviewed_proposals (id) WHERE superseded_at IS NOT NULL');
        $c->statement('CREATE INDEX taxonomy_reviewed_proposals_supersedes_idx ON taxonomy_reviewed_proposals (supersedes_proposal_id) WHERE supersedes_proposal_id IS NOT NULL');

        $this->replaceConfirmationGuardTrigger($c);
    }

    /**
     * `CREATE OR REPLACE` de la función del trigger creado en TASK-0006B. Las CUATRO reglas previas
     * (la excepción de anulación de TASK-0006C y las tres prohibiciones) quedan **idénticas**, y se les
     * suman dos reglas nuevas de ciclo de vida:
     *
     * 5. Un rastro de supersesión ya grabado es inmutable — mismo criterio que ya protege al rastro de
     *    anulación. Sin esto, la evidencia de por qué una revisión quedó obsoleta se podría reescribir
     *    después, que es justo lo que el delta durable viene a evitar.
     * 6. `SUPERSEDED` es TERMINAL: no se «des-supersede». Mismo criterio que `ABORTED`. Esto importa
     *    de verdad, no es ceremonial: devolver una propuesta superseded a `PENDING_APPLY` podría
     *    colisionar con la propuesta nueva que el humano ya congeló para ese candidato, y el índice
     *    único parcial recién lo detectaría en el momento del UPDATE. Mejor prohibirlo explícitamente.
     *
     * El alcance del trigger sigue NO cubriendo `decision`/`decision_payload`/`payload_fingerprint` en
     * el camino normal — deliberado desde TASK-0006B, porque los tests aprobados de tamper-detection de
     * TASK-0004 los modifican a propósito para probar que `apply()` lo detecta, y guardarlos acá
     * volvería inalcanzable un guard aprobado.
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
                -- Reglas originales de TASK-0006B/0006C, intactas.
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

                -- ================================================================================
                -- TASK-0006E: dos reglas nuevas de ciclo de vida de supersesion.
                -- ================================================================================

                -- 5. El rastro de una supersesion ya grabada es inmutable - mismo criterio que el
                --    rastro de anulacion. El delta de estado existe para explicar POR QUE la revision
                --    quedo obsoleta; si se pudiera reescribir despues, no probaria nada.
                IF OLD.superseded_at IS NOT NULL AND (
                    NEW.superseded_at IS DISTINCT FROM OLD.superseded_at
                    OR NEW.supersession_reference IS DISTINCT FROM OLD.supersession_reference
                    OR NEW.supersession_reason IS DISTINCT FROM OLD.supersession_reason
                    OR NEW.supersession_actor_type IS DISTINCT FROM OLD.supersession_actor_type
                    OR NEW.supersession_channel IS DISTINCT FROM OLD.supersession_channel
                    OR NEW.supersession_state_delta::text IS DISTINCT FROM OLD.supersession_state_delta::text
                ) THEN
                    RAISE EXCEPTION 'taxonomy_reviewed_proposals: el rastro de una supersesion ya grabada es inmutable (propuesta %).', OLD.id;
                END IF;

                -- 6. SUPERSEDED es TERMINAL: no se des-supersede. Devolver una propuesta superseded a
                --    PENDING_APPLY podria colisionar con la propuesta NUEVA que el humano ya congelo
                --    para ese mismo candidato, y el indice unico parcial recien lo detectaria en el
                --    UPDATE. Mismo criterio que ABORTED.
                IF OLD.status = 'SUPERSEDED' AND NEW.status IS DISTINCT FROM 'SUPERSEDED' THEN
                    RAISE EXCEPTION 'taxonomy_reviewed_proposals: SUPERSEDED es terminal (propuesta %). Una propuesta superseded no vuelve a PENDING_APPLY ni pasa a APPLIED/ABORTED - el candidato se revisa de nuevo congelando una propuesta NUEVA.', OLD.id;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);
    }

    public function down(): void
    {
        $c = DB::connection('pgsql');

        // Restaura la función del trigger a su forma de TASK-0006C (sin las dos reglas de supersesión).
        $c->statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION taxonomy_reviewed_proposals_guard_confirmation()
            RETURNS trigger AS $$
            BEGIN
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
                        RAISE EXCEPTION 'taxonomy_reviewed_proposals: la anulacion de una confirmacion (propuesta %) no puede modificar la decision, sus fingerprints, su procedencia de revision ni su origen.', OLD.id;
                    END IF;

                    RETURN NEW;
                END IF;

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

        $c->statement('DROP INDEX IF EXISTS taxonomy_reviewed_proposals_supersedes_idx');
        $c->statement('DROP INDEX IF EXISTS taxonomy_reviewed_proposals_superseded_idx');

        foreach ([
            'taxonomy_reviewed_proposals_supersession_not_self',
            'taxonomy_reviewed_proposals_successor_needs_delta',
            'taxonomy_reviewed_proposals_supersession_complete',
        ] as $constraint) {
            $c->statement("ALTER TABLE taxonomy_reviewed_proposals DROP CONSTRAINT IF EXISTS {$constraint}");
        }

        $c->statement(<<<'SQL'
            ALTER TABLE taxonomy_reviewed_proposals
                DROP COLUMN IF EXISTS supersession_state_delta,
                DROP COLUMN IF EXISTS supersession_channel,
                DROP COLUMN IF EXISTS supersession_by_id,
                DROP COLUMN IF EXISTS supersession_actor_type,
                DROP COLUMN IF EXISTS supersession_reason,
                DROP COLUMN IF EXISTS supersession_reference,
                DROP COLUMN IF EXISTS inherited_decision_from_id,
                DROP COLUMN IF EXISTS supersedes_proposal_id,
                DROP COLUMN IF EXISTS superseded_by_proposal_id,
                DROP COLUMN IF EXISTS superseded_at
        SQL);
    }
};
