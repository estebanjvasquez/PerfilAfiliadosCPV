<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 (sección 6): catálogo GOBERNADO de `relation_type` para `taxonomy_concept_relations` -
 * mismo criterio que `taxonomy_concept_types` (tabla, no strings mágicos repartidos por el código,
 * ni DB ENUM). `source_concept_types`/`target_concept_types` son `TEXT[]` (NULL = cualquier
 * concept_type es válido en ese extremo) para poder restringir, por ejemplo, que una relación
 * `USED_FOR` solo tenga sentido de CAPABILITY/SERVICE hacia EQUIPMENT, sin volver eso una regla de
 * código.
 *
 * `inverse_relation_code` es un auto-puntero por `code` (no FK físico, ver mismo razonamiento que
 * `taxonomy_concept_types`) para pares direccionales (ej. PART_OF / HAS_PART).
 *
 * IMPORTANTE (sección 6 del pedido): esta tabla es pura gobernanza de VOCABULARIO. No implica que
 * MAINTENANCE/MANUFACTURING/RENTAL deban existir acá como relation_type - esos son casi siempre
 * QUERY INTENTS aplicados a un SUBJECT (ver `IntentContaminationDetector`), no relaciones
 * concepto-a-concepto. El seeder (`taxonomy:seed-concept-vocabulary`) solo siembra relaciones
 * genéricas de identidad/composición/general (RELATED_TO, PART_OF, HAS_PART, SUPERSEDES), nunca
 * verbos de intención de negocio.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE taxonomy_concept_relation_types (
                id BIGSERIAL PRIMARY KEY,
                code VARCHAR(50) NOT NULL,
                label_es VARCHAR(255) NOT NULL,
                label_en VARCHAR(255) NOT NULL,
                source_concept_types TEXT[] NULL,
                target_concept_types TEXT[] NULL,
                directional BOOLEAN NOT NULL DEFAULT TRUE,
                inverse_relation_code VARCHAR(50) NULL,
                default_weight NUMERIC(5, 4) NOT NULL DEFAULT 0.5,
                max_depth SMALLINT NULL,
                active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                UNIQUE (code)
            )
        SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS taxonomy_concept_relation_types');
    }
};
