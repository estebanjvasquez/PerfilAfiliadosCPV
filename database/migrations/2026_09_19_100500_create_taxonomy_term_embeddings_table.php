<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 (sección 9): almacenamiento REUSABLE de embeddings por término - hoy no existe ninguna
 * tabla de embeddings a nivel de `taxonomy_terms` (solo `taxonomy_category_embeddings`, Fase MCP-1).
 * Sin esto, cualquier retrieval semántico del Builder tendría que llamar al modelo de embeddings en
 * cada corrida para cada término - exactamente lo que la sección 9 del pedido prohíbe ("NO llamar al
 * modelo de embeddings repetidamente para el mismo término si existe un vector válido").
 *
 * Mismo diseño exacto que `taxonomy_category_embeddings` (Fase MCP-1): vector(1024) porque el
 * modelo real (@cf/baai/bge-m3) produce esa dimensión, índice HNSW para coseno, tabla separada de la
 * maestra para poder regenerar/cambiar de modelo sin tocar `taxonomy_terms`.
 *
 * Esta migración SOLO crea la tabla - queda en 0 filas al terminar esta entrega. Poblarla (generar
 * embeddings para los términos que lo necesiten) es una acción explícita y con costo de API, fuera
 * del alcance "solo lectura + dry-run" de esta fase; `CanonicalConceptBuilderService` reporta
 * honestamente cuando el retrieval semántico no tiene vectores disponibles, en vez de inventar un
 * resultado.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement('CREATE EXTENSION IF NOT EXISTS vector');

        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE taxonomy_term_embeddings (
                id BIGSERIAL PRIMARY KEY,
                term_id BIGINT NOT NULL REFERENCES taxonomy_terms(id) ON DELETE CASCADE,
                embedding vector(1024) NOT NULL,
                model_version VARCHAR(255) NOT NULL,
                source_text TEXT NOT NULL,
                generated_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                UNIQUE (term_id)
            )
        SQL);

        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_term_embeddings_hnsw ON taxonomy_term_embeddings USING hnsw (embedding vector_cosine_ops)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS taxonomy_term_embeddings');
    }
};
