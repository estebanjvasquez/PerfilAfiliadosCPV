# Isolated PHPUnit on Contabo — evidence tied to a tested SHA

Delivered against orchestrator instruction `6144477232` and the owner-relayed
instruction of 2026-10-13 (points 1–4: publish the isolation logs and commands
tied to the tested SHA; review migration dependencies and propose a minimal
correction; execute it only against the disposable PostgreSQL; keep isolation,
schema preparation and test results separate).

| | |
|---|---|
| SHA under test | `99e603cb4713fe0a349f7958af44327e5ae34845` (`feature/upgrade-filament-v3`) |
| Test runner | `infra/contabo-test-runner` @ `d17854bd875a68ce23ed7ec9a87f87046f00512f` |
| Run id | `20261013T183459Z-99e603cb4713` |
| Host | Contabo `66.94.121.98`, AlmaLinux 10.2 |
| Runtime | PHP 8.2.34 · `pdo_pgsql,intl,zip,gd,bcmath` · PostgreSQL 16.15 (`pgvector/pgvector:pg16`) |
| Artifacts | `/root/cpv-test/artifacts/20261013T183459Z-99e603cb4713/` — 18 files |

The three result groups are reported separately on purpose. A suite that needed
a prepared schema must not read as a suite that needed nothing, and a defect in
the repository under test is not a failure of the isolation.

```
RESULT  isolation      : PASS (18 checks)
RESULT  schema prep    : PASS (11 steps)   [3 patches applied; 4 ordering violations deferred]
RESULT  test suite     : RED (exit 2)      [Unit+Feature, full suite]
RESULT  findings in the repository under test: 1 reported by the run itself
```

## Reproducing it

```bash
# on the host, as root
cd /root/cpv-runner
git fetch origin infra/contabo-test-runner
git checkout --detach origin/infra/contabo-test-runner
./docker/test/run_tests.sh 99e603cb4713fe0a349f7958af44327e5ae34845

# free, no database, no container: the dependency analysis on its own
node docker/test/migration_deps.mjs database/migrations database/migrations/pgsql
```

Exit codes: `0` all three groups good, `3` isolation held but the schema could
not be prepared so the suite says nothing about the code, `1` anything else.

---

## Group 1 — Isolation: 18 checks, 0 failed

Asserted from Docker's own view, never claimed. Both listings below are
byte-identical (`sha256` `0a46bebbd6a5971e` for each), one taken from the
service container and one from **the container that actually ran PHPUnit**:

```
bind /root/cpv-test/20261013T183459Z-99e603cb4713 -> /app rw=true
```

Exactly one bind mount, and it is this run's disposable workspace. The guard
requires that mount by exact source and destination, requires the listing to be
non-empty, and requires the bind count to be exactly 1 — an unexpected host path
is a failure rather than an extra line nobody reads.

* **Fixture database publishes no host port**: `db_ports.json` is
  `{"5432/tcp":null}`. Reachable only by service name on the private network.
* **Every resolved connection verified before any migration** (`connections.txt`):

  ```
  APP_ENV                testing
  default connection     'pgsql'
  pgsql                  driver=pgsql  host=testdb  database=cpv_test
                         unused placeholder: nothing listening on 127.0.0.1:3306   (mysql)
                         unused placeholder: nothing listening on 127.0.0.1:3306   (mariadb)
                         unused placeholder: nothing listening on localhost:1433   (sqlsrv)
  live current_database  cpv_test
  live server address    172.21.0.2/32
  live version           PostgreSQL 16.15
  tables in public       0
  SAFE: every resolved connection points at the disposable fixture database.
  ```

  Every connection must be the fixture, or a sqlite file inside the workspace,
  or a loopback placeholder with **nothing listening** — attempted with a 0.5 s
  connect, not assumed. `supabase`, `pooler` and `amazonaws` host patterns are
  rejected for every driver, and a connection carrying a `url` is rejected
  because a url overrides the discrete fields.
