<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-0004 (Phase C2, Issue #2 comentario `5886148283`): materializa el contrato central que
 * Phase C1 dejó explícitamente pendiente (ver docblock de `CanonicalConceptApplyService`):
 *
 *   REVIEWED_PROPOSAL -> PAYLOAD INMUTABLE Y CON FINGERPRINT -> APPLY(payload) -> VALIDATE ->
 *   COMMIT/ROLLBACK -> AUDIT
 *
 * Esta tabla es el "payload inmutable" del contrato. Cada fila es la CONGELACIÓN de UNA decisión
 * humana de revisión (sobre un candidato término->concepto de `taxonomy_candidate_concept_links`,
 * o sobre una relación concepto<->concepto de `taxonomy_concept_relations`) - nunca se sobre-
 * escribe (`ReviewedProposalService::apply()` solo hace transiciones de `status`, nunca UPDATE de
 * los campos de decisión). El fingerprint de tamper-detection (`payload_fingerprint`) existe
 * precisamente para poder demostrar que nadie editó los campos de decisión a mano después de
 * congelados.
 *
 * `reviewed_at`/`reviewer_id` (identidad y momento de la REVISIÓN) están separados a propósito de
 * `authorization_reference`/`target_environment`/`applied_at` (identidad y momento de la
 * EJECUCIÓN) - hallazgo explícito del orquestador en TASK-0004 ("distinguish review decision from
 * execution authorization"). "Reviewed" nunca implica "authorized to write" - son dos eventos
 * distintos, posiblemente de personas/momentos distintos.
 *
 * `candidate_link_id` XOR `concept_relation_id` (nunca ambos, nunca ninguno) según `proposal_type`
 * - forzado por un CHECK, no solo por convención de aplicación.
 *
 * Los dos índices únicos parciales (`status = 'PENDING_APPLY'`) son la salvaguarda DB-enforced
 * (no TOCTOU) contra congelar dos propuestas activas para el mismo candidato/relación a la vez -
 * mismo criterio que las migraciones de TASK-0003 (`insertOrIgnore` + índice único parcial en vez
 * de exists()+create()).
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE taxonomy_reviewed_proposals (
                id BIGSERIAL PRIMARY KEY,
                proposal_type VARCHAR(30) NOT NULL,
                candidate_link_id BIGINT NULL REFERENCES taxonomy_candidate_concept_links(id) ON DELETE CASCADE,
                concept_relation_id BIGINT NULL REFERENCES taxonomy_concept_relations(id) ON DELETE CASCADE,
                decision VARCHAR(30) NOT NULL,
                decision_payload JSONB NOT NULL DEFAULT '{}',
                payload_version VARCHAR(60) NOT NULL,
                taxonomy_state_fingerprint VARCHAR(64) NOT NULL,
                payload_fingerprint VARCHAR(64) NOT NULL,
                reviewer_id BIGINT NULL,
                reviewed_at TIMESTAMP NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'PENDING_APPLY',
                applied_at TIMESTAMP NULL,
                authorization_reference VARCHAR(255) NULL,
                target_environment VARCHAR(30) NULL,
                application_result JSONB NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                CONSTRAINT taxonomy_reviewed_proposals_target_xor CHECK (
                    (proposal_type = 'TERM_CONCEPT_LINK' AND candidate_link_id IS NOT NULL AND concept_relation_id IS NULL)
                    OR (proposal_type = 'CONCEPT_RELATION' AND concept_relation_id IS NOT NULL AND candidate_link_id IS NULL)
                )
            )
        SQL);

        DB::connection('pgsql')->statement(
            "CREATE UNIQUE INDEX taxonomy_reviewed_proposals_one_pending_per_candidate ".
            "ON taxonomy_reviewed_proposals (candidate_link_id) ".
            "WHERE status = 'PENDING_APPLY' AND candidate_link_id IS NOT NULL"
        );
        DB::connection('pgsql')->statement(
            "CREATE UNIQUE INDEX taxonomy_reviewed_proposals_one_pending_per_relation ".
            "ON taxonomy_reviewed_proposals (concept_relation_id) ".
            "WHERE status = 'PENDING_APPLY' AND concept_relation_id IS NOT NULL"
        );
        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_reviewed_proposals_status_idx ON taxonomy_reviewed_proposals (status)'
        );
        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_reviewed_proposals_candidate_idx ON taxonomy_reviewed_proposals (candidate_link_id)'
        );
        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_reviewed_proposals_relation_idx ON taxonomy_reviewed_proposals (concept_relation_id)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS taxonomy_reviewed_proposals');
    }
};
