# Isolated PHPUnit on Contabo — evidence tied to a tested SHA

Delivered against orchestrator instruction `6144477232` and the owner-relayed
instruction of 2026-10-13 (points 1–4: publish the isolation logs and commands
tied to the tested SHA; review migration dependencies and propose a minimal
correction; execute it only against the disposable PostgreSQL; keep isolation,
schema preparation and test results separate).

Corrected after verdict `6152642797`. See **Redaction** and **Where the
artifacts are** below for findings 1 and 3 of that verdict.

| | |
|---|---|
| SHA under test | `99e603cb4713fe0a349f7958af44327e5ae34845` (`feature/upgrade-filament-v3`) |
| Test runner | `infra/contabo-test-runner` @ `d17854bd875a68ce23ed7ec9a87f87046f00512f` (first run, and the commit the three patches live at) · `04c1fbf9abcfa6847575368e5761482a8345ff2b` (repeat run, after the host path was externalised) |
| Run id | `20261013T183459Z-99e603cb4713` (first) · `20261014T063709Z-99e603cb4713` (repeat) |
| Host | the project staging VPS, `66.94.121.98` (already recorded in `PROJECT_KNOWLEDGE.md`) |
| Runtime | PHP 8.2.34 · `pdo_pgsql,intl,zip,gd,bcmath` · PostgreSQL 16.15 (`pgvector/pgvector:pg16`) |
| Artifacts | 18 files per run on the host; the checkable ones are committed, redacted, under [`audit/isolated_phpunit_2026-10-13/`](isolated_phpunit_2026-10-13/) (10 files, first run) and [`audit/isolated_phpunit_2026-10-14/`](isolated_phpunit_2026-10-14/) (5 files, repeat run) |

The three result groups are reported separately on purpose. A suite that needed
a prepared schema must not read as a suite that needed nothing, and a defect in
the repository under test is not a failure of the isolation.

```
RESULT  isolation      : PASS (18 checks)
RESULT  schema prep    : PASS (11 steps)   [3 patches applied; 4 ordering violations deferred]
RESULT  test suite     : RED (exit 2)      [Unit+Feature, full suite]
RESULT  findings in the repository under test: 1 reported by the run itself
```

## Redaction

This repository is **public**, and `PROJECT_KNOWLEDGE.md`'s visibility notice
allows already-published infrastructure identifiers to stay but forbids adding
new detail — system users, database hosts, internal paths. The first version of
this document broke that rule, which verdict `6152642797` reported as a major
finding. It was right.

Measured at `99e603cb`, so that redaction follows the rule rather than a
feeling about it. **One command, quoted so the numbers can be reproduced** —
note the pattern carries no leading `/`, for the reason given below:

```bash
git grep -c "<pattern>" 99e603c | awk -F: '{s+=$NF} END {print s+0}'
```

| detail | occurrences before this work | status now |
|---|---|---|
| executor install directory | 0 | **published** in git history, see below |
| executor state directory | 0 | **published** in git history, see below |
| executor config directory | 0 | **published** in git history, see below |
| runner workspace directory | 0 | **published** in git history, see below |
| runner checkout directory | 0 | **published** in git history, see below |
| exact OS release | 0 | **published** in git history, see below |
| private container network address | 2, both byte matches inside the binary `app/images/user.pdf`; 0 in any text file | **published** in git history, see below |
| live application directory | 13, including `.github/workflows/deploy-contabo.yml` | already published before this work; placeholdered here anyway |
| `66.94.121.98` | 12 | already published; kept |
| `vmi3554091…` | 4 | already published; kept |
| `pruebas.camarapetrolera.app` | 33 | already published; kept |

The search patterns for the first seven rows are the literal strings
themselves, so they are not reproduced in this column: an earlier version of
this table listed them and thereby republished, while documenting the
redaction, every string the redaction had removed.

### The exposure is in public history, and removing it from the working tree did not undo that

Verdict `6162683638` was right, and it corrected a real understatement of mine.
I had written that the republication was "caught before any review saw it",
which implied the strings were never published. They were: I had already
**pushed** the commit. A pushed commit on a public repository is world-readable
immediately, and deleting the strings in a later commit removes them from the
working tree, not from history.