* **`vendor/` and `bootstrap/cache` are created inside the workspace**, never
  shared with the live app. Writing package-discovery cache through a shared
  `bootstrap/cache` mount has previously produced live HTTP 500s; here the
  target is `/root/cpv-test/<run>/bootstrap/cache`, and the isolation is proven
  from `docker inspect` rather than from the absence of an incident.
* **Staging smoke before and after**: `200` / `200` on
  `https://pruebas.camarapetrolera.app/`.
* **Live stack untouched**: `app` started `2026-10-06T11:43:57Z`, `nginx`
  `2026-09-22T20:55:52Z`, `RestartCount=0` for both, measured after the run.
* **No deployment was triggered by any of this work.** The most recent
  `Deploy a Contabo` workflow run is `004d24e9` of `2026-10-06T11:43:21Z`, which
  matches the `app` container's start time; there have been none since. Every
  commit of this delivery touches only `docs/**` and `audit/**` on the
  integration branch, and the runner lives on a branch the workflow does not
  watch.

This run does **not** probe isolation by writing a sentinel into the live
`bootstrap/cache`. Proving a guard by exercising the dangerous path on a
production host is not a test.

---

## Group 2 — Schema preparation: 11 steps, 0 failed

### What was wrong

Replaying `database/migrations` into an empty database was impossible at this
SHA, for four independent reasons. Each was found by **running** the replay, not
by reading it, and each is reported rather than worked around.

| # | Defect | Replay died at | Minimal correction |
|---|---|---|---|
| 1 | Four foreign keys reference tables that later migrations create | 7 of 98 | `defer_four_fks.patch` — defer the four declarations, re-create them in SQL once their tables exist |
| 2 | The published spatie/laravel-permission migration is the **v5** stub while `composer.lock` installs **6.25.0** | 8 of 98 | `align_permission_migration_to_v6.patch` — the two locals the installed stub itself uses |
| 3 | `information_schema.statistics`, a **MySQL-only** view, in a 2026-07-24 migration | 36 of 98 | `portable_index_check.patch` — `Schema::hasIndex()`, driver-aware, Laravel 11+ |
| 4 | Nothing in this branch creates `taxonomy_categories`, referenced in 4 places | 36 of 98 | take the creating migrations from the branch that has them |

Measured on **PostgreSQL 16.15**, which is what this fixture runs. Whether other
engines reject the same declarations is not established by this run and is not
claimed.

The four ordering violations, from `migration_deps.txt`:

```
2022_05_31_223828_create_empresas_table.php:36        needs cities                  (2022_07_28)
2022_08_12_145310_create_sustainabilities_table.php:19 needs areas                  (2022_08_15)
2022_09_01_154856_create_infratypes_table.php:18      needs infrasectors            (2022_09_06)
2022_09_01_185804_create_infrafacilities_table.php:18 needs infraregion_infrasystem (2022_12_01)
```

### Defect 4 is a branch that is not merged, not schema to invent

`6aca79e` ("mover migraciones de pgsql a su propia carpeta") moved 20
migrations — 13 PostgreSQL view migrations and 7 Phase-4 table migrations —
into `database/migrations/pgsql/`. `git branch --contains 6aca79e` puts that
commit **only** on `origin/feature/supplhi-postgres-buscador`. At this SHA the
directory holds a single file, the `2026_09_07` rework, whose own docblock says
it assumes those tables already exist. It matches what the repository already
documented: `SkipMysqlOnlyViewMigrationsOnPgsql` states that the real views
"YA EXISTEN en Supabase (creadas por `feature/supplhi-postgres-buscador` con su
propio mecanismo)".

So the fixture is built from the **union of both sets**, taken at
`feature/supplhi-postgres-buscador@5df23fa9c5d0b93d5ccd0e155095a0e98726d6d0`
(21 files, listed in `pgsql_bootstrap_migrations.txt`). Laravel sorts migrations
by filename across every `--path`, so the dates reproduce the historical order:
base tables (2022–) → pgsql views (2026-08-31) → Phase 4 (2026-09-01) → the
rework (2026-09-07) → the migrations that reference them (2026-09-11+).

**Whether that branch should be merged is the owner's decision, not this
script's.** The run states the dependency instead of hiding it.

### What the correction does not do

