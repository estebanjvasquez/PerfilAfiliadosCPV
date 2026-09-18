<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TAXV2-3: relaciones Término -> Servicio legacy del JSON V2
 * (`records[].legacy_service_relations[]`). A diferencia de las relaciones CPV, acá `status` NO es
 * el workflow de revisión - el JSON trae directo el estado operativo del servicio en ese momento
 * (`legacy_active`/`deprecated`/`disabled`, mismo vocabulario de la sección 4.7), así que se
 * importa tal cual como un snapshot informativo, sin auto-triage. `sectors_id`/`service_name` del
 * JSON no se duplican acá - ya viven en `legacy_services` (join por `legacy_service_id`).
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE taxonomy_term_service_relations (
                id BIGSERIAL PRIMARY KEY,
                term_id BIGINT NOT NULL REFERENCES taxonomy_terms(id) ON DELETE CASCADE,
                legacy_service_id BIGINT NOT NULL REFERENCES legacy_services(id) ON DELETE CASCADE,
                relation_type VARCHAR(30) NOT NULL,
                weight NUMERIC(5, 4) NOT NULL,
                confidence NUMERIC(5, 4) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'legacy_active',
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                UNIQUE (term_id, legacy_service_id, relation_type)
            )
        SQL);

        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_term_service_relations_term_id_idx ON taxonomy_term_service_relations (term_id)'
        );
        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_term_service_relations_service_id_idx ON taxonomy_term_service_relations (legacy_service_id)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS taxonomy_term_service_relations');
    }
};
