<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TAXV2-2 (ver docs/taxonomia/INSTRUCCIONES_TAXONOMIA_CPV_CRAWLER_ADMIN_V2.md sección 4.6 y el plan
 * de esta fase): relaciones Término -> CPV del diccionario V2
 * (`records[].taxonomy_relations[]` del JSON).
 *
 * `category_id` es NULLABLE a propósito: de los 903 códigos CPV distintos referenciados en
 * `taxonomy_relations[]`, 2 no existen en `taxonomy_categories` (`CPV-48.01.13G`/`CPV-48.01.24G` -
 * mismo problema de tipeo del Grupo 48 ya documentado en acuerdos_pendientes_con_lorenzo.md, pero
 * sobre códigos que el JSON V2 trae con el error todavía sin corregir). Se guarda igual el
 * `cpv_code` crudo (nunca se descarta la fila) para no perder la relación una vez que alguien
 * corrija el código.
 *
 * `status` implementa el auto-triage por confianza decidido en el plan de esta fase (no se importa
 * todo como pareja/sin revisar): `admin_defaults.relation_thresholds.automatic_accept` (0.9) ->
 * `approved`; `minimum_relation_weight` (0.45) a 0.9 -> `needs_review`; por debajo -> `deprecated`
 * (nunca alimenta el refuerzo de ranking de TAXV2-5). Los valores del enum siguen
 * `admin_defaults.review_workflow` del JSON: candidate/auto_mapped/needs_review/approved/rejected/
 * deprecated - acá solo se usan needs_review/approved/deprecated al importar; rejected queda para
 * cuando un admin rechace una relación desde el panel (TAXV2-6).
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE taxonomy_term_cpv_relations (
                id BIGSERIAL PRIMARY KEY,
                term_id BIGINT NOT NULL REFERENCES taxonomy_terms(id) ON DELETE CASCADE,
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
                UNIQUE (term_id, cpv_code, relation_type, source)
            )
        SQL);

        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_term_cpv_relations_term_id_idx ON taxonomy_term_cpv_relations (term_id)'
        );
        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_term_cpv_relations_category_id_idx ON taxonomy_term_cpv_relations (category_id)'
        );
        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_term_cpv_relations_status_idx ON taxonomy_term_cpv_relations (status)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS taxonomy_term_cpv_relations');
    }
};
