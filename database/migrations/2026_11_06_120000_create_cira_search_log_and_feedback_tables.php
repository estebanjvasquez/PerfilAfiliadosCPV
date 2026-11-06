<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-0013 (Issue #2 `6684941317`): registro de búsquedas de CIRA y votos 👍/👎 por resultado.
 *
 * Para qué. El cliente no va a seguir llenando planillas de prueba, así que la calibración del
 * buscador necesita señales que se acumulen solas. Necesita saber qué buscó la gente, qué consulta
 * mandó realmente el agente de n8n, qué devolvió el Worker y en qué orden, y qué le pareció cada
 * resultado. Con eso la lista de referencia (`docs/uat/golden_set_lote001.json`) crece sin pedirle
 * nada a nadie, y `docs/uat/tools/evaluate_search.mjs` mide cada cambio contra ella.
 *
 * Por qué aquí y no en el log de n8n. El subflujo CIRA_log escribe en OTRO proyecto de Supabase, en
 * una tabla de 9 columnas sin los parámetros del agente ni los ids devueltos, y en el flujo
 * «Chat CIRA V5 - MCP» el nodo que lo llama está desconectado. Estas tablas viven en la misma base
 * que `empresas`, así que los votos se pueden cruzar con las empresas por clave foránea.
 *
 * Lo que NO guarda, a propósito: IP, user agent ni encabezados. «Build Log» de n8n los arma, pero
 * para calibrar un buscador no hacen falta, y guardarlos convertiría esto en un registro de
 * personas. `visitor_ref` y `voter_ref` son referencias opacas que pone quien escribe: un id
 * seudónimo del widget o el id interno de un usuario autenticado.
 *
 * Votos sin efecto automático. Ninguna fila de `cira_search_feedback` cambia el ranking por sí
 * sola. Los votos son evidencia para revisión. `voter_kind` existe porque los afiliados podrían votar
 * en contra de su competencia, y el análisis tiene que poder pesar distinto los votos del personal.
 *
 * RLS explícito. En Supabase todas las tablas de `public` tienen RLS activado y ninguna política, de
 * modo que la API REST (roles `anon`/`authenticated`) no puede leerlas. Eso lo pone un mecanismo de
 * Supabase y no las migraciones, así que una base distinta (la desechable de pruebas, una
 * restauración) quedaría sin esa protección. Por eso se activa aquí. Laravel y n8n se conectan
 * como dueños de la tabla y no se ven afectados.
 *
 * Idempotente (`IF NOT EXISTS`), por el mismo incidente de bookkeeping entre conexiones que documenta
 * `2026_09_16_110000_create_empresa_search_documents_table.php`.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public function up(): void
    {
        $db = DB::connection('pgsql');

        $db->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cira_search_log (
                id BIGSERIAL PRIMARY KEY,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                source TEXT NOT NULL DEFAULT 'n8n_chat'
                    CONSTRAINT cira_search_log_source_check
                    CHECK (source IN ('n8n_chat', 'evaluation', 'debug', 'other')),
                session_ref TEXT NULL,
                visitor_ref TEXT NULL,
                n8n_execution_id TEXT NULL,
                user_input TEXT NOT NULL
                    CONSTRAINT cira_search_log_user_input_check
                    CHECK (length(user_input) BETWEEN 1 AND 2000),
                agent_tool TEXT NULL,
                agent_params JSONB NULL,
                interpretation TEXT NULL,
                result_count INTEGER NULL
                    CONSTRAINT cira_search_log_result_count_check
                    CHECK (result_count IS NULL OR result_count >= 0),
                result_empresa_ids BIGINT[] NOT NULL DEFAULT '{}',
                result_evidence JSONB NULL,
                engine_version TEXT NULL,
                response_ms INTEGER NULL
                    CONSTRAINT cira_search_log_response_ms_check
                    CHECK (response_ms IS NULL OR response_ms >= 0),
                error TEXT NULL
            )
        SQL);

        $db->statement('CREATE INDEX IF NOT EXISTS cira_search_log_created_at_idx ON cira_search_log (created_at)');
        $db->statement('CREATE INDEX IF NOT EXISTS cira_search_log_session_ref_idx ON cira_search_log (session_ref)');

        $db->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cira_search_feedback (
                id BIGSERIAL PRIMARY KEY,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                search_log_id BIGINT NOT NULL
                    REFERENCES cira_search_log(id) ON DELETE CASCADE,
                empresa_id BIGINT NULL
                    REFERENCES empresas(id) ON DELETE SET NULL,
                rank_position INTEGER NULL
                    CONSTRAINT cira_search_feedback_rank_position_check
                    CHECK (rank_position IS NULL OR rank_position >= 1),
                vote SMALLINT NOT NULL
                    CONSTRAINT cira_search_feedback_vote_check
                    CHECK (vote IN (-1, 1)),
                reason TEXT NULL
                    CONSTRAINT cira_search_feedback_reason_check
                    CHECK (reason IS NULL OR reason IN ('otro_sector', 'solo_coincide_palabra', 'empresa_inactiva', 'falta_empresa', 'otro')),
                missing_empresa_id BIGINT NULL
                    REFERENCES empresas(id) ON DELETE SET NULL,
                comment TEXT NULL
                    CONSTRAINT cira_search_feedback_comment_check
                    CHECK (comment IS NULL OR length(comment) <= 1000),
                voter_kind TEXT NOT NULL DEFAULT 'anonymous'
                    CONSTRAINT cira_search_feedback_voter_kind_check
                    CHECK (voter_kind IN ('staff', 'affiliate', 'anonymous', 'evaluator')),
                voter_ref TEXT NULL,
                -- Un motivo solo acompaña a un voto negativo.
                CONSTRAINT cira_search_feedback_reason_needs_downvote
                    CHECK (reason IS NULL OR vote = -1),
                -- La empresa faltante solo tiene sentido con ese motivo.
                CONSTRAINT cira_search_feedback_missing_needs_reason
                    CHECK (missing_empresa_id IS NULL OR reason = 'falta_empresa')
            )
        SQL);

        // Un voto por votante, por búsqueda y por empresa. `empresa_id` NULL es el voto sobre el
        // resultado completo. Las COALESCE hacen que dos NULL choquen, que es lo que se quiere.
        $db->statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS cira_search_feedback_one_vote_idx
                ON cira_search_feedback (search_log_id, COALESCE(empresa_id, 0), COALESCE(voter_ref, ''))
        SQL);
        $db->statement('CREATE INDEX IF NOT EXISTS cira_search_feedback_empresa_idx ON cira_search_feedback (empresa_id)');

        $db->statement('ALTER TABLE cira_search_log ENABLE ROW LEVEL SECURITY');
        $db->statement('ALTER TABLE cira_search_feedback ENABLE ROW LEVEL SECURITY');
    }

    public function down(): void
    {
        $db = DB::connection('pgsql');
        $db->statement('DROP TABLE IF EXISTS cira_search_feedback');
        $db->statement('DROP TABLE IF EXISTS cira_search_log');
    }
};
