<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TAXV2-13 (columnas de evidencia tomadas literalmente de la sección 6 del documento de
 * instrucciones: `company_id, page_url, taxonomy_term_id, matched_text, canonical_term,
 * match_type, context, cpv_code, relation_weight, evidence_score, crawled_at`).
 *
 * Es evidencia para explicabilidad (sección 8), NO una asignación automática: ninguna fila de acá
 * mueve por sí sola `empresa_taxonomy_category` (mismo principio de "nada nuevo entra sin revisión"
 * ya aplicado a `taxonomy_candidate_terms` en TAXV2-12, extendido acá al matching de empresas) -
 * `status` deja ver qué evidencia un admin ya confirmó como relevante desde el panel, sin construir
 * un segundo workflow de aprobación paralelo al de TAXV2-6.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE company_term_matches (
                id BIGSERIAL PRIMARY KEY,
                page_id BIGINT NOT NULL REFERENCES company_pages(id) ON DELETE CASCADE,
                empresa_id BIGINT NOT NULL,
                taxonomy_term_id BIGINT NOT NULL REFERENCES taxonomy_terms(id) ON DELETE CASCADE,
                matched_text VARCHAR(255) NOT NULL,
                canonical_term VARCHAR(255) NOT NULL,
                match_type VARCHAR(30) NOT NULL,
                context TEXT NULL,
                cpv_code VARCHAR(50) NULL,
                relation_weight NUMERIC(5, 4) NULL,
                evidence_score NUMERIC(6, 4) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending_review',
                crawled_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                UNIQUE (page_id, taxonomy_term_id)
            )
        SQL);

        DB::connection('pgsql')->statement(
            'CREATE INDEX company_term_matches_empresa_id_idx ON company_term_matches (empresa_id)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS company_term_matches');
    }
};
