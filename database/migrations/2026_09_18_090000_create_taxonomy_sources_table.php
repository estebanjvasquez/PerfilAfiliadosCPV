<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TAXV2-1 (ver docs/taxonomia/INSTRUCCIONES_TAXONOMIA_CPV_CRAWLER_ADMIN_V2.md secciones 4.11 y 5,
 * y el plan de esta fase): catálogo de fuentes del diccionario de términos V2
 * (`oil_gas_master_taxonomy_v2_cpv.json` -> `source_crawlers[]`), sembrado por
 * `taxonomy:seed-sources`.
 *
 * `CURATED` no viene en `source_crawlers[]` (esa lista solo trae las 3 fuentes crawleables:
 * SLB/OSHA/IADC) pero es la fuente real de 2.258 de los 1.837 términos del JSON (algunos términos
 * tienen más de una fuente) - se siembra a mano como fuente NO crawleable (`crawlable=false`,
 * `base_url` null) para no perder la trazabilidad de esos términos. `enabled` default false para
 * las 3 crawleables (mismo default que trae el JSON, `enabled_by_default: false`) - un admin debe
 * habilitarlas a propósito antes de que `taxonomy:crawl-source` (TAXV2-12) pueda correr contra
 * ellas.
 *
 * Estadísticas de crawleo (`last_crawl`, `terms_discovered`, etc. de la sección 4.11) NO se
 * guardan acá — se derivan de la última fila de `taxonomy_crawl_runs` (TAXV2-12) para no duplicar
 * el dato en 2 lugares.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE taxonomy_sources (
                id BIGSERIAL PRIMARY KEY,
                source_id VARCHAR(50) NOT NULL,
                name VARCHAR(255) NOT NULL,
                base_url VARCHAR(255) NULL,
                discovery_url VARCHAR(255) NULL,
                crawlable BOOLEAN NOT NULL DEFAULT FALSE,
                enabled BOOLEAN NOT NULL DEFAULT FALSE,
                respect_robots_txt BOOLEAN NOT NULL DEFAULT TRUE,
                rate_limit_rpm SMALLINT NULL,
                requires_admin_approval_before_import BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                UNIQUE (source_id)
            )
        SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS taxonomy_sources');
    }
};
