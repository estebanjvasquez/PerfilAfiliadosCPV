<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TAXV2-12 (ver docs/taxonomia/INSTRUCCIONES_TAXONOMIA_CPV_CRAWLER_ADMIN_V2.md sección 5 y el plan
 * de esta fase): historial de corridas del crawler de fuentes terminológicas (SLB/OSHA/IADC).
 *
 * `pending_urls`/`visited_urls` (arrays nativos `text[]`, mismo patrón de TAXV2-1 con
 * `PostgresTextArrayCast`) son el checkpoint real: cada invocación de `taxonomy:crawl-source`
 * retoma la fila `running` más reciente de esa fuente (o crea una nueva si no hay ninguna activa),
 * saca hasta `--limit` URLs de `pending_urls`, las mueve a `visited_urls`, y encola los links del
 * mismo host que encuentre - así una fuente se recorre en varias invocaciones acotadas (pensadas
 * para cron) en vez de una sola corrida larga que agote el pool PHP-FPM del hosting compartido
 * (ver docs/plan_despliegue_taxonomia_cira.md). Cuando `pending_urls` queda vacío, `status` pasa a
 * `completed`.
 *
 * `status = blocked` es la regla 7 de la sección 5 ("si cambia el acceso, detener la fuente"): si
 * robots.txt empieza a prohibir la URL semilla, el comando detiene esa fuente y dejar rastro acá
 * en vez de reintentar solo.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        DB::connection('pgsql')->statement(<<<'SQL'
            CREATE TABLE taxonomy_crawl_runs (
                id BIGSERIAL PRIMARY KEY,
                source_id VARCHAR(50) NOT NULL REFERENCES taxonomy_sources(source_id) ON DELETE CASCADE,
                status VARCHAR(20) NOT NULL DEFAULT 'running',
                pending_urls TEXT[] NOT NULL DEFAULT '{}',
                visited_urls TEXT[] NOT NULL DEFAULT '{}',
                pages_processed INTEGER NOT NULL DEFAULT 0,
                candidates_found INTEGER NOT NULL DEFAULT 0,
                started_at TIMESTAMP NULL,
                finished_at TIMESTAMP NULL,
                error_message TEXT NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            )
        SQL);

        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_crawl_runs_source_id_idx ON taxonomy_crawl_runs (source_id)'
        );
        DB::connection('pgsql')->statement(
            'CREATE INDEX taxonomy_crawl_runs_status_idx ON taxonomy_crawl_runs (status)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP TABLE IF EXISTS taxonomy_crawl_runs');
    }
};
