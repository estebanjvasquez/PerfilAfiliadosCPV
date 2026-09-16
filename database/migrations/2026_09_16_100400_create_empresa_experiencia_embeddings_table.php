<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase MCP-5.3 (ver docs/taxonomia/plan_mcp_cira.md): mismo diseño que `service_embeddings`/
 * `taxonomy_category_embeddings` - un vector por proyecto de `empresa_experiencias`. Necesario
 * porque la descripción de un proyecto es texto libre idiosincrático (ej. "REHABILITACION VIAL Y
 * SOLUCION DE DRENAJES EN LA CIRCUNVALACION No. 3") - una búsqueda léxica simple no va a resolver
 * la mayoría de las preguntas reales de un usuario ("empresas que hayan construido represas").
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement('CREATE EXTENSION IF NOT EXISTS vector');

        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE empresa_experiencia_embeddings (
                id BIGSERIAL PRIMARY KEY,
                experiencia_id BIGINT NOT NULL REFERENCES empresa_experiencias(id) ON DELETE CASCADE,
                embedding vector(1024) NOT NULL,
                model_version VARCHAR(255) NOT NULL,
                source_text TEXT NOT NULL,
                generated_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                UNIQUE (experiencia_id)
            )
        SQL);

        DB::connection('pgsql')->statement(
            'CREATE INDEX empresa_experiencia_embeddings_hnsw ON empresa_experiencia_embeddings USING hnsw (embedding vector_cosine_ops)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS empresa_experiencia_embeddings');
    }
};
