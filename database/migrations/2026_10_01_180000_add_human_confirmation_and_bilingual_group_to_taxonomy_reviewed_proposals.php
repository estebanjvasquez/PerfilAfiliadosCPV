<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-0006B (Issue #2 comentario `5936206843`), secciones A y D: las DOS extensiones mínimas que
 * el dueño de la taxonomía necesita para que sus decisiones humanas sean representables dentro de
 * C2 sin debilitar inmutabilidad, atribución, idempotencia ni la separación REVIEW/FREEZE/CONFIRM
 * vs APPLY.
 *
 * ESTRICTAMENTE ADITIVA: solo `ADD COLUMN` nulables (más un booleano con DEFAULT FALSE), índices y
 * un trigger nuevo. Cero `DROP`, cero `ALTER TYPE`, cero reescritura de filas existentes salvo el
 * backfill determinístico y acotado descrito abajo. Ninguna columna existente cambia de tipo,
 * nulabilidad ni contenido.
 *
 * ---------------------------------------------------------------------------------------------
 * A) CAPA DE CONFIRMACIÓN HUMANA — por qué hacía falta una columna nueva
 * ---------------------------------------------------------------------------------------------
 * El re-audit `5934324928` (BLOQUEO 1) dictaminó que las propuestas #492–#495 no son decisiones
 * humanas: fueron preparadas por el agente y congeladas bajo la cuenta #3, así que `reviewer_id=3`
 * decía estructuralmente una cosa y la nota en `context_reason` otra. Una auditoría de gobernanza
 * no puede apoyarse en atribución contradictoria. El hueco reportado en la sección 10.3 de
 * `audit/phase6_task0006_queue_review_2026-10-01.md` era que C2 NO tenía dónde registrar la
 * confirmación humana de una propuesta ya congelada:
 *
 * - un solo par de identidad de revisión (`reviewer_id`/`reviewed_at`), sin segundo actor;
 * - estados solo `PENDING_APPLY`/`APPLIED`/`ABORTED`, sin `CONFIRMED`;
 * - `abort()` privado y alcanzable solo desde la ruta de `apply()`;
 * - el índice único parcial `one_pending_per_candidate` impide por BASE DE DATOS congelar una
 *   segunda propuesta activa para el mismo candidato, así que "que el humano vuelva a congelar"
 *   exigía borrar/abortar las filas existentes - prohibido sin autorización de limpieza separada.
 *
 * El dueño eligió la opción A de ese reporte: capa aditiva con operación `confirm()` dedicada.
 *
 * INVARIANTE CENTRAL: la confirmación NO toca ningún campo de decisión. `reviewer_id`,
 * `reviewed_at`, `decision`, `decision_payload`, `payload_version`, `payload_fingerprint` y
 * `taxonomy_state_fingerprint` quedan exactamente como estaban. Por eso `payload_fingerprint`
 * SIGUE SIENDO VÁLIDO después de confirmar: `computePayloadFingerprint()` se calcula sobre una
 * lista FIJA de 9 campos de decisión (ver `ReviewedProposalService::apply()`), y ninguna columna
 * agregada acá entra en esa lista. Confirmar una propuesta no puede invalidar su tamper-detection -
 * verificado por test, no por suposición.
 *
 * `requires_human_confirmation` es el único predicado que `apply()` consulta. Es `FALSE` por
 * DEFAULT, y eso ES la regla de compatibilidad con lo histórico: toda propuesta congelada antes de
 * esta migración queda en `FALSE` y sigue siendo aplicable exactamente como antes. Las propuestas
 * legítimas revisadas por humanos (#420/#421/#422/#491) NO se vuelven inválidas - requisito
 * explícito de la sección A del comentario ("Do not make every historical legitimate human-frozen
 * proposal suddenly invalid if its original review provenance already satisfies the human-review
 * requirement").
 *
 * BACKFILL DETERMINÍSTICO Y ACOTADO (#492–#495): se marcan como pendientes de confirmación
 * EXACTAMENTE las filas que satisfacen las TRES condiciones a la vez:
 *   1. `candidate_link_id IN (266, 267, 268, 269)` - los ids que el comentario nombra;
 *   2. `decision_payload->>'context_reason'` CONTIENE el marcador de atribución del agente - la
 *      evidencia real de que la decisión la preparó el agente, no un id hardcodeado;
 *   3. `status = 'PENDING_APPLY'` - nunca se toca una propuesta ya aplicada o abortada.
 *
 * La condición 2 hace el backfill AUTO-VALIDANTE: si el texto no está, la fila no se toca, así que
 * el backfill no puede marcar por error una decisión humana. Verificado en vivo antes de escribir
 * la migración: el marcador está presente en #492–#495 y AUSENTE en #420/#421/#422/#491. El
 * backfill escribe SOLO `requires_human_confirmation`/`prepared_by_actor_type`/`prepared_via` -
 * jamás `decision_payload` ni ningún fingerprint, así que la decisión en sí queda intacta.
 *
 * PROTECCIÓN CONTRA MUTACIÓN CASUAL (pedida explícitamente por la sección A): el trigger
 * `taxonomy_reviewed_proposals_guard_confirmation` rechaza a nivel de BASE DE DATOS (1) apagar
 * `requires_human_confirmation` una vez encendido y (2) sobrescribir una confirmación ya grabada.
 * Deliberadamente NO guarda las columnas de decisión: los tests aprobados de tamper-detection de
 * TASK-0004 modifican `decision_payload`/`payload_fingerprint` a propósito para probar que
 * `apply()` lo detecta, y un trigger sobre esas columnas volvería ese guard aprobado inalcanzable.
 * El alcance del trigger es exactamente el que el comentario pidió proteger, ni más ni menos.
 *
 * ---------------------------------------------------------------------------------------------
 * D) GRUPO BILINGÜE — por qué `proposal_group_id` y no una tabla nueva
 * ---------------------------------------------------------------------------------------------
 * Los candidatos 270 (`refinery`, en) y 271 (`refinería`, es, `translation_alias` de `refinery`)
 * son UN concepto bilingüe. El dueño decidió ES=`refinería` / EN=`refinery`, un solo concepto.
 *
 * La restricción dura: `candidate_link_id` es una sola columna con un CHECK XOR y un índice único
 * parcial `WHERE status = 'PENDING_APPLY'`. Si una única fila de propuesta representara el par, el
 * candidato 271 quedaría FUERA de ese índice y nada impediría que alguien congelara una segunda
 * propuesta para él - exactamente la carrera de concepto duplicado que la sección D prohíbe.
 *
 * Por eso el grupo se modela como DOS filas de propuesta (una por candidato) unidas por
 * `proposal_group_id`, en vez de una fila con una lista en el payload o una tabla miembro nueva:
 *
 * - el índice único parcial YA EXISTENTE protege AUTOMÁTICAMENTE a los dos candidatos, sin índice
 *   nuevo y sin sincronizar un `status` duplicado en otra tabla (una tabla miembro necesitaría
 *   replicar el `status` del padre para tener un índice parcial equivalente, y eso puede driftear);
 * - el CHECK XOR se cumple sin tocarlo (cada fila sigue teniendo exactamente un origen);
 * - cada fila conserva su propio `payload_fingerprint` y `taxonomy_state_fingerprint`, así que la
 *   detección de tamper y de obsolescencia sigue siendo por fila, sin casos especiales;
 * - el drift de fuente se revalida por miembro, y `apply()` aborta si CUALQUIER miembro drifteó;
 * - `apply()` (en una tarea futura y separadamente autorizada) bloquea todo el grupo, crea UN solo
 *   concepto y adjunta las dos identidades de término en UNA transacción; un segundo `apply()` de
 *   un hermano ve el grupo ya aplicado y reutiliza el concepto en vez de crear otro.
 *
 * Esto NO es un rediseño N:M: `taxonomy_term_concepts` sigue recibiendo una fila por término, la
 * cardinalidad TÉRMINO→CONCEPTO sigue siendo 1 concepto por término, y ningún consumidor de
 * búsqueda cambia. El comentario pedía parar si hiciera falta cambiar semántica de búsqueda o
 * cardinalidad - no hizo falta.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    /**
     * Marcador de atribución que las 4 propuestas preparadas por el agente llevan dentro de su
     * propio `context_reason` (escrito en la ronda anterior de TASK-0006, justamente para que el
     * registro durable fuera autodescriptivo). Usarlo como predicado del backfill es lo que hace
     * que el backfill sea evidencia-dirigido en vez de id-dirigido.
     */
    private const AGENT_ATTRIBUTION_MARKER = 'preparada por el agente Claude Code';

    private const AGENT_PREPARED_CANDIDATE_IDS = [266, 267, 268, 269];

    public function up(): void
    {
        $c = DB::connection('pgsql');

        $c->statement(<<<'SQL'
            ALTER TABLE taxonomy_reviewed_proposals
                ADD COLUMN requires_human_confirmation BOOLEAN NOT NULL DEFAULT FALSE,
                ADD COLUMN prepared_by_actor_type VARCHAR(30) NULL,
                ADD COLUMN prepared_via VARCHAR(20) NULL,
                ADD COLUMN confirmed_by_id BIGINT NULL,
                ADD COLUMN confirmed_at TIMESTAMP NULL,
                ADD COLUMN confirmation_reference VARCHAR(255) NULL,
                ADD COLUMN confirmation_channel VARCHAR(20) NULL,
                ADD COLUMN confirmation_note TEXT NULL,
                ADD COLUMN proposal_group_id UUID NULL
        SQL);

        // Una confirmación es todo-o-nada: no puede existir media confirmación (fecha sin actor,
        // actor sin referencia de gobernanza, etc.). DB-enforced, no solo por convención del
        // servicio - mismo criterio que el CHECK XOR de la migración original.
        $c->statement(<<<'SQL'
            ALTER TABLE taxonomy_reviewed_proposals
                ADD CONSTRAINT taxonomy_reviewed_proposals_confirmation_complete CHECK (
                    (confirmed_at IS NULL AND confirmed_by_id IS NULL AND confirmation_reference IS NULL AND confirmation_channel IS NULL)
                    OR (confirmed_at IS NOT NULL AND confirmed_by_id IS NOT NULL AND confirmation_reference IS NOT NULL AND confirmation_channel IS NOT NULL)
                )
        SQL);

        // Los miembros de un grupo bilingüe se buscan siempre por group_id (apply() bloquea el
        // grupo completo), y la UI de confirmación lista exactamente las propuestas pendientes de
        // confirmar - un índice parcial para esa cola, que es chica por definición.
        $c->statement('CREATE INDEX taxonomy_reviewed_proposals_group_idx ON taxonomy_reviewed_proposals (proposal_group_id) WHERE proposal_group_id IS NOT NULL');
        $c->statement('CREATE INDEX taxonomy_reviewed_proposals_awaiting_confirmation_idx ON taxonomy_reviewed_proposals (id) WHERE requires_human_confirmation = TRUE AND confirmed_at IS NULL');

        $this->createConfirmationGuardTrigger($c);

        $this->backfillAgentPreparedProposals($c);
    }

    /**
     * Protege las columnas NUEVAS de mutación casual (sección A del comentario). Alcance
     * deliberadamente acotado: NO guarda `decision`/`decision_payload`/`payload_fingerprint`,
     * porque los tests aprobados de TASK-0004 los modifican a propósito para probar que `apply()`
     * detecta el tamper - guardarlos acá dejaría ese guard aprobado inalcanzable y convertiría un
     * test de seguridad en un error de base de datos.
     */
    private function createConfirmationGuardTrigger($c): void
    {
        $c->statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION taxonomy_reviewed_proposals_guard_confirmation()
            RETURNS trigger AS $$
            BEGIN
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

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        $c->statement(<<<'SQL'
            CREATE TRIGGER taxonomy_reviewed_proposals_guard_confirmation_trg
                BEFORE UPDATE ON taxonomy_reviewed_proposals
                FOR EACH ROW EXECUTE FUNCTION taxonomy_reviewed_proposals_guard_confirmation()
        SQL);
    }

    /**
     * Marca como pendientes de confirmación humana EXACTAMENTE las propuestas preparadas por el
     * agente. Determinístico (tres predicados conjuntos, sin heurística), auditable (deja su propia
     * fila en `taxonomy_audit_log` por cada propuesta marcada) y limitado a identificar el requisito
     * de confirmación: NO toca `decision`, `decision_payload`, ni ningún fingerprint.
     */
    private function backfillAgentPreparedProposals($c): void
    {
        $marker = '%'.self::AGENT_ATTRIBUTION_MARKER.'%';

        $targets = $c->table('taxonomy_reviewed_proposals')
            ->whereIn('candidate_link_id', self::AGENT_PREPARED_CANDIDATE_IDS)
            ->where('status', 'PENDING_APPLY')
            ->whereRaw("decision_payload->>'context_reason' LIKE ?", [$marker])
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($targets === []) {
            // Entorno limpio / base sin esas filas (ej. una instalación nueva o un entorno de test):
            // la migración es válida igual. No se inventa ninguna fila.
            return;
        }

        $c->table('taxonomy_reviewed_proposals')
            ->whereIn('id', $targets)
            ->update([
                'requires_human_confirmation' => true,
                'prepared_by_actor_type' => 'agent',
                'prepared_via' => 'console',
                'updated_at' => now(),
            ]);

        foreach ($targets as $proposalId) {
            $c->table('taxonomy_audit_log')->insert([
                'user_id' => null,
                'entity_type' => 'App\Models\TaxonomyReviewedProposal',
                'entity_id' => $proposalId,
                'field' => 'requires_human_confirmation',
                'old_value' => 'false',
                'new_value' => 'true',
                'reason' => 'TASK-0006B (Issue #2 comentario 5936206843, seccion A): propuesta preparada por el agente marcada como pendiente de CONFIRMACION HUMANA. Backfill deterministico por candidate_link_id + marcador de atribucion presente en context_reason + status PENDING_APPLY. No se modifico la decision ni ningun fingerprint.',
                'actor_type' => 'system',
                // `taxonomy_audit_log.algorithm_version` es VARCHAR(50) - verificado contra el
                // esquema real, no asumido.
                'algorithm_version' => 'taxonomy-reviewed-proposal/c2-confirm-v1',
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $c = DB::connection('pgsql');

        $c->statement('DROP TRIGGER IF EXISTS taxonomy_reviewed_proposals_guard_confirmation_trg ON taxonomy_reviewed_proposals');
        $c->statement('DROP FUNCTION IF EXISTS taxonomy_reviewed_proposals_guard_confirmation()');
        $c->statement('DROP INDEX IF EXISTS taxonomy_reviewed_proposals_awaiting_confirmation_idx');
        $c->statement('DROP INDEX IF EXISTS taxonomy_reviewed_proposals_group_idx');
        $c->statement('ALTER TABLE taxonomy_reviewed_proposals DROP CONSTRAINT IF EXISTS taxonomy_reviewed_proposals_confirmation_complete');
        $c->statement(<<<'SQL'
            ALTER TABLE taxonomy_reviewed_proposals
                DROP COLUMN IF EXISTS requires_human_confirmation,
                DROP COLUMN IF EXISTS prepared_by_actor_type,
                DROP COLUMN IF EXISTS prepared_via,
                DROP COLUMN IF EXISTS confirmed_by_id,
                DROP COLUMN IF EXISTS confirmed_at,
                DROP COLUMN IF EXISTS confirmation_reference,
                DROP COLUMN IF EXISTS confirmation_channel,
                DROP COLUMN IF EXISTS confirmation_note,
                DROP COLUMN IF EXISTS proposal_group_id
        SQL);
    }
};
