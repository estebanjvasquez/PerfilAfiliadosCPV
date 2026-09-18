<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * V2->V3 (ver docs/taxonomia/MIGRACION_TAXONOMIA_CPV_V2_A_V3.md y
 * docs/taxonomia/oil_gas_master_taxonomy_v3_source_driven.json -> `records[].provenance`):
 *
 * - `origin_type`: 'external_verified' (113 términos) / 'seed_taxonomy_pending_source_verification'
 *   (544) / 'curated_or_generated' (1.232) - reemplaza la idea de que TODO término tiene una fuente
 *   externa verdadera; la mayoría son semilla/curados y eso es correcto, no un bug, siempre que no se
 *   les etiquete como si tuvieran una fuente externa comprobada.
 * - `display_source`: texto ya armado por el JSON V3 para mostrar en el admin (ej. "OSHA verified",
 *   "Seed taxonomy — source verification pending") - arregla directamente el bug reportado (todos
 *   los términos mostraban "Curado manualmente" sin excepción).
 * - `primary_source_id`: NULL salvo que `origin_type=external_verified`; apunta a la fuente
 *   verificada principal (coincide con la fila correspondiente en `taxonomy_term_source_bindings`).
 * - `candidate_sources` (jsonb): sugerencias SIN verificar heredadas de la generación V2
 *   (`provenance.candidate_sources[]`) - se guardan para que el crawler las priorice, pero nunca se
 *   muestran como si fueran la fuente real del término (regla explícita del documento V3).
 *
 * No se toca `source_id` (ver docblock de la migración de `taxonomy_term_source_bindings`).
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            ALTER TABLE taxonomy_terms
                ADD COLUMN origin_type VARCHAR(50) NULL,
                ADD COLUMN display_source VARCHAR(255) NULL,
                ADD COLUMN primary_source_id VARCHAR(50) NULL REFERENCES taxonomy_sources(source_id) ON DELETE SET NULL,
                ADD COLUMN candidate_sources JSONB NULL
        SQL);

        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_terms_origin_type_idx ON taxonomy_terms (origin_type)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            ALTER TABLE taxonomy_terms
                DROP COLUMN IF EXISTS origin_type,
                DROP COLUMN IF EXISTS display_source,
                DROP COLUMN IF EXISTS primary_source_id,
                DROP COLUMN IF EXISTS candidate_sources
        SQL);
    }
};
