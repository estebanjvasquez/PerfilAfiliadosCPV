<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TAXV2-1 (ver docs/taxonomia/oil_gas_master_taxonomy_v2_cpv.json `records[]` y el plan de esta
 * fase): el diccionario de términos real - reemplaza la "inteligencia" que hoy vive repartida como
 * constantes hardcodeadas (`GENERIC_ATTRACTOR_FAMILY_CODES` en `BuildEmpresaSearchDocuments.php` y
 * en el Worker externo `perfilafiliados-mcp`) por datos administrables: `relevance_weight`,
 * `oil_gas_exclusivity`, `ambiguity_penalty`, `context_required`, etc. por término.
 *
 * `region`/`negative_context`/`positive_context` son arrays nativos de Postgres (`text[]`), no
 * JSON - pedido explícito de la sección 11 del documento de instrucciones ("relaciones consultadas
 * frecuentemente deben ser tablas relacionales, no arrays JSON"; acá no son relaciones per se, pero
 * el mismo criterio de no usar JSON para lo que Postgres ya modela nativamente aplica).
 * `positive_context` no viene poblado en el JSON real (ningún registro lo trae), se deja para carga
 * manual futura vía el admin (sección 4.10).
 *
 * `term_category`/`term_subcategory` (no `category`/`subcategory`, los nombres del JSON) para no
 * confundir con `taxonomy_categories` (la jerarquía CPV) - son una clasificación temática distinta
 * y no relacionada (ej. "drilling", "completion_production", "people_slang_us").
 *
 * `pg_trgm` habilitado acá (sección 12 del documento: fuzzy matching sobre `term`/`canonical_term`,
 * mismo patrón ya usado en `perfilafiliados-mcp` para tolerancia a typos - Fase MCP-4.1).
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE taxonomy_terms (
                id BIGSERIAL PRIMARY KEY,
                external_id VARCHAR(50) NOT NULL,
                term VARCHAR(255) NOT NULL,
                language VARCHAR(5) NOT NULL,
                canonical_term VARCHAR(255) NOT NULL,
                term_category VARCHAR(100) NULL,
                term_subcategory VARCHAR(100) NULL,
                region TEXT[] NOT NULL DEFAULT '{}',
                term_type VARCHAR(50) NOT NULL,
                relevance_weight NUMERIC(5, 4) NOT NULL DEFAULT 0,
                oil_gas_exclusivity NUMERIC(5, 4) NOT NULL DEFAULT 0,
                context_required BOOLEAN NOT NULL DEFAULT FALSE,
                minimum_supporting_terms SMALLINT NOT NULL DEFAULT 0,
                context_window_words SMALLINT NOT NULL DEFAULT 80,
                ambiguity_penalty NUMERIC(5, 4) NOT NULL DEFAULT 0,
                negative_context TEXT[] NOT NULL DEFAULT '{}',
                positive_context TEXT[] NOT NULL DEFAULT '{}',
                source_id VARCHAR(50) NULL REFERENCES taxonomy_sources(source_id),
                mapping_review_status VARCHAR(30) NOT NULL DEFAULT 'unmapped',
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                UNIQUE (external_id)
            )
        SQL);

        DB::connection('pgsql')->statement(
            "CREATE INDEX taxonomy_terms_term_trgm ON taxonomy_terms USING gin (term gin_trgm_ops)"
        );
        DB::connection('pgsql')->statement(
            "CREATE INDEX taxonomy_terms_canonical_term_trgm ON taxonomy_terms USING gin (canonical_term gin_trgm_ops)"
        );
        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_terms_language_idx ON taxonomy_terms (language)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS taxonomy_terms');
    }
};
