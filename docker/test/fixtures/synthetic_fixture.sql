-- Synthetic fixture for the DISPOSABLE test PostgreSQL only.
-- Authorised by the owner decision of 2026-10-14: synthetic fixtures in the disposable test
-- database, no customer data, and never the shared database as a fixture.
--
-- NOTHING HERE IS COPIED FROM ANY EXISTING DATABASE. Every value is written out in this file, so
-- the fixture is a versioned definition that can be read, reviewed and reproduced from the
-- repository alone. No row is read from the shared Supabase database, by this file or by the
-- runner that loads it.
--
-- Run with -v ON_ERROR_STOP=1 against the disposable fixture, AFTER the migration replay and the
-- project's own idempotent seeders. It refuses to run on a database that already holds data in
-- the tables it fills (see the guards below), so it cannot silently add rows to anything real.
--
-- WHAT IT DOES NOT DO
--   * It does not disable, drop or defer a single constraint. Every insert below satisfies the
--     foreign keys, the unique keys and the not-null constraints the migrations declared; that is
--     the point of loading it through SQL instead of through a truncate-and-copy.
--   * It does not touch any table a test writes to inside its own transaction (concepts, terms,
--     candidate links, reviewed proposals, users). Those the tests create for themselves.
--   * It invents no permission, role or relation type. Those come from the project's own
--     versioned seeders - Phase3PermissionsSeeder, TaxonomyV2PermissionsSeeder and
--     taxonomy:seed-concept-vocabulary - which the runner invokes separately.

-- ---------------------------------------------------------------------------
-- 0. REFUSE TO RUN ON ANYTHING THAT IS NOT AN EMPTY FIXTURE.
--
--    A fixture loader pointed at the wrong database is the one mistake in this whole exercise
--    that could not be undone, so it fails closed rather than trusting the connection string.
-- ---------------------------------------------------------------------------
DO $guard$
DECLARE
  n_empresas    bigint;
  n_categories  bigint;
  db            text := current_database();
BEGIN
  IF db <> 'cpv_test' THEN
    RAISE EXCEPTION 'REFUSING: this fixture only loads into the disposable database "cpv_test", not into "%"', db;
  END IF;

  SELECT count(*) INTO n_empresas   FROM empresas;
  SELECT count(*) INTO n_categories FROM taxonomy_categories;

  IF n_empresas <> 0 OR n_categories <> 0 THEN
    RAISE EXCEPTION 'REFUSING: expected an empty fixture, found % empresas and % taxonomy_categories', n_empresas, n_categories;
  END IF;
END $guard$;

-- ---------------------------------------------------------------------------
-- 1. REFERENCE CATEGORY HIERARCHY.
--
--    WHY A HIERARCHY AND NOT A FLAT LIST. Three test helpers read the SHAPE of this tree, not
--    just its size, so a flat list of 18 rows would satisfy the count and still leave them
--    failing:
--
--      ValidationCorpusTest::anyLeafCategoryId()               needs >= 1 row at level 2
--      ValidationCorpusTest::siblingCategoryUnderSameFamily()  needs that leaf's parent to have
--                                                              another level-2 child
--      ValidationCorpusTest::categoryInDifferentFamilySameGroup()
--                                                              needs that parent's parent to have
--                                                              a SECOND level-1 child which itself
--                                                              has a level-2 child
--      TaxonomyConceptExplorerTest::existingCategoryIds(18)    needs >= 18 rows, each with a code
--
--    Shape below: 2 groups (level 0) x 2 families (level 1) x 5 subcategories (level 2) = 26 rows,
--    20 of them leaves. That satisfies all four with margin, and the margin is deliberate: the
--    explorer tests page 18 categories at 12 per page, so a fixture sized exactly 18 would make a
--    pagination test pass for the wrong reason.
--
--    THE CODES ARE DELIBERATELY NOT CPV CODES. ZZTEST- prefixed, so nobody reading a query
--    result, an export or a log can mistake a fixture row for a row of the real reference
--    catalogue, and so a stray fixture row in a real database is greppable. The real catalogue is
--    loaded by ImportTaxonomy from the classified master file, which is not in this repository
--    and is not needed to exercise this code: every test above reads the tree's shape and its
--    code column, never a specific CPV value.
--
--    path follows the convention the creating migration documents - the chain of codes from the
--    root, slash separated - written out here rather than left to SupplierCategoryObserver,
--    because psql does not boot the application.
--    source_version marks every row as fixture-made, for the same greppability reason.
-- ---------------------------------------------------------------------------

-- 1.1 Groups (level 0).
INSERT INTO taxonomy_categories (code, parent_id, level, path, source_version, is_active, created_at, updated_at)
VALUES
  ('ZZTEST-GRP-01', NULL, 0, 'ZZTEST-GRP-01', 'isolated-fixture-v1', true, now(), now()),
  ('ZZTEST-GRP-02', NULL, 0, 'ZZTEST-GRP-02', 'isolated-fixture-v1', true, now(), now());

-- 1.2 Families (level 1), two under each group.
INSERT INTO taxonomy_categories (code, parent_id, level, path, source_version, is_active, created_at, updated_at)
SELECT
  g.code || '-FAM-' || f.n,
  g.id,
  1,
  g.path || '/' || g.code || '-FAM-' || f.n,
  'isolated-fixture-v1',
  true, now(), now()
FROM taxonomy_categories g
CROSS JOIN (VALUES (1), (2)) AS f(n)
WHERE g.level = 0 AND g.source_version = 'isolated-fixture-v1'
ORDER BY g.id, f.n;

