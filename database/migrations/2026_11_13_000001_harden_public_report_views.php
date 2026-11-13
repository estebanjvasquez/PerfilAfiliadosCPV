<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-0025 / F-SEC01-T24-02 (Issue #2 `6850596236`): the 13 report views in `public` stop running with their owner's
 * rights for the Supabase Data API roles.
 *
 * Why. In Supabase these views are owned by `postgres` (BYPASSRLS) and were created without `security_invoker`, so they
 * read their base tables with the owner's rights. Supabase's default privileges also grant every table privilege on
 * `public` relations to `anon` and `authenticated`. The base tables have RLS enabled with no policies, so those roles
 * read nothing from the tables directly, but through these views they read everything the views return (one of them
 * aggregates contact names, emails and phones).
 *
 * What, per view (all 13 have the same profile: no nested views, no functions use them, the only consumer is Laravel
 * connected as the owner):
 * - `security_invoker = true` (PostgreSQL 15+): the view checks the CALLER's privileges and RLS on the base tables. The
 *   owner (table owner, BYPASSRLS) and `service_role` (BYPASSRLS) read exactly what they read before; `anon` and
 *   `authenticated` get what RLS gives them on the base tables, which today is nothing.
 * - `REVOKE ALL ... FROM anon, authenticated`: least privilege, and a second barrier. A later `CREATE OR REPLACE VIEW`
 *   without the option silently resets `security_invoker`, but keeps the grants; a `DROP` + `CREATE` loses both, which
 *   is why a test pins the invariant for every public view.
 * No base-table privilege, RLS setting, policy or other role is touched.
 *
 * Idempotent (ALTER ... SET and REVOKE can repeat), scoped to these 13 names, and a no-op where a view or an API role
 * does not exist (fresh or test databases) or on a non-PostgreSQL connection. `down()` restores the previous state
 * exactly as observed in the shared database on 2026-11-13: option unset and ALL privileges for both API roles.
 */
return new class extends Migration
{
    private const UP_SQL = <<<'SQL'
DO $hardening$
DECLARE
    v text;
    r text;
BEGIN
    FOREACH v IN ARRAY ARRAY['ClientsView', 'ExperienceView', 'FacilityView', 'FinanceView', 'InventoryView',
                             'MachineryView', 'ManagementDetView', 'ManagementView', 'PresenceView', 'ResourceView',
                             'SustainabilityView', 'capacityView', 'catalogoView']
    LOOP
        IF EXISTS (SELECT 1 FROM pg_class c WHERE c.relnamespace = 'public'::regnamespace AND c.relname = v AND c.relkind = 'v') THEN
            EXECUTE format('ALTER VIEW public.%I SET (security_invoker = true)', v);
            FOREACH r IN ARRAY ARRAY['anon', 'authenticated']
            LOOP
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = r) THEN
                    EXECUTE format('REVOKE ALL ON public.%I FROM %I', v, r);
                END IF;
            END LOOP;
        END IF;
    END LOOP;
END
$hardening$;
SQL;

    private const DOWN_SQL = <<<'SQL'
DO $hardening$
DECLARE
    v text;
    r text;
BEGIN
    FOREACH v IN ARRAY ARRAY['ClientsView', 'ExperienceView', 'FacilityView', 'FinanceView', 'InventoryView',
                             'MachineryView', 'ManagementDetView', 'ManagementView', 'PresenceView', 'ResourceView',
                             'SustainabilityView', 'capacityView', 'catalogoView']
    LOOP
        IF EXISTS (SELECT 1 FROM pg_class c WHERE c.relnamespace = 'public'::regnamespace AND c.relname = v AND c.relkind = 'v') THEN
            EXECUTE format('ALTER VIEW public.%I RESET (security_invoker)', v);
            FOREACH r IN ARRAY ARRAY['anon', 'authenticated']
            LOOP
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = r) THEN
                    EXECUTE format('GRANT ALL ON public.%I TO %I', v, r);
                END IF;
            END LOOP;
        END IF;
    END LOOP;
END
$hardening$;
SQL;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(self::UP_SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::unprepared(self::DOWN_SQL);
    }
};