* No foreign key is disabled anywhere — no `session_replication_role`, no
  dropped constraint left dropped.
* No migration is reordered or renamed. Renaming changes a migration's identity
  in the `migrations` table, which would make an applied migration look pending
  on every existing database.
* Nothing is applied to any existing database. The patches touch the disposable
  workspace; the SQL touches the disposable fixture. Neither leaves the test
  containers, and both are `git apply --check`ed first, so a patch that does not
  fit the SHA fails loudly instead of being forced onto a mangled tree.
* No test, fixture row or expected result is edited to make anything pass.
* The three patches apply cleanly to a tree at `99e603cb`, individually and
  together in order — verified separately from the run.

### Result: an empty database builds, with integrity intact

```
all 104 migrations replayed into the empty fixture
90 objects: 77 BASE TABLE + 13 VIEW
82 foreign key constraints in schema public
```

The four deferred constraints are confirmed from `pg_constraint`, with their
definitions, by SQL that **raises** when one is missing (`constraints.txt`):

```
NOTICE:  all four deferred foreign keys are present
 empresas_city_id_foreign                           | FOREIGN KEY (city_id) REFERENCES cities(id)
 infrafacilities_infraregion_infrasystem_id_foreign | FOREIGN KEY (infraregion_infrasystem_id) REFERENCES infraregion_infrasystem(id)
 infratypes_infrasectors_id_foreign                 | FOREIGN KEY (infrasectors_id) REFERENCES infrasectors(id)
 sustainabilities_areas_id_foreign                  | FOREIGN KEY (areas_id) REFERENCES areas(id)
```

Because the PostgreSQL view migrations now run, the fixture **has** the 13
views. The earlier stated limit — that the MySQL-only view migrations are
marked as run and their views therefore absent — no longer applies to the
views themselves.

---

## Group 3 — The suite: it ran, and it is red

```
Tests: 419, Assertions: 2157, Errors: 5, Failures: 21,
       Deprecations: 1, Skipped: 2, Risky: 2       phpunit exit 2    57s
```

393 of 419 pass. For scale: before the schema was complete the same suite
produced 265 errors and 395 assertions, so the schema work moved assertions from
395 to 2157.

**Seven of the 26 declare a data precondition in their own failure message**,
and these strings are in the tests at this SHA:

```
5 ×  Precondición: hacen falta 18 categorías reales de referencia.
1 ×  Se necesitan al menos 2 empresas reales en la BD de pruebas para este test.
1 ×  Se necesita al menos una empresa real en la BD de pruebas para este test.
```

What the fixture actually held, measured (`fixture_rows.txt`):

```
empresas                       0      taxonomy_term_cpv_relations    0
taxonomy_categories            0      users                          0
taxonomy_terms                 0      permissions                   16
taxonomy_canonical_concepts    0      roles                          0
```

The 16 permissions come from the project's own `Phase3PermissionsSeeder` and
`TaxonomyV2PermissionsSeeder`, run explicitly because the suite assumes the
Shield-style permission catalogue exists and `DatabaseSeeder` does not call
them. Both are idempotent (`firstOrCreate`). Before they were run, the dominant
failure was `PermissionDoesNotExist: There is no permission named
update_taxonomy::candidate::concept::link for guard web`, 265 times. No
permission, role or fixture row was invented by the runner.

**The claim boundary, stated precisely:** 7 of the 26 declare a data
precondition explicitly. The other 19 are assertion mismatches whose shape
(`expected size 2, actual 0`; `an array contains 274`; `0 is identical to 1`) is
consistent with an empty dataset, but that has **not** been verified test by
test and is not claimed here. Nothing in this delivery establishes that the
remaining 19 are not real code defects.

---

## Findings for the orchestrator

1. **The migration set cannot build an empty database at this SHA** — four
   independent causes, three fixed by minimal patches applied only to the test
   path, one (defect 4) caused by `feature/supplhi-postgres-buscador` not being
   merged. **Decision needed:** whether to promote the three patches into the
   repository, and whether that branch should be merged. `schema_fix/README.md`
   gives both promotion routes; neither is applied.
