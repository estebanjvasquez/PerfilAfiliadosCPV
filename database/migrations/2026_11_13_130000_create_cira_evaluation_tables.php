<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-0027 (Issue #2 `6852945146`): institutional evaluation of the CIRA search engine by the Chamber.
 *
 * Four tables, nothing else:
 * - `cira_eval_campaigns`: one evaluation campaign (draft / open / closed).
 * - `cira_eval_invitations`: who may evaluate in a campaign. Access is granted per campaign to an EXISTING user account
 *   (no new identity system, no global role or permission). Each invitation carries a pseudonym (E-01, E-02...), which is
 *   all the reports show of the evaluator.
 * - `cira_evaluations`: one evaluator's judgement of one search for one of the 8 guided tests. It points at the search
 *   in `cira_search_log` and keeps an IMMUTABLE snapshot of what the engine returned (query, ids, positions, evidence,
 *   engine version), so later ranking changes cannot rewrite the evidence. The 👍/👎 votes are NOT copied here: they stay
 *   in `cira_search_feedback`, the single source of truth, and are joined by `search_log_id`.
 * - `cira_eval_test_decisions`: the Chamber's explicit decision per test (approve / observe / reject). Nothing computes it.
 *
 * `cira_search_log` and `cira_search_feedback` are not altered. RLS is enabled with no policy (as on every Supabase table),
 * and the Data API roles are revoked, so neither `anon` nor `authenticated` can reach these rows (lesson of F-SEC01-T24-02).
 * Laravel connects as the table owner and is not affected. Idempotent (`IF NOT EXISTS`); `down()` drops exactly these
 * objects.
 */
return new class extends Migration
{
    public $connection = 'pgsql';

    public const TESTS = "'CPV-01', 'CPV-02', 'CPV-03', 'CPV-04', 'CPV-05', 'CPV-06', 'CPV-07', 'CPV-08'";

    public function up(): void
    {
        $db = DB::connection('pgsql');
        $tests = self::TESTS;

        $db->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cira_eval_campaigns (
                id BIGSERIAL PRIMARY KEY,
                code TEXT NOT NULL
                    CONSTRAINT cira_eval_campaigns_code_check CHECK (code ~ '^[A-Z0-9][A-Z0-9-]{1,39}$'),
                name TEXT NOT NULL
                    CONSTRAINT cira_eval_campaigns_name_check CHECK (length(name) BETWEEN 1 AND 200),
                description TEXT NULL
                    CONSTRAINT cira_eval_campaigns_description_check CHECK (description IS NULL OR length(description) <= 4000),
                status TEXT NOT NULL DEFAULT 'draft'
                    CONSTRAINT cira_eval_campaigns_status_check CHECK (status IN ('draft', 'open', 'closed')),
                opens_at TIMESTAMPTZ NULL,
                closes_at TIMESTAMPTZ NULL,
                created_by BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT cira_eval_campaigns_code_unique UNIQUE (code),
                CONSTRAINT cira_eval_campaigns_window_check CHECK (opens_at IS NULL OR closes_at IS NULL OR closes_at > opens_at)
            )
        SQL);

        $db->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS cira_eval_invitations (
                id BIGSERIAL PRIMARY KEY,
                campaign_id BIGINT NOT NULL REFERENCES cira_eval_campaigns(id) ON DELETE CASCADE,
                user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
                pseudonym TEXT NOT NULL
                    CONSTRAINT cira_eval_invitations_pseudonym_check CHECK (pseudonym ~ '^E-[0-9]{2,4}$'),
                invited_by BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
                expires_at TIMESTAMPTZ NULL,
                revoked_at TIMESTAMPTZ NULL,
                revoked_by BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT cira_eval_invitations_pseudonym_unique UNIQUE (campaign_id, pseudonym),
                CONSTRAINT cira_eval_invitations_user_unique UNIQUE (campaign_id, user_id)
            )
        SQL);

        $db->statement(<<<SQL
            CREATE TABLE IF NOT EXISTS cira_evaluations (
                id BIGSERIAL PRIMARY KEY,
                campaign_id BIGINT NOT NULL REFERENCES cira_eval_campaigns(id) ON DELETE RESTRICT,
                invitation_id BIGINT NOT NULL REFERENCES cira_eval_invitations(id) ON DELETE RESTRICT,
                test_code TEXT NOT NULL
                    CONSTRAINT cira_evaluations_test_code_check CHECK (test_code IN ({$tests})),
                search_log_id BIGINT NULL REFERENCES cira_search_log(id) ON DELETE SET NULL,
                query_text TEXT NOT NULL
                    CONSTRAINT cira_evaluations_query_text_check CHECK (length(query_text) BETWEEN 1 AND 2000),
                snapshot JSONB NOT NULL,
                snapshot_sha256 TEXT NOT NULL
                    CONSTRAINT cira_evaluations_snapshot_sha256_check CHECK (snapshot_sha256 ~ '^[0-9a-f]{64}$'),
                rating TEXT NULL
                    CONSTRAINT cira_evaluations_rating_check CHECK (rating IS NULL OR rating IN ('correcta', 'parcial', 'incorrecta')),
                extra_empresa_ids BIGINT[] NOT NULL DEFAULT '{}',
                missing_empresa_ids BIGINT[] NOT NULL DEFAULT '{}',
                missing_text TEXT NULL
                    CONSTRAINT cira_evaluations_missing_text_check CHECK (missing_text IS NULL OR length(missing_text) <= 1000),
                reason TEXT NULL
                    CONSTRAINT cira_evaluations_reason_check CHECK (reason IS NULL OR reason IN ('faltan_empresas', 'sobran_resultados', 'orden', 'explicacion', 'sin_resultados', 'otro')),
                comment TEXT NULL
                    CONSTRAINT cira_evaluations_comment_check CHECK (comment IS NULL OR length(comment) <= 2000),
                observation TEXT NULL
                    CONSTRAINT cira_evaluations_observation_check CHECK (observation IS NULL OR length(observation) <= 2000),
                status TEXT NOT NULL DEFAULT 'borrador'
                    CONSTRAINT cira_evaluations_status_check CHECK (status IN ('borrador', 'enviada')),
                submitted_at TIMESTAMPTZ NULL,
                client_token UUID NOT NULL,
                finding_category TEXT NULL
                    CONSTRAINT cira_evaluations_finding_category_check CHECK (finding_category IS NULL OR finding_category IN ('datos', 'taxonomia_sinonimos', 'indexacion', 'ranking', 'explicacion', 'otros')),
                reviewer_note TEXT NULL
                    CONSTRAINT cira_evaluations_reviewer_note_check CHECK (reviewer_note IS NULL OR length(reviewer_note) <= 2000),
                reviewed_by BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
                reviewed_at TIMESTAMPTZ NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT cira_evaluations_client_token_unique UNIQUE (client_token),
                CONSTRAINT cira_evaluations_submitted_needs_rating CHECK (status = 'borrador' OR (rating IS NOT NULL AND submitted_at IS NOT NULL))
            )
        SQL);
        // One evaluation per evaluator, test and logged search: a double click or a second tab updates the same row.
        $db->statement('CREATE UNIQUE INDEX IF NOT EXISTS cira_evaluations_one_per_search_idx ON cira_evaluations (invitation_id, test_code, search_log_id) WHERE search_log_id IS NOT NULL');
        $db->statement('CREATE INDEX IF NOT EXISTS cira_evaluations_campaign_test_idx ON cira_evaluations (campaign_id, test_code)');
        $db->statement('CREATE INDEX IF NOT EXISTS cira_evaluations_search_log_idx ON cira_evaluations (search_log_id)');

        // The evidence is immutable: once written, the snapshot, its hash, the query and the search it came from cannot
        // change (the link may only become NULL, when the referenced log row is deleted by its own ON DELETE SET NULL).
        $db->statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION cira_evaluations_keep_evidence() RETURNS trigger LANGUAGE plpgsql AS $fn$
            BEGIN
                IF NEW.snapshot IS DISTINCT FROM OLD.snapshot
                   OR NEW.snapshot_sha256 IS DISTINCT FROM OLD.snapshot_sha256
                   OR NEW.query_text IS DISTINCT FROM OLD.query_text
                   OR NEW.campaign_id IS DISTINCT FROM OLD.campaign_id
                   OR NEW.invitation_id IS DISTINCT FROM OLD.invitation_id
                   OR NEW.client_token IS DISTINCT FROM OLD.client_token
                   OR (NEW.search_log_id IS DISTINCT FROM OLD.search_log_id AND NEW.search_log_id IS NOT NULL) THEN
                    RAISE EXCEPTION 'cira_evaluations: la evidencia de una evaluación no se puede modificar' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END
            $fn$
        SQL);
        $db->statement('DROP TRIGGER IF EXISTS cira_evaluations_keep_evidence ON cira_evaluations');
        $db->statement('CREATE TRIGGER cira_evaluations_keep_evidence BEFORE UPDATE ON cira_evaluations FOR EACH ROW EXECUTE FUNCTION cira_evaluations_keep_evidence()');

        $db->statement(<<<SQL
            CREATE TABLE IF NOT EXISTS cira_eval_test_decisions (
                id BIGSERIAL PRIMARY KEY,
                campaign_id BIGINT NOT NULL REFERENCES cira_eval_campaigns(id) ON DELETE CASCADE,
                test_code TEXT NOT NULL
                    CONSTRAINT cira_eval_test_decisions_test_code_check CHECK (test_code IN ({$tests})),
                decision TEXT NOT NULL
                    CONSTRAINT cira_eval_test_decisions_decision_check CHECK (decision IN ('aprobado', 'observado', 'rechazado')),
                note TEXT NULL
                    CONSTRAINT cira_eval_test_decisions_note_check CHECK (note IS NULL OR length(note) <= 2000),
                decided_by BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
                decided_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT cira_eval_test_decisions_unique UNIQUE (campaign_id, test_code)
            )
        SQL);

        foreach (['cira_eval_campaigns', 'cira_eval_invitations', 'cira_evaluations', 'cira_eval_test_decisions'] as $table) {
            $db->statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        }
        $db->unprepared(<<<'SQL'
            DO $revoke$
            DECLARE t text; r text;
            BEGIN
                FOREACH t IN ARRAY ARRAY['cira_eval_campaigns', 'cira_eval_invitations', 'cira_evaluations', 'cira_eval_test_decisions'] LOOP
                    FOREACH r IN ARRAY ARRAY['anon', 'authenticated'] LOOP
                        IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = r) THEN
                            EXECUTE format('REVOKE ALL ON public.%I FROM %I', t, r);
                        END IF;
                    END LOOP;
                END LOOP;
            END
            $revoke$;
        SQL);
    }

    public function down(): void
    {
        $db = DB::connection('pgsql');
        $db->statement('DROP TABLE IF EXISTS cira_eval_test_decisions');
        $db->statement('DROP TABLE IF EXISTS cira_evaluations');
        $db->statement('DROP FUNCTION IF EXISTS cira_evaluations_keep_evidence()');
        $db->statement('DROP TABLE IF EXISTS cira_eval_invitations');
        $db->statement('DROP TABLE IF EXISTS cira_eval_campaigns');
    }
};
