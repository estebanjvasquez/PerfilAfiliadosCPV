<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-0016 (Issue #2 `6791532623`): análisis con IA de una solicitud de categoría.
 *
 * Una fila por ejecución, SOLO INSERCIÓN: un análisis nuevo nunca sobrescribe a otro y la fila no se edita después
 * (un trigger rechaza UPDATE). Guarda la instantánea de la solicitud y de las categorías elegidas tal como estaban,
 * la versión de las instrucciones, el proveedor y el modelo, el estado, la salida validada, las fuentes, el error
 * saneado y el consumo si el proveedor lo informa.
 *
 * No toca `taxonomy_category_requests` ni la taxonomía: es una tabla nueva con FK hacia la solicitud.
 * `request_id` ON DELETE CASCADE, el mismo criterio que los intentos de aviso (si se borra la empresa, se borra la
 * solicitud y su historial). `actor_user_id` ON DELETE SET NULL: el análisis sobrevive al usuario.
 *
 * ADITIVA y reversible: `IF NOT EXISTS` (los tests la aplican dentro de su transacción) y `down()` la quita entera.
 * NO aplicada en la base compartida: aplicarla es un paso de despliegue que autoriza el propietario.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        $db = DB::connection('pgsql');

        $db->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS taxonomy_category_request_analyses (
                id BIGSERIAL PRIMARY KEY,
                request_id BIGINT NOT NULL REFERENCES taxonomy_category_requests(id) ON DELETE CASCADE,
                actor_user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
                status VARCHAR(20) NOT NULL,
                limited BOOLEAN NOT NULL DEFAULT FALSE,
                request_snapshot JSONB NOT NULL,
                categories_snapshot JSONB NOT NULL,
                catalog_candidates JSONB NOT NULL DEFAULT '[]',
                prompt_version VARCHAR(20) NOT NULL,
                provider VARCHAR(40) NOT NULL,
                model VARCHAR(100) NULL,
                output JSONB NULL,
                sources JSONB NOT NULL DEFAULT '[]',
                validation_issues JSONB NOT NULL DEFAULT '[]',
                error_code VARCHAR(40) NULL,
                error_message VARCHAR(300) NULL,
                input_tokens INTEGER NULL,
                output_tokens INTEGER NULL,
                cost_usd NUMERIC(10, 6) NULL,
                started_at TIMESTAMP NOT NULL,
                finished_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP NOT NULL,
                CONSTRAINT taxonomy_category_request_analyses_status_check
                    CHECK (status IN ('completed', 'failed'))
            )
        SQL);

        $db->statement('CREATE INDEX IF NOT EXISTS taxonomy_category_request_analyses_request_idx ON taxonomy_category_request_analyses (request_id, id)');
        $db->statement('CREATE INDEX IF NOT EXISTS taxonomy_category_request_analyses_day_idx ON taxonomy_category_request_analyses (created_at)');

        // Solo inserción: un análisis guardado no se reescribe (DELETE queda para el CASCADE de la solicitud).
        $db->statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION taxonomy_category_request_analyses_no_update() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'taxonomy_category_request_analyses is append-only';
            END;
            $$ LANGUAGE plpgsql
        SQL);
        $db->statement('DROP TRIGGER IF EXISTS taxonomy_category_request_analyses_append_only ON taxonomy_category_request_analyses');
        $db->statement(<<<'SQL'
            CREATE TRIGGER taxonomy_category_request_analyses_append_only
                BEFORE UPDATE ON taxonomy_category_request_analyses
                FOR EACH ROW EXECUTE FUNCTION taxonomy_category_request_analyses_no_update()
        SQL);

        // RLS explícito, como en TASK-0013: la API REST de Supabase (anon/authenticated) no la lee; Laravel se
        // conecta como dueño de la tabla y no se ve afectado.
        $db->statement('ALTER TABLE taxonomy_category_request_analyses ENABLE ROW LEVEL SECURITY');
    }

    public function down(): void
    {
        $db = DB::connection('pgsql');

        $db->statement('DROP TABLE IF EXISTS taxonomy_category_request_analyses');
        $db->statement('DROP FUNCTION IF EXISTS taxonomy_category_request_analyses_no_update()');
    }
};