Measured across the pushed commits of this work, counting how many of the seven
literals each one's tree contains:

```
integration branch, origin/feature/upgrade-filament-v3
  650db29   7 of 7   the original delivery - where the exposure began
  25e25c3   0        the redaction
  20d3c12   0
  179efa3   0
  83c6de7   7 of 7   re-introduced by the redaction table itself
  38c21bb   0        corrected
  2303eda   0

runner branch, origin/infra/contabo-test-runner
  4045faa … d17854b  the two runner scratch paths, in 11 consecutive pushed
                     commits; 04c1fbf9abcfa6847575368e5761482a8345ff2b removes
                     them going forward only
```

So the honest classification is **published**, not "new, redacted", and not
"never published in readable form". The redaction limits what a reader of the
current tree sees; it does not limit what a reader of history sees. The same
applies to the runner branch, which verdict `6162683638` could not inspect and
said so — the record here covers it because the record should be complete, not
because it was asked for.

What the redaction still achieves: the current tree of both branches is clean
(a grep for all seven returns 0 in `audit/`, `docs/` and `docker/`), and
nothing further is added.

### GATE F — remediation is the owner's decision, not taken here

The options are a history rewrite, changing repository visibility, or accepting
the exposure as it stands. **None is applied.** History rewriting and
force-pushing are prohibited for this agent, and repository visibility is an
owner decision, so this is reported for the owner to decide with an accurate
record rather than acted on. Nothing about the exposed detail is a credential:
the seven items are filesystem paths, a distribution name and a private subnet
prefix. That is context for the decision, not an argument for any particular
one.

The first attempt at this measurement reported 0 for everything, including
identifiers that are plainly in the deploy workflow. The cause was the command,
not the repository: `git grep -- "/pattern" <rev>` makes git read the pattern as
a pathspec, so it searched for files with those names and matched nothing. The
table above comes from the corrected form. The wrong numbers were never
published; they are recorded here because a redaction justified by a broken
measurement would be worth no more than an unredacted document.

So absolute host paths, the OS release, the service account and the private
container address are replaced throughout, here and in the committed artifacts,
by role-based placeholders: `<runner checkout>`, `<workspace>`,
`<artifacts dir>`, `<live app dir>`, `<executor install dir>`,
`<executor state dir>`, `<executor config dir>`, `<private network address>`.
The unredacted originals stay on the host, outside this repository.

### The same rule applied where the review did not look

Verdict `6152642797` reviewed the integration-branch commit. The identical
violation was in the runner's own source on `infra/contabo-test-runner` — the
same public repository, a different branch — because `run_tests.sh` hard-coded
the scratch root under the host account, which the measurement above puts at 0
prior occurrences. Leaving it because nobody flagged it would have been
knowingly keeping a reported class of defect.

It now comes from `CPV_TEST_ROOT` with **no default**: a missing value is a
loud failure rather than a silent fallback to somebody's home directory, and a
relative value is rejected. The live application directory keeps its default,
since it is already published 13 times including in the deploy workflow, and
`CPV_LIVE_APP_DIR` can override it. Fixed in
`infra/contabo-test-runner` @ `04c1fbf9abcfa6847575368e5761482a8345ff2b`, and the run was repeated afterwards
(`20261014T063709Z-99e603cb4713`) to confirm the runner still works end to end
with the path supplied from outside: isolation PASS (18 checks, 0 failed),
schema preparation PASS (11 steps, 0 failed), 90 objects, suite RED again.

The one place redaction costs evidence is `mounts.txt`, where the workspace path is
now a placeholder, so a reader cannot see for themselves that it is disjoint
from the live application directory. The runner asserts exactly that, by exact
string, and records the assertion in `summary.txt` as part of the 18 isolation
checks.

## The pointer was advanced, and here is the proof

Verdict `6162548587` raised, conditionally, that the pointer might not have been
advanced for that round, because the handoff excerpt it was given showed
`review_head=650db29c` and `review_requested_at=2026-10-13T18:40:33Z` — the
previous round's values. It was right to raise it: nothing in the bundle said
which commit that excerpt came from.

The pointer had been advanced. `review_head` read straight from git at each
commit of that round:

```
at 650db29  review_head=d1e1ee1485b2  requested_at=2026-10-13T10:21:48Z
at 25e25c3  review_head=650db29c6daf  requested_at=2026-10-13T18:40:33Z
at 20d3c12  review_head=650db29c6daf  requested_at=2026-10-13T18:40:33Z   <- reviewed commit
at 179efa3  review_head=20d3c12d9bc7  requested_at=2026-10-14T06:40:59Z   <- pointer commit
```

That is the two-commit pattern working: a commit cannot contain its own hash, so
the pointer naming the content commit must sit above it.

The decisive check is the marker, because the executor derives it from
`review_head` and nothing else. The marker published on `6162548587` is
`211231898585851937714c720a90d920`, and:

```
sha256("<repo>|KNOWLEDGE-HANDOFF|feature/upgrade-filament-v3|20d3c12…")[:32]
  = 211231898585851937714c720a90d920   <- matches the published marker
sha256(… same, but 650db29…)[:32]
  = ac6200348544573b58dbee9673f68072   <- that is the PREVIOUS verdict's marker
```

Had the pointer still named `650db29`, the executor would have found the
existing marker and skipped the round as already reviewed. It did not: it logged
`branch_advanced_past_review_head  declared=20d3c12d9bc7  tip=179efa3e617f`,
which is it reading the advanced pointer from the tip.

**Fixed where the ambiguity actually was**, since a conditional finding that
recurs every round costs a review each time: the executor's evidence bundle now
labels the handoff section with the commit it was read at and states what the
pointer fields mean there. `SISTEG-Agent-Orchestrator@64ecc4bf`.

## Reproducing it

```bash
# in the runner checkout on the host
git fetch origin infra/contabo-test-runner
git checkout --detach origin/infra/contabo-test-runner
./docker/test/run_tests.sh 99e603cb4713fe0a349f7958af44327e5ae34845

# free, no database, no container: the dependency analysis on its own
node docker/test/migration_deps.mjs database/migrations database/migrations/pgsql
```

Exit codes: `0` all three groups good, `3` isolation held but the schema could
not be prepared so the suite says nothing about the code, `1` anything else.

## Where the artifacts are

Verdict `6152642797`, finding 3, asked for the evidence to be checkable from
the repository rather than cited by hash. **Which commit produced which set**,
since verdict `6162548587` finding 4 asked for exactly that:

| artifact set | run | runner commit |
|---|---|---|
| [`audit/isolated_phpunit_2026-10-13/`](isolated_phpunit_2026-10-13/) | `20261013T183459Z-99e603cb4713` | `d17854bd875a68ce23ed7ec9a87f87046f00512f` |
| [`audit/isolated_phpunit_2026-10-14/`](isolated_phpunit_2026-10-14/) | `20261014T063709Z-99e603cb4713` | `04c1fbf9abcfa6847575368e5761482a8345ff2b` |

The patch files themselves are at `d17854bd875a68ce23ed7ec9a87f87046f00512f` and unchanged by `04c1fbf9abcfa6847575368e5761482a8345ff2b`, which
touched only the runner's path handling.

The first set, under
[`audit/isolated_phpunit_2026-10-13/`](isolated_phpunit_2026-10-13/):

| file | what it holds |
|---|---|
| `summary.txt` | the three groups' counters, machine-readable |
| `mounts.txt` | mounts of the service container, from `docker inspect` |
| `mounts_phpunit.txt` | mounts of the container that ran PHPUnit — byte-identical to the above |
| `connections.txt` | every resolved connection and the pre-flight verdict |
| `constraints.txt` | the four re-created constraints as `pg_constraint` reports them |
| `db_ports.json` | published ports of the fixture database |
| `migration_deps.txt` | the dependency analysis at the tested SHA |
| `fixture_rows.txt` | what data the fixture actually held |
| `phpunit_failures.txt` | the full failing-test section of the suite output |
| `schema_fix_applied.diff` | the three patches' net effect on `database/migrations` |

The repeat run's set, under
[`audit/isolated_phpunit_2026-10-14/`](isolated_phpunit_2026-10-14/), is
`summary.txt`, `fixture_rows.txt`, `constraints.txt`, `phpunit_failures.txt`
and `failing_test_name_diff.txt`, the last of which carries the exact commands
used to compare the two runs' failing-test names.

