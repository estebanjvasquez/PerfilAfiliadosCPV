<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 (sección 4): vocabulario de `concept_type` GOBERNADO POR TABLA, no por DB ENUM ni por un
 * `match()`/array disperso en el código - añadir un tipo nuevo (ej. un futuro "REGULATION") es
 * una fila nueva, no una migración. `taxonomy_canonical_concepts.concept_type` NO lleva FK físico a
 * esta tabla a propósito: permite desactivar (`active=false`) un tipo sin romper conceptos ya
 * clasificados con él, y el mismo criterio ya se usa en este proyecto para `taxonomy_terms.term_type`
 * (VARCHAR libre, sin FK, validado a nivel de aplicación).
 *
 * Sembrado (idempotente, insertOrIgnore) por `taxonomy:seed-concept-vocabulary` - no en el up() de
 * esta migración, mismo patrón que `taxonomy_sources`/`taxonomy:seed-sources`.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE taxonomy_concept_types (
                id BIGSERIAL PRIMARY KEY,
                code VARCHAR(50) NOT NULL,
                label_es VARCHAR(255) NOT NULL,
                label_en VARCHAR(255) NOT NULL,
                sort_order SMALLINT NOT NULL DEFAULT 0,
                active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                UNIQUE (code)
            )
        SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS taxonomy_concept_types');
    }
};
