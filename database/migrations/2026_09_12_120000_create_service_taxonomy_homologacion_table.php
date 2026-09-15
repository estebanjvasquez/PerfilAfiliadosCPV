<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 3 del proyecto de taxonomía (ver docs/taxonomia/analisis_taxonomia_supplyhigh.md sección 6,
 * y docs/taxonomia/plan_mcp_cira.md para el trabajo de MCP que esto desbloquea): mapeo revisable
 * de cada uno de los 112 `services` (catálogo viejo, conexión mysql) hacia la Familia (nivel 1) más
 * cercana de `taxonomy_categories` (catálogo nuevo, conexión pgsql) - insumo para
 * `taxonomy:homologate-empresas`, que recién propaga esto a `empresa_taxonomy_category` por
 * empresa. Separado en 2 pasos (mapeo de servicio, después propagación a empresa) para poder
 * revisar 112 filas una sola vez en vez de cientos de filas de empresa si algo sale mal.
 *
 * `service_id` no lleva `constrained()` real: `services` vive en la conexión `mysql` (catálogo
 * viejo) y esta tabla vive en `pgsql` (mismo patrón cross-connection que `EmpresaPgsql` vs
 * `Empresa` en este proyecto) - Postgres no puede validar una FK contra una tabla de otra base de
 * datos. La integridad se valida en código (`HomologateServicesTaxonomy`), no en el schema.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE service_taxonomy_homologacion (
                id BIGSERIAL PRIMARY KEY,
                service_id BIGINT NOT NULL,
                category_id BIGINT NOT NULL REFERENCES taxonomy_categories(id) ON DELETE CASCADE,
                distance NUMERIC(6, 4) NOT NULL,
                method VARCHAR(255) NOT NULL DEFAULT 'semantic_auto',
                reviewed BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                UNIQUE (service_id, category_id)
            )
        SQL);

        DB::connection('pgsql')->statement(
            'CREATE INDEX service_taxonomy_homologacion_service_id_idx ON service_taxonomy_homologacion (service_id)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS service_taxonomy_homologacion');
    }
};