-- 1.3 Subcategories (level 2), five under each family.
INSERT INTO taxonomy_categories (code, parent_id, level, path, tipo_oferta, source_version, is_active, created_at, updated_at)
SELECT
  fam.code || '-SUB-' || s.n,
  fam.id,
  2,
  fam.path || '/' || fam.code || '-SUB-' || s.n,
  CASE WHEN s.n % 2 = 0 THEN 'Servicio' ELSE 'Bien' END,
  'isolated-fixture-v1',
  true, now(), now()
FROM taxonomy_categories fam
CROSS JOIN (VALUES (1), (2), (3), (4), (5)) AS s(n)
WHERE fam.level = 1 AND fam.source_version = 'isolated-fixture-v1'
ORDER BY fam.id, s.n;

-- ---------------------------------------------------------------------------
-- 2. FICTITIOUS COMPANIES.
--
--    Two tests read an existing empresas.id and then insert their own
--    empresa_taxonomy_category evidence against it:
--
--      CanonicalConceptBuilderServiceTest
--        ::predict_affected_companies_counts_direct_companies_deduplicated_by_empresa_id
--          "Se necesita al menos una empresa real en la BD de pruebas para este test."   -> >= 1
--      CanonicalConceptBuilderServiceTest
--        ::predict_affected_companies_breaks_down_direct_evidence_by_origen
--          "Se necesitan al menos 2 empresas reales en la BD de pruebas para este test." -> >= 2
--
--    Three rows, so the second test taking limit(2) still leaves one company that is NOT part of
--    its expected set - a fixture of exactly two would let an "all companies" bug pass as a
--    "the two I attached" result.
--
--    rif and name are the only NOT NULL columns of this table; everything else, city_id included,
--    is nullable and stays null. The RIFs are in the invalid-by-construction J-00000000 range and
--    the names say what they are, so no row here can be read as a real affiliate.
-- ---------------------------------------------------------------------------
INSERT INTO empresas (rif, name, created_at, updated_at)
VALUES
  ('J-00000000-1', 'ZZTEST Empresa Ficticia Uno',  now(), now()),
  ('J-00000000-2', 'ZZTEST Empresa Ficticia Dos',  now(), now()),
  ('J-00000000-3', 'ZZTEST Empresa Ficticia Tres', now(), now());

-- ---------------------------------------------------------------------------
-- 3. PROVE WHAT WAS LOADED, AND FAIL IF THE SHAPE IS WRONG.
--
--    The counts are asserted here rather than eyeballed from a report, so a fixture that loaded
--    partially is a failure of the fixture phase and never a mysterious test failure later.
-- ---------------------------------------------------------------------------
DO $verify$
DECLARE
  n_total bigint; n_l0 bigint; n_l1 bigint; n_l2 bigint; n_emp bigint;
  leaf_id bigint; sibling_id bigint; other_family_child_id bigint; family_id bigint; group_id bigint;
BEGIN
  SELECT count(*) INTO n_total FROM taxonomy_categories;
  SELECT count(*) INTO n_l0 FROM taxonomy_categories WHERE level = 0;
  SELECT count(*) INTO n_l1 FROM taxonomy_categories WHERE level = 1;
  SELECT count(*) INTO n_l2 FROM taxonomy_categories WHERE level = 2;
  SELECT count(*) INTO n_emp FROM empresas;

  IF n_total <> 26 OR n_l0 <> 2 OR n_l1 <> 4 OR n_l2 <> 20 THEN
    RAISE EXCEPTION 'fixture shape wrong: total=% l0=% l1=% l2=%', n_total, n_l0, n_l1, n_l2;
  END IF;
  IF n_emp <> 3 THEN
    RAISE EXCEPTION 'fixture shape wrong: empresas=%', n_emp;
  END IF;

  -- The three shape preconditions, asserted the way the tests read them.
  SELECT id INTO leaf_id FROM taxonomy_categories WHERE level = 2 ORDER BY id LIMIT 1;
  IF leaf_id IS NULL THEN
    RAISE EXCEPTION 'no level-2 category: anyLeafCategoryId() would return 0';
  END IF;

  SELECT parent_id INTO family_id FROM taxonomy_categories WHERE id = leaf_id;
  SELECT parent_id INTO group_id  FROM taxonomy_categories WHERE id = family_id;

  SELECT id INTO sibling_id
  FROM taxonomy_categories
  WHERE parent_id = family_id AND id <> leaf_id
  LIMIT 1;
  IF sibling_id IS NULL THEN
    RAISE EXCEPTION 'siblingCategoryUnderSameFamily() would return null';
  END IF;

  SELECT c.id INTO other_family_child_id
  FROM taxonomy_categories c
  WHERE c.parent_id = (
    SELECT f.id FROM taxonomy_categories f
    WHERE f.parent_id = group_id AND f.id <> family_id
    LIMIT 1)
  LIMIT 1;
  IF other_family_child_id IS NULL THEN
    RAISE EXCEPTION 'categoryInDifferentFamilySameGroup() would return null';
  END IF;

  RAISE NOTICE 'fixture ok: 26 categories (2/4/20), 3 empresas, leaf=% family=% group=% sibling=% other_family_child=%',
    leaf_id, family_id, group_id, sibling_id, other_family_child_id;
END $verify$;

SELECT 'taxonomy_categories' AS fixture_table, level, count(*) AS row_count
FROM taxonomy_categories GROUP BY level ORDER BY level;
SELECT 'empresas' AS fixture_table, NULL::int AS level, count(*) AS row_count FROM empresas;
