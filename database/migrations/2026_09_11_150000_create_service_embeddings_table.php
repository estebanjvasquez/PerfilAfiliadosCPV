<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase MCP-4.1 (ver docs/taxonomia/plan_mcp_cira.md): embeddings del catálogo `services` (112
 * filas) para el fallback semántico de `search_empresas` — mismo diseño que
 * `taxonomy_category_embeddings` (Fase MCP-1), ya anticipado en el comentario de esa migración
 * ("el mismo patron que va a usar despues el buscador semantico de empresas").
 *
 * Por qué a nivel de `services` y no de `empresas`: el hueco real encontrado en la auditoría de
 * paridad (11 sep 2026) es que un termino como "soldadura" no aparece literal ni por trigrama
 * (pg_trgm) en ningun `services.name` real (el catalogo dice "MATERIALES, EQUIPOS Y ACCESORIOS
 * PARA SOLDAR", variante morfologica) ni "valvulas" tiene ninguna entrada cercana en absoluto. Un
 * embedding por EMPRESA no resolveria esto (nombres de empresa son nombres propios, sin contenido
 * semantico util); un embedding por SERVICIO (catalogo chico y estable, 112 filas) sí, porque el
 * contrato semantico esta en el texto del servicio, no en el nombre de quien lo presta - y una vez
 * identificado el servicio semanticamente cercano, las empresas se resuelven por el pivote
 * `empresa_sector_service` normal, sin necesidad de un segundo embedding.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement('CREATE EXTENSION IF NOT EXISTS vector');

        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE service_embeddings (
                id BIGSERIAL PRIMARY KEY,
                service_id BIGINT NOT NULL REFERENCES services(id) ON DELETE CASCADE,
                embedding vector(1024) NOT NULL,
                model_version VARCHAR(255) NOT NULL,
                source_text TEXT NOT NULL,
                generated_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                UNIQUE (service_id)
            )
        SQL);

        DB::connection('pgsql')->statement(
            'CREATE INDEX service_embeddings_hnsw ON service_embeddings USING hnsw (embedding vector_cosine_ops)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS service_embeddings');
    }
};
