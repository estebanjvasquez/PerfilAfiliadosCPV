<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TAXV2-13: una fila por página realmente descargada de la web de una empresa - registro de
 * evidencia ("guardar URL, fuente y fecha", regla 5 de la sección 5, aplicada acá también al
 * crawler de empresas). `page_type` es una clasificación best-effort por palabras clave de la URL
 * (home/about/services/capabilities/products/industries/projects/equipment/solutions, según la
 * lista priorizada de la sección 6) - solo para ordenar qué visitar primero y para mostrar contexto
 * en el panel, no alimenta el ranking.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE company_pages (
                id BIGSERIAL PRIMARY KEY,
                crawl_run_id BIGINT NOT NULL REFERENCES company_crawl_runs(id) ON DELETE CASCADE,
                empresa_id BIGINT NOT NULL,
                url VARCHAR(500) NOT NULL,
                page_type VARCHAR(30) NULL,
                http_status SMALLINT NULL,
                title VARCHAR(500) NULL,
                content_hash VARCHAR(64) NULL,
                fetched_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                UNIQUE (crawl_run_id, url)
            )
        SQL);

        DB::connection('pgsql')->statement(
            'CREATE INDEX company_pages_empresa_id_idx ON company_pages (empresa_id)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS company_pages');
    }
};
