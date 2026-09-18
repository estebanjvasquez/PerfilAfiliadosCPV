<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TAXV3-2: relación N:M explícita entre `taxonomy_terms` y `taxonomy_canonical_concepts` (pedido
 * así, no como una columna `canonical_concept_id` directa en `taxonomy_terms`, para no cerrar la
 * puerta a que un término pertenezca a más de un concepto en el futuro).
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE taxonomy_term_concepts (
                id BIGSERIAL PRIMARY KEY,
                term_id BIGINT NOT NULL REFERENCES taxonomy_terms(id) ON DELETE CASCADE,
                concept_id BIGINT NOT NULL REFERENCES taxonomy_canonical_concepts(id) ON DELETE CASCADE,
                created_at TIMESTAMP NULL,
                UNIQUE (term_id, concept_id)
            )
        SQL);

        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_term_concepts_term_id_idx ON taxonomy_term_concepts (term_id)'
        );
        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_term_concepts_concept_id_idx ON taxonomy_term_concepts (concept_id)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS taxonomy_term_concepts');
    }
};
