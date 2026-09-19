<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 (sección 7): cola de revisión del Canonical Concept Builder - MISMO patrón/convenciones
 * que `taxonomy_candidate_terms` (TAXV2-12: crawl_run_id/source_id -> status -> reviewed_by/at ->
 * published_*), pero para una entidad distinta (propuestas TÉRMINO->CONCEPTO, no términos nuevos
 * del crawler) - por eso es una tabla propia, no una reutilización de `taxonomy_candidate_terms`.
 *
 * `suggested_concept_id` NULL representa `PROPOSE_NEW_CONCEPT` (sección 7 del pedido): el Builder
 * no encontró un concepto existente lo bastante fuerte y sugiere que el término merece un concepto
 * canónico propio - `suggested_new_concept_name` lleva el nombre propuesto en ese caso.
 *
 * `published_term_concept_id` es el mismo espejo de `taxonomy_candidate_terms.published_term_id`:
 * el único camino de esta cola hacia `taxonomy_term_concepts` es la acción "Publicar/Aprobar" del
 * admin (o un futuro `--apply`), nunca una escritura automática del Builder en este delivery.
 *
 * Esta tabla queda en 0 filas al final de esta entrega (el dry-run no persiste candidatos - sección
 * 15 del pedido: "Preferiblemente tampoco debe persistir candidate links durante el dry-run").
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE taxonomy_candidate_concept_links (
                id BIGSERIAL PRIMARY KEY,
                suggested_term_id BIGINT NOT NULL REFERENCES taxonomy_terms(id) ON DELETE CASCADE,
                suggested_concept_id BIGINT NULL REFERENCES taxonomy_canonical_concepts(id) ON DELETE CASCADE,
                suggested_new_concept_name VARCHAR(255) NULL,
                signals JSONB NOT NULL DEFAULT '{}',
                confidence NUMERIC(5, 4) NOT NULL DEFAULT 0,
                tier VARCHAR(30) NOT NULL DEFAULT 'REVIEW',
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                review_notes TEXT NULL,
                reviewed_by BIGINT NULL,
                reviewed_at TIMESTAMP NULL,
                published_term_concept_id BIGINT NULL REFERENCES taxonomy_term_concepts(id) ON DELETE SET NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            )
        SQL);

        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_candidate_concept_links_status_idx ON taxonomy_candidate_concept_links (status)'
        );
        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_candidate_concept_links_tier_idx ON taxonomy_candidate_concept_links (tier)'
        );
        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_candidate_concept_links_term_idx ON taxonomy_candidate_concept_links (suggested_term_id)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS taxonomy_candidate_concept_links');
    }
};