2. **The suite depends on data, not only on schema.** Seven tests say so
   themselves. A green full suite in isolation needs a data load — factories,
   seeders written for the purpose, or an anonymised extract. Authoring that
   data is outside a minimal correction and is the owner's call.
3. **`db:skip-mysql-only-view-migrations` cannot run on a new database**,
   although its own docblock says to run it once per new pgsql database: it
   reads `select max(batch) from migrations` before anything creates that table.
   The runner calls `migrate:install` first. On an existing database this is
   invisible.
4. **`.gitignore`'s blanket `*.sql` silently excluded the bootstrap SQL.**
   `git add` reported nothing and the run failed on the server with
   `restore_four_fks.sql: No such file or directory` after a successful replay.
   A negation rule now keeps those two files.

## Claims this delivery does NOT make

* Not that other engines reject the declarations of defects 1–3. Measured on
  PostgreSQL 16.15 only.
* Not that the historical 197/197 is invalid. It remains evidence of that
  execution. It is simply not evidence that the migrations can rebuild an empty
  database.
* Not that any historical run used a particular database.
  `DatabaseTransactions` in 17 test files establishes that those tests need a
  pre-existing schema, and `phpunit.xml`'s commented-out sqlite override
  establishes that `DB_CONNECTION` comes from the environment. Neither
  identifies which database any past run pointed at.
* Not that the fixture is equivalent to the shared database. It has the same
  schema and the 13 views; it has none of the data.
* Not that the remaining 19 assertion mismatches are data-caused.

## Artifact inventory

`/root/cpv-test/artifacts/20261013T183459Z-99e603cb4713/`, `sha256` prefixes:

| file | sha256 (16) | what it holds |
|---|---|---|
| `summary.txt` | `fbb339459d6b3b7e` | the three groups' counters, machine-readable |
| `mounts.txt` | `0a46bebbd6a5971e` | mounts of the service container |
| `mounts_phpunit.txt` | `0a46bebbd6a5971e` | mounts of the container that ran PHPUnit |
| `connections.txt` | `5f7cbfad613e69b2` | every resolved connection, pre-flight verdict |
| `constraints.txt` | `99cc24d19fe55929` | the four constraints from `pg_constraint`, FK totals |
| `migration_deps.txt` | `638d18e32e1b68bf` | the dependency analysis at this SHA |
| `fixture_rows.txt` | `253c949bc925480c` | what data the fixture held |
| `phpunit.txt` | `5933301d641e2fba` | the full suite output |
| `db_ports.json` | | published ports of the fixture database |
| `schema_fix_applied.diff` | | the patches' net effect on `database/migrations` |
| `pgsql_bootstrap_migrations.txt` | | the 21 files taken from the sibling branch |
| `migrate.log`, `seed.log`, `composer.log`, `build.log`, `schema_fix_sql.log`, `schema_fix.log`, `run.log` | | step logs |

## Executor state at delivery

Unrelated to the test runs, recorded because the infrastructure acceptance asks
for it.

* **Deployed version, proven byte-identical** rather than asserted: all ten
  modules under `/opt/cpv-orchestrator/cpv_orchestrator/` have `git hash-object`
  blob ids equal to those committed at
  `SISTEG-Agent-Orchestrator@b126bc11e9f2d927c616b7230c98b316ab7786e2`
  (`feature/cpv-executor-contabo`, pushed).
* **Cadence**: `cycle_complete` every 120 s to the second
  (…17:29:51, 17:31:51, 17:33:51, 17:35:51, 17:37:52, 17:39:51Z), 1.3–2.1 s per
  cycle, `health: ok`, service `active` and `enabled`.
* **Counters** from `/var/lib/cpv-orchestrator/state.db`: 6 review requests, 6
  distinct markers, `attempts=1` on every one, and `review_budget` totals 6
  (4+1+1 by hour) — one model call per request, no retries, no duplicates.
* **Fault injector disabled**: `CPV_FAULT_INJECT` appears in no unit file, no
  config under `/etc/cpv-orchestrator/`, and not in the unit's `Environment`.
* The review pointer was not advanced by any of this work until the delivery
  commit, so no review was spent on an intermediate state.
