<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TAXV2-13 (sección 6 del documento de instrucciones y el plan de esta fase): historial de corridas
 * del crawler de webs de empresas - mismo patrón de checkpoint que `taxonomy_crawl_runs` (TAXV2-12):
 * `pending_urls`/`visited_urls` (`text[]`) son el estado real que permite que
 * `taxonomy:crawl-company-website` procese una empresa (o un lote chico) por invocación y la
 * retome en la siguiente corrida sin repetir páginas ya visitadas.
 *
 * `empresa_id` NO lleva `constrained()`/FK real: `empresas` vive en la conexión `mysql`, esta tabla
 * en `pgsql` (mismo patrón cross-connection ya documentado en
 * `2026_09_12_120000_create_service_taxonomy_homologacion_table.php` para `service_id`) - Postgres
 * no puede validar una FK contra una tabla de otra base de datos.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE company_crawl_runs (
                id BIGSERIAL PRIMARY KEY,
                empresa_id BIGINT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'running',
                pending_urls TEXT[] NOT NULL DEFAULT '{}',
                visited_urls TEXT[] NOT NULL DEFAULT '{}',
                pages_processed INTEGER NOT NULL DEFAULT 0,
                matches_found INTEGER NOT NULL DEFAULT 0,
                started_at TIMESTAMP NULL,
                finished_at TIMESTAMP NULL,
                error_message TEXT NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            )
        SQL);

        DB::connection('pgsql')->statement(
            'CREATE INDEX company_crawl_runs_empresa_id_idx ON company_crawl_runs (empresa_id)'
        );
        DB::connection('pgsql')->statement(
            'CREATE INDEX company_crawl_runs_status_idx ON company_crawl_runs (status)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS company_crawl_runs');
    }
};
