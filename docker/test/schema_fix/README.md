# Schema bootstrap correction — proposal, applied only to the disposable PostgreSQL

## The problem, measured

`node docker/test/migration_deps.mjs database/migrations` reports, at
`99e603cb4713fe0a349f7958af44327e5ae34845`:

```
migrations scanned          : 98
tables created by migrations: 30
foreign key references      : 32 (28 with an inferred table name)
  satisfied at replay time  : 28
  VIOLATIONS                : 4
  unresolved                : 0
```

Four foreign keys reference a table that a **later** migration creates, so
replaying the set into an empty database aborts at the first of them:

| declared in | needs | created by |
|---|---|---|
| `2022_05_31_223828_create_empresas_table.php:36` | `cities` | `2022_07_28_183312_create_cities_table.php` |
| `2022_08_12_145310_create_sustainabilities_table.php:19` | `areas` | `2022_08_15_005221_create_areas_table.php` |
| `2022_09_01_154856_create_infratypes_table.php:18` | `infrasectors` | `2022_09_06_153218_create_infrasectors_table.php` |
| `2022_09_01_185804_create_infrafacilities_table.php:18` | `infraregion_infrasystem` | `2022_12_01_222538_create_infraregion_infrasystem_table.php` |

Observed on PostgreSQL 16.15: `SQLSTATE[42P01] relation "cities" does not
exist`, at migration 7 of 98, with 7 tables created. Whether other engines
reject the same declaration is **not** established by this evidence and is not
claimed here.

The existing databases do not have this problem because they were never built
by replaying the set from zero.

## The correction

Two parts, both minimal and both reversible:

1. **`defer_four_fks.patch`** — removes exactly the four constraint
   declarations and nothing else: 4 files, 3 insertions, 4 deletions. The
   **columns stay**, with the same types, so no table shape changes. Other
   `constrained()` calls in the same files (`empresa_id`, `user_id`) are
   untouched.

2. **`restore_four_fks.sql`** — re-creates those four foreign keys after the
   replay, once every referenced table exists, using the names Laravel itself
   generates (`{table}_{column}_foreign`) and the same default referential
   action.

`verify_constraints.sql` then asks the catalogue whether all four exist, and
**raises** if any is missing, so the suite cannot run against a schema that is
quietly short a foreign key.

Net effect: an empty database can be built from zero, and the schema it ends up
with has the same constraints as before.

## What this deliberately does not do

* **No foreign keys are disabled.** No `SET session_replication_role`, no
  `--no-foreign-key-checks`, no dropped constraint left dropped.
* **No migration is reordered or renamed.** Renaming a migration file changes
  its identity in the `migrations` table, which would make an applied migration
  look pending on every existing database.
* **Nothing is applied to any existing database.** The patch is applied by
  `run_tests.sh` to the disposable workspace only, and the SQL runs against the
  disposable fixture only. Neither leaves the test containers.
* **No test, fixture or expected result is edited** to make anything pass.

## Promoting it into the repository — the owner's decision, not taken here

Applied as a patch, the test run executes *the candidate SHA plus this patch*,
and the runner states that in its log and artifacts. That is honest for a test
run but it is not a fix to the repository. Two ways to make it permanent, both
of which change the code under test and therefore need authorization:

1. **Same change, committed.** Commit the patch, and add ONE new migration
   dated after `2022_12_01` that creates the four constraints if they are not
   already present. On existing databases the four edited migrations are
   already recorded as run, so the edits are inert there, and the new migration
   finds the constraints already in place and skips them.

2. **Forward-only.** Leave the four historical migrations untouched and move
   only the declarations into a new late migration, guarded the same way. Less
   surgical to read, but it never edits a migration that has already run
   anywhere.

Option 1 is the smaller diff and the one this patch already is. Neither is
applied here.

## Reproducing

```bash
node docker/test/migration_deps.mjs database/migrations   # the analysis, exit 1 if violations
./docker/test/run_tests.sh <sha>                          # applies the patch, prepares, then tests
```

The runner keeps the three result groups separate: isolation assertions, schema
preparation, and the test suite.
