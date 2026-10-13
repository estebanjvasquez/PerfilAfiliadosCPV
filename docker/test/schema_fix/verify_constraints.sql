-- Evidence that the schema preparation preserved integrity, asked of the
-- database catalogue rather than asserted by the script that just ran.
--
-- It FAILS CLOSED: a missing constraint raises, so the run cannot continue to
-- the suite with a schema that is quietly missing a foreign key.

\echo '== The four deferred constraints must exist'

DO $$
DECLARE
  missing text[];
BEGIN
  SELECT array_agg(expected.name)
    INTO missing
    FROM (VALUES
      ('empresas_city_id_foreign'),
      ('sustainabilities_areas_id_foreign'),
      ('infratypes_infrasectors_id_foreign'),
      ('infrafacilities_infraregion_infrasystem_id_foreign')
    ) AS expected(name)
   WHERE NOT EXISTS (
     SELECT 1
       FROM pg_constraint
      WHERE conname = expected.name
        AND contype = 'f'
   );

  IF missing IS NOT NULL THEN
    RAISE EXCEPTION 'missing foreign key constraint(s): %', missing;
  END IF;

  RAISE NOTICE 'all four deferred foreign keys are present';
END $$;

\echo ''
\echo '== Their definitions, from the catalogue'
SELECT conname AS constraint_name,
       pg_get_constraintdef(oid) AS definition
  FROM pg_constraint
 WHERE contype = 'f'
   AND conname IN ('empresas_city_id_foreign',
                   'sustainabilities_areas_id_foreign',
                   'infratypes_infrasectors_id_foreign',
                   'infrafacilities_infraregion_infrasystem_id_foreign')
 ORDER BY conname;

\echo ''
\echo '== Every foreign key in the fixture, counted'
SELECT count(*) AS foreign_keys_total
  FROM pg_constraint c
  JOIN pg_namespace n ON n.oid = c.connamespace
 WHERE c.contype = 'f'
   AND n.nspname = 'public';

\echo ''
\echo '== Tables and views present'
SELECT table_type, count(*) AS n
  FROM information_schema.tables
 WHERE table_schema = 'public'
 GROUP BY table_type
 ORDER BY table_type;
