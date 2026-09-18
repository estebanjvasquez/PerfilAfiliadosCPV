<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TAXV2-12 (sección 5 del documento de instrucciones, tabla de staging descrita ahí): términos que
 * el crawler encuentra en una fuente pero que TODAVÍA no son parte del diccionario real. Ninguna
 * fila de acá llega a `taxonomy_terms` sin pasar por el workflow
 * `candidate -> needs_review -> approved/rejected -> published` (regla 8 de la sección 5:
 * "ningún término nuevo entra directamente en producción") - la acción "Publicar" del
 * `TaxonomyCandidateTermResource` (TAXV2-12) es el único camino hacia `taxonomy_terms`, y crea ahí
 * sus relaciones CPV en `needs_review` (nunca `approved` automático) para que pasen otra vez por la
 * cola de revisión ya existente de TAXV2-6.
 *
 * `change_status` implementa la "detección de cambios" de la sección 5 (`NEW/UNCHANGED/CHANGED/
 * MISSING`) vía `content_hash` + `first_seen_at`/`last_seen_at` - un término que deja de aparecer en
 * una corrida no se borra (la sección 5 lo prohíbe explícitamente), solo queda con `last_seen_at`
 * viejo para que el dashboard lo pueda señalar como `MISSING`.
 *
 * Único por (`source_id`, LOWER(`term`)) vía índice de expresión (no se puede declarar `UNIQUE` de
 * expresión inline en `CREATE TABLE`) - así una misma fuente nunca duplica el mismo término entre
 * corridas; distintas fuentes SÍ pueden proponer el mismo término de forma independiente.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE taxonomy_candidate_terms (
                id BIGSERIAL PRIMARY KEY,
                crawl_run_id BIGINT NULL REFERENCES taxonomy_crawl_runs(id) ON DELETE SET NULL,
                source_id VARCHAR(50) NOT NULL REFERENCES taxonomy_sources(source_id) ON DELETE CASCADE,
                term VARCHAR(255) NOT NULL,
                language VARCHAR(10) NOT NULL DEFAULT 'en',
                source_url VARCHAR(500) NOT NULL,
                source_category VARCHAR(255) NULL,
                context_snippet TEXT NULL,
                content_hash VARCHAR(64) NOT NULL,
                change_status VARCHAR(20) NOT NULL DEFAULT 'new',
                suggested_canonical_term VARCHAR(255) NULL,
                suggested_aliases TEXT[] NOT NULL DEFAULT '{}',
                suggested_cpv_relations JSONB NULL,
                confidence NUMERIC(5, 4) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'candidate',
                review_notes TEXT NULL,
                reviewed_by BIGINT NULL,
                reviewed_at TIMESTAMP NULL,
                published_term_id BIGINT NULL REFERENCES taxonomy_terms(id) ON DELETE SET NULL,
                first_seen_at TIMESTAMP NOT NULL,
                last_seen_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            )
        SQL);

        DB::connection('pgsql')->statement(
            'CREATE UNIQUE INDEX taxonomy_candidate_terms_source_term_uidx ON taxonomy_candidate_terms (source_id, LOWER(term))'
        );
        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_candidate_terms_status_idx ON taxonomy_candidate_terms (status)'
        );
        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_candidate_terms_change_status_idx ON taxonomy_candidate_terms (change_status)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS taxonomy_candidate_terms');
    }
};