The three patches themselves, and the proposal that explains them, are in this
same repository on the runner branch, at
`infra/contabo-test-runner` @ `d17854bd875a68ce23ed7ec9a87f87046f00512f`
(path handling later externalised at `04c1fbf9abcfa6847575368e5761482a8345ff2b`; the patches are identical in
both):

```
docker/test/schema_fix/README.md                          the proposal, with both promotion routes
docker/test/schema_fix/defer_four_fks.patch               defect 1
docker/test/schema_fix/align_permission_migration_to_v6.patch   defect 2
docker/test/schema_fix/portable_index_check.patch         defect 3
docker/test/schema_fix/restore_four_fks.sql               re-creates the four constraints
docker/test/schema_fix/verify_constraints.sql             asks the catalogue, raises if one is missing
docker/test/migration_deps.mjs                            the dependency analyser
docker/test/run_tests.sh                                  the runner
```

They are deliberately **not** on the integration branch: committing executable
files there would make the next push a mixed commit, and `paths-ignore` is
all-or-nothing over the whole push, so staging would rebuild.

---

## Group 1 — Isolation: 18 checks, 0 failed

Asserted from Docker's own view, never claimed. Both listings below are
byte-identical, one taken from the service container and one from **the
container that actually ran PHPUnit**:

```
bind <workspace> -> /app rw=true
```

Exactly one bind mount, and it is this run's disposable workspace. The guard
requires that mount by exact source and destination, requires the listing to be
non-empty, and requires the bind count to be exactly 1 — an unexpected host path
is a failure rather than an extra line nobody reads. It also asserts, by exact
string, that no mount references the live application directory, its
`storage`, its `bootstrap/cache`, its `public` or its `.env`.

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
  live server address    <private network address>
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
  target is inside the disposable workspace, and the isolation is proven from
  `docker inspect` rather than from the absence of an incident.
* **Staging smoke before and after**: `200` / `200` on
  `https://pruebas.camarapetrolera.app/`.
* **Live stack untouched**: the serving containers report `RestartCount=0` and
  start times of `2026-10-06T11:43:57Z` and `2026-09-22T20:55:52Z`, measured
  after the run.
* **No deployment was triggered by any of this work.** The most recent
  `Deploy a Contabo` workflow run is `004d24e9` of `2026-10-06T11:43:21Z`, which
  matches the serving container's start time; there have been none since. Every
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
2022_05_31_223828_create_empresas_table.php:36         needs cities                  (2022_07_28)
2022_08_12_145310_create_sustainabilities_table.php:19 needs areas                   (2022_08_15)
2022_09_01_154856_create_infratypes_table.php:18       needs infrasectors            (2022_09_06)
2022_09_01_185804_create_infrafacilities_table.php:18  needs infraregion_infrasystem (2022_12_01)
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
(21 files, listed by the runner into `pgsql_bootstrap_migrations.txt`). Laravel
sorts migrations by filename across every `--path`, so the dates reproduce the
historical order: base tables (2022–) → pgsql views (2026-08-31) → Phase 4
(2026-09-01) → the rework (2026-09-07) → the migrations that reference them
(2026-09-11+).

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
  together in order. Reproduce it from this repository:
  ```bash
  git checkout 99e603cb -- database/migrations
  for p in defer_four_fks align_permission_migration_to_v6 portable_index_check; do
    git show "infra/contabo-test-runner:docker/test/schema_fix/$p.patch" | git apply --check -
  done
  ```

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
marked as run and their views therefore absent — no longer applies to the views
themselves.

---

## Group 3 — The suite: it ran, and it is red

```
Tests: 419, Assertions: 2157, Errors: 5, Failures: 21,
       Deprecations: 1, Skipped: 2, Risky: 2       phpunit exit 2    57s
```

393 of 419 pass. For scale: before the schema was complete the same suite
produced 265 errors and 395 assertions, so the schema work moved assertions from
395 to 2157. The full failing section is committed as `phpunit_failures.txt`.

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

### One test is not deterministic, which only a repeatable harness could show

The repeat run at the same SHA, with the same preparation, produced **20
failures instead of 21** and 2160 assertions instead of 2157. Diffing the
failing-test names between the two runs isolates the whole difference to one
test:

