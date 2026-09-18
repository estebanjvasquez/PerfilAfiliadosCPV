<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TAXV2-3 (sección 4.8 del documento de instrucciones): relaciones Servicio legacy -> CPV del JSON
 * V2 (`service_to_cpv_relations[].taxonomy_relations[]`) - fuente NUEVA y complementaria a
 * `service_taxonomy_homologacion` (Fase 3, basada en distancia de embeddings en vivo, 1 sola
 * Familia por servicio) - decisión ya tomada de no tocar esa tabla/flujo.
 *
 * Mismo esqueleto de columnas y mismo criterio de auto-triage de `status` por confianza que
 * `taxonomy_term_cpv_relations` (ver esa migración) - la propia fila de `service_to_cpv_relations`
 * trae un campo `status: "legacy_bridge"` a nivel de SERVICIO (constante en las 112, sin valor
 * discriminante) que no se guarda acá; el `status` de esta tabla es el workflow de revisión
 * (`admin_defaults.review_workflow`), no ese tag.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE legacy_service_cpv_relations (
                id BIGSERIAL PRIMARY KEY,
                legacy_service_id BIGINT NOT NULL REFERENCES legacy_services(id) ON DELETE CASCADE,
                cpv_code VARCHAR(50) NOT NULL,
                category_id BIGINT NULL REFERENCES taxonomy_categories(id) ON DELETE SET NULL,
                level VARCHAR(20) NOT NULL,
                relation_type VARCHAR(30) NOT NULL,
                weight NUMERIC(5, 4) NOT NULL,
                confidence NUMERIC(5, 4) NOT NULL,
                matched_on TEXT NULL,
                source VARCHAR(50) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'needs_review',
                reviewed_by BIGINT NULL,
                reviewed_at TIMESTAMP NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                UNIQUE (legacy_service_id, cpv_code, relation_type, source)
            )
        SQL);

        DB::connection('pgsql')->statement(
            'CREATE INDEX legacy_service_cpv_relations_service_id_idx ON legacy_service_cpv_relations (legacy_service_id)'
        );
        DB::connection('pgsql')->statement(
            'CREATE INDEX legacy_service_cpv_relations_category_id_idx ON legacy_service_cpv_relations (category_id)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS legacy_service_cpv_relations');
    }
};
