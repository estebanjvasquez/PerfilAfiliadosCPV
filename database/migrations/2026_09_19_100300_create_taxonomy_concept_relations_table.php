<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 (sección 5): relaciones semánticas TIPADAS entre CONCEPTOS canónicos (no entre términos -
 * eso sigue siendo exclusivamente `taxonomy_term_concepts`, con su semántica de IDENTIDAD/EXPRESIÓN
 * intacta, sección 2 del pedido).
 *
 * Esta tabla queda vacía (0 filas) al final de esta entrega - `--apply` no está habilitado para
 * poblarla todavía (sección 16/17 del pedido). Existe como infraestructura para que el Builder (y
 * un admin, a mano) puedan proponer/registrar relaciones más adelante, sin requerir otra migración.
 *
 * `relation_type` no lleva FK físico a `taxonomy_concept_relation_types.code` (mismo criterio que el
 * resto del vocabulario gobernado de este proyecto) - se valida a nivel de aplicación en
 * `CanonicalConceptBuilderService`.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE taxonomy_concept_relations (
                id BIGSERIAL PRIMARY KEY,
                source_concept_id BIGINT NOT NULL REFERENCES taxonomy_canonical_concepts(id) ON DELETE CASCADE,
                target_concept_id BIGINT NOT NULL REFERENCES taxonomy_canonical_concepts(id) ON DELETE CASCADE,
                relation_type VARCHAR(50) NOT NULL,
                weight NUMERIC(5, 4) NOT NULL DEFAULT 0,
                confidence NUMERIC(5, 4) NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'candidate',
                provenance JSONB NULL,
                reviewed_by BIGINT NULL,
                reviewed_at TIMESTAMP NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                CONSTRAINT taxonomy_concept_relations_no_self_relation CHECK (source_concept_id != target_concept_id),
                UNIQUE (source_concept_id, target_concept_id, relation_type)
            )
        SQL);

        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_concept_relations_source_idx ON taxonomy_concept_relations (source_concept_id)'
        );
        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_concept_relations_target_idx ON taxonomy_concept_relations (target_concept_id)'
        );
        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_concept_relations_status_idx ON taxonomy_concept_relations (status)'
        );
        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_concept_relations_relation_type_idx ON taxonomy_concept_relations (relation_type)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS taxonomy_concept_relations');
    }
};
