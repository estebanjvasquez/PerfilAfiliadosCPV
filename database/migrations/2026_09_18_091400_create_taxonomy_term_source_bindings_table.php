<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * V2->V3 (ver docs/taxonomia/MIGRACION_TAXONOMIA_CPV_V2_A_V3.md sección 2/3, "Paso 5 - bindings
 * múltiples"): reemplaza la idea de "1 término = 1 fuente" (la columna `taxonomy_terms.source_id`
 * original) por la relación N:M real que el JSON V2 siempre tuvo pero que el importador de TAXV2-1
 * colapsaba a un solo valor (tomando el primer elemento de `sources[]` - que casi siempre era
 * `CURATED`, aunque el término también tuviera SLB/OSHA/IADC como fuente real). Bug real reportado
 * por el usuario: los 1.837 términos mostraban "Curado manualmente" sin excepción.
 *
 * Esta tabla guarda SOLO bindings ya verificados (`provenance.source_bindings[]` del JSON V3, 117 en
 * total sobre 113 términos) - las sugerencias sin verificar (`candidate_sources[]`) NO van acá, van
 * en la columna `taxonomy_terms.candidate_sources` (jsonb) para no aparentar una verificación que no
 * existe (regla explícita del documento: "candidate_sources: Never show these as the term's true
 * source"). El crawler de fuentes (TAXV2-12) puede crear filas nuevas acá cuando encuentre una
 * coincidencia exacta contra un término ya existente mientras crawlea.
 *
 * `taxonomy_terms.source_id` (la columna original) NO se toca ni se elimina en esta migración - se
 * mantiene como marca de "por qué mecanismo entró la fila" (import JSON vs crawler vs manual), sin
 * pretender ya que sea "la fuente verdadera" del término (ese rol ahora lo cumplen
 * `origin_type`/`display_source`/`primary_source_id`, ver migración siguiente).
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE taxonomy_term_source_bindings (
                id BIGSERIAL PRIMARY KEY,
                term_id BIGINT NOT NULL REFERENCES taxonomy_terms(id) ON DELETE CASCADE,
                source_id VARCHAR(50) NOT NULL REFERENCES taxonomy_sources(source_id) ON DELETE CASCADE,
                source_term VARCHAR(255) NULL,
                source_url VARCHAR(500) NULL,
                language VARCHAR(10) NULL,
                binding_type VARCHAR(50) NULL,
                verification_status VARCHAR(30) NOT NULL DEFAULT 'pending_verification',
                sync_enabled BOOLEAN NOT NULL DEFAULT FALSE,
                first_seen_at TIMESTAMP NULL,
                last_seen_at TIMESTAMP NULL,
                last_verified_at TIMESTAMP NULL,
                content_hash VARCHAR(64) NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                UNIQUE (term_id, source_id)
            )
        SQL);

        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_term_source_bindings_term_id_idx ON taxonomy_term_source_bindings (term_id)'
        );
        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_term_source_bindings_source_id_idx ON taxonomy_term_source_bindings (source_id)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS taxonomy_term_source_bindings');
    }
};