```
only in the first run:
  Tests\Unit\Taxonomy\ReviewedProposalConfirmationTest
    ::invalidating_is_recorded_as_its_own_distinguishable_audit_event
```

Everything else is identical. **Checkable from the repository**, which verdict
`6162548587` finding 1 correctly required rather than prose: the repeat run's
`summary.txt`, `phpunit_failures.txt` and `failing_test_name_diff.txt` are
committed under
[`audit/isolated_phpunit_2026-10-14/`](isolated_phpunit_2026-10-14/), and the
name diff states the exact commands used to produce it.

Two runs are not enough to characterise the cause and none is claimed here;
they are enough to establish that the suite is not deterministic at this SHA. This is the first thing the isolated harness bought
that the shared database could not: against a database other work also writes
to, a test that changes verdict between runs is indistinguishable from data
drift.

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
   repository, and whether that branch should be merged.
   `docker/test/schema_fix/README.md` on the runner branch gives both promotion
   routes; neither is applied.
2. **The suite depends on data, not only on schema.** Seven tests say so
   themselves. A green full suite in isolation needs a data load — factories,
   seeders written for the purpose, or an anonymised extract. Authoring that
   data is outside a minimal correction and is the owner's call.
3. **`db:skip-mysql-only-view-migrations` cannot run on a new database**,
   although its own docblock says to run it once per new pgsql database: it
   reads `select max(batch) from migrations` before anything creates that table.
   The runner calls `migrate:install` first. On an existing database this is
   invisible.
4. **One test is not deterministic.** Two runs at the same SHA with the same
   preparation differ by exactly one:
   `ReviewedProposalConfirmationTest::invalidating_is_recorded_as_its_own_distinguishable_audit_event`
   failed in the first and passed in the second. Not characterised — two runs
   cannot do that — but established, and only visible because the harness is
   repeatable.
5. **`.gitignore`'s blanket `*.sql` silently excluded the bootstrap SQL.**
   `git add` reported nothing and the run failed on the host with
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

## Executor state at delivery

Unrelated to the test runs, recorded because the infrastructure acceptance asks
for it.

* **Deployed version, proven byte-identical** rather than asserted: all ten
  modules in the executor's install directory have `git hash-object` blob ids
  equal to those committed at
  `SISTEG-Agent-Orchestrator@64ecc4bfb1bd22715f79371ea3e2d6c99272a40d`
  (`feature/cpv-executor-contabo`, pushed), including the two modules changed
  this round. The runs reported above were served by
  `b126bc11e9f2d927c616b7230c98b316ab7786e2`; `64ecc4bf` changes only how
  evidence is assembled for reviews and nothing about how tests run.
* **Cadence**: `cycle_complete` every 120 s to the second
  (…17:29:51, 17:31:51, 17:33:51, 17:35:51, 17:37:52, 17:39:51Z), 1.3–2.1 s per
  cycle, `health: ok`, service `active` and `enabled`.
* **Counters** from the executor's durable state: 6 review requests before this
  round, 6 distinct markers, `attempts=1` on every one, and the hourly review
  budget totalling 6 — one model call per request, no retries, no duplicates.
  Every marker the executor has ever published maps to exactly one comment;
  the seven pairs are recorded in `validate_handoff.mjs` beside the check that
  relies on them.
* **Fault injector disabled**: `CPV_FAULT_INJECT` appears in no unit file, no
  file in the executor's config directory, and not in the unit's `Environment`.
* **Evidence truncation, disclosed and then fixed.** It grew across rounds:
  14361 characters dropped reviewing `650db29c`, then 30780 reviewing
  `20d3c12d` against a 220000 budget. Because truncation took the tail, by the
  second round it had swallowed the diff of the very file a finding was about,
  and verdict `6162548587` recorded that it could not verify the correction it
  had asked for — including the gate's new exit-2 behaviour.

  Trimming is now by priority rather than position, in
  `SISTEG-Agent-Orchestrator@64ecc4bf`: the recent-thread section is the only
  one that excerpts material a reviewer can fetch for itself, so it is dropped
  first, with a note saying so and a log line recording it, while the request,
  governance, handoff and the full diff stay. The tail truncation remains as a
  last resort, which makes it rare instead of normal.
