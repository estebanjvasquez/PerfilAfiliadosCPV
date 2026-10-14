# Read-only staging preflight for CPV-INTEGRATION-CANDIDATE-0010

- **Instruction:** Issue #2, comment `6174401867` (orchestrator manual review: candidate PASS, live preflight next)
- **Candidate accepted:** REVIEW_HEAD `b384e1ad1f6ee360b36bc79bd638a5950aecb3a5`, TESTED_HEAD `6c25bb3b55f2c8b825da5246fb40a2235c557e81`
- **Preflight executed from:** `cec3b48d1bce62dc09ce1816f2ad5953523e01ba`. The delta from `b384e1a` touches only `docs/deploy/` (the v2 SQL file and its runner), so the runtime code checked here is the accepted code.
- **Branch:** `feature/integration-candidate-0010`. The deploy workflow does not watch it. `feature/upgrade-filament-v3` is unchanged at `a6e9db4`.
- **Outcome:** **COMPATIBLE.** 13 blocks ran with 51 OK, 21 INFO, 14 BASELINE and **0 stops**. The runner exited 0 and the transaction ended in ROLLBACK.

Nothing was deployed or pushed to the runtime branch. Nothing was written to the ledger, the schema or the data.

---

## 1. What was run, against what

| | |
|---|---|
| Target | The database that staging's `pgsql` connection resolves. The runner executed inside the running app container, so it used the app's own resolved configuration. |
| Identity | `current_database()` = `postgres`, matching `database.connections.pgsql.database` (`true`). The schema is `public` and `search_path` is `public`. `app_env` = `staging`. The default connection is `pgsql`, so the ledger read is the one `migrate --force` uses. |
| Engine | PostgreSQL **17.6** on staging. Note that the integrated suite ran on the 16.15 fixture (see §6). |
| Read-only | The transaction was opened `READ ONLY` and checked with `SHOW transaction_read_only` = `on` before the first block. Block 1 read it again inside the same transaction. PostgreSQL enforces this setting, not the script. The transaction always ends in `ROLLBACK`. |
| Inputs | SQL `sha256 d70bb7a1ce284b66a6cf6303be7d6a4a9ba43832e307128c496cd5e831f69e3a` and runner `sha256 562d852c67702e5f9b51b1c4338de6daca555efc060751865678b1d8d4be09f7`. Both were taken with `git show` from `cec3b48` and match the committed files. |
| Executed | `2026-10-14T17:47:08Z` |
| Staging around the run | HEAD `f8a98df` before and after. App container `e26ae9260fc1` before and after (not restarted). HTTP 200 before and 200 after. |

**What staging runs.** Three different SHAs are involved, and none of the differences between them touches runtime code:
- The **running image** was built by the last "Deploy a Contabo" run (2026-10-06T11:43Z, success) from `004d24e`. The app container has been up 8 days.
- The **checkout** in the app directory is at `f8a98df`. It is a descendant of `004d24e` that differs in 6 `docs/`/`audit/` files. It moved without a deploy run, and this round did not move it.
- The **candidate's base** is `a6e9db4`.

`git diff 004d24e a6e9db4` and `git diff f8a98df a6e9db4`, both limited to paths outside `docs/` and `audit/`, are **empty**. The code running on staging is therefore the candidate's base, and the ledger read here is the one the next `migrate --force` will see.

### How `\echo` was handled

`\echo` is a psql meta-command, not SQL, and the app image does not include psql. v2 of the file has no `\echo`. Each block now starts with a comment marker, `-- @block <id> | <title>`, and contains exactly one `SELECT`/`WITH`. psql reads the markers as comments. The runner (`docs/deploy/run_readonly_preflight.php`) splits the file on the markers and sends each statement on its own as plain SQL.

### Runner guarantees (`docs/deploy/run_readonly_preflight.php`)

1. **Hash check.** The runner refuses SQL whose sha256 differs from the declared one.
2. **Statement guard.** Before any connection opens, each block must be a single statement starting with `SELECT`/`WITH`. Literals and comments are removed, and the remaining text must not contain any write or session-control word. A dry run rejected all of these: `DELETE`, `SELECT 1; DELETE`, a data-modifying CTE, `setval`, `SELECT … INTO` and `UPDATE`.
3. **Read-only transaction.** Everything runs in one READ ONLY transaction, checked with `SHOW`, and ends with `ROLLBACK`.
4. **Exit codes.** A SQL error stops the run with exit 5. Any verdict other than `OK`/`INFO`/`BASELINE` is a stop, exit 6. Any other exception exits 1, never 0.
5. **No secrets.** No credential passes through the runner. Configuration is already inside the container, and the SQL arrives in an environment variable, so no file was written into the live container.

### First attempt: died before any query (recorded, not hidden)

The first live attempt from `7c72d2c` failed on its first output line. When PHP reads the script from stdin, PHP CLI does not define the `STDOUT` constant. The failure happened before `BEGIN`. The `pgsql` connection is lazy and had not been opened, so **no query and no connection reached the database**. Laravel's error handler still let the process **exit 0**. That is the more serious defect, because a preflight that fails silently looks like a pass.

Fixed in `cec3b48`: the runner writes to `php://stdout`/`php://stderr`, opens the transaction inside the `try`, and maps any exception to a non-zero exit code. The failed attempt's output is kept under `live_preflight/attempt1_runner_died/`.

### Fixture dry run first

Before the live run, the v2 SQL ran in the isolated harness (`infra/contabo-test-runner @ 5f63181`, run `20261014T174247Z-7c72d2cdbd85`, `--skip-suite --preflight`) under `psql -v ON_ERROR_STOP=1`. All 13 blocks ran without SQL error. Isolation was 17/17, schema prep 16/16 and fixtures 8/8, staging returned 200/200, and no `cpvtest-*` container was left. The fixture's stops are the expected ones for a freshly built database where both new migrations already ran: block 1 is not read-only under plain psql, and block 4 finds the objects already there. The fixture output is attached for syntax evidence only. **It is not used as evidence about staging.**

---

## 2. Results against the instruction

### Point 1: DB identity, schema, read-only
Block 1: **OK** (see §1).

### Point 2: FK block 5 with the exact referenced schema and column

`conkey`/`confkey` are resolved to column names through `pg_attribute`, and schemas through `pg_namespace`:

| Table.column | Constraint (Laravel name) | Local columns | References | Actions del/upd | Validated | Keys on column | Verdict |
|---|---|---|---|---|---|---|---|
| empresas.city_id | `empresas_city_id_foreign` ✓ | city_id | `public.cities(id)` | a / a | true | 1 | OK, the migration will skip it |
| infrafacilities.infraregion_infrasystem_id | `…_foreign` ✓ | infraregion_infrasystem_id | `public.infraregion_infrasystem(id)` | a / a | true | 1 | OK, the migration will skip it |
| infratypes.infrasectors_id | `…_foreign` ✓ | infrasectors_id | `public.infrasectors(id)` | a / a | true | 1 | OK, the migration will skip it |
| sustainabilities.areas_id | `…_foreign` ✓ | areas_id | `public.areas(id)` | a / a | true | 1 | OK, the migration will skip it |

The table schema, referenced schema, referenced column (`id`) and NO ACTION semantics all match what `2022_12_02_000000_restore_deferred_foreign_keys` intends. None of the keys is missing, so the "missing FK" stop does not apply.

**Block 5b: orphans, as aggregate counts only.**

| Relation | Non-null values | Orphans |
|---|---|---|
| empresas.city_id → cities.id | 383 | **0** |
| infrafacilities.infraregion_infrasystem_id → infraregion_infrasystem.id | 1215 | **0** |
| infratypes.infrasectors_id → infrasectors.id | 6 | **0** |
| sustainabilities.areas_id → areas.id | 280 | **0** |

**Where the live keys come from.** The ledger has `2026_08_31_154307_add_deferred_foreign_keys_out_of_order_tables` (batch 1) and the candidate has no file for it (block 2c). That migration came from the supplhi branch and is how these four keys reached the live database. Their names are exactly the ones Laravel generates.

**Observation, not acted on.** The migration's `satisfyingForeignKeyExists()` compares the local column, referenced table, referenced column and actions. It does **not** compare `foreign_schema`, which Laravel 12.69's `Schema::getForeignKeys()` does return. A key with the same table name in another schema would therefore pass that check. On staging the referenced schema is `public` for all four, so this has no effect here. The preflight's block 5 is now the guard for it. The instruction says this is not an application-code round, so the migration was not changed. It can be hardened in a later round if the reviewer wants that.

### Point 3: migration registry

**The seven imported creates: all registered, batch 15.** `2026_09_01_120000` … `120006`.

There is a fact the earlier plan did not state. The seven files live in `database/migrations/pgsql/`. The deploy's `migrate --force` does **not** scan that folder: Laravel globs `*_*.php` without recursion, and nothing in the app calls `loadMigrationsFrom()` for that folder. The deploy would not attempt them even if they were unregistered. They are registered anyway, so either way nothing happens to them. The harness scans the folder explicitly with `--path`.

**The six edited migrations: all registered (batch 1).** The edits are inert on this database.

**The complete pending set for `migrate --force` (block 2b) contains exactly the two new migrations:**
- `2022_12_02_000000_restore_deferred_foreign_keys`
- `2026_10_09_120000_create_taxonomy_category_requests_table`

None of the 15 MySQL-only view migrations is pending. All are registered, so `db:skip-mysql-only-view-migrations` will insert nothing.

**Ledger rows with no file in the candidate (block 2c): 21, listed as INFO.** `migrate` ignores them. They are listed so that nothing is hidden:
- `2022_08_03_000000_create_vector_extension`, `2026_08_31_150839_create_jobs_table` and `2026_08_31_154307_add_deferred_foreign_keys_out_of_order_tables` (batch 1)
- 13 `2026_08_31_16xxxx/18xxxx_create_*_view_pgsql` (batches 2–14): the PostgreSQL versions of the views
- 5 `2026_09_19_1007…1011` taxonomy intent and semantic-analysis migrations (batch 51), registered by another branch with no file here

Arithmetic check: 127 ledger rows = 98 registered files from `database/migrations` + 8 from `pgsql/` (the 7 imports and `2026_09_07_090000_rework…`) + 21 with no file.

**Singleton and new objects (blocks 4, 4a, 4b, 4c):**
- `taxonomy_category_requests`, its sequence, `_pkey`, `_empresa_idx` and `_status_idx` are **absent**. So is the column `taxonomy_selection_settings.category_request_recipient_email`.
- `taxonomy_selection_settings` exists with **one row**. Its shape is exactly the one `2026_09_15_090000` creates: `id bigint NOT NULL`, `max_categorias_principales/secundarias smallint NULL` and `created_at/updated_at timestamp NULL`. It has no extra columns.
- The targets of the new foreign keys, `empresas.id` and `users.id`, are `bigint` and each is covered by its own PK.

### Point 4: stops
No PARAR, no ATENCION, no SQL error, no identity or schema mismatch, no missing prerequisite (block 6: 10/10 present) and no missing or unexpected FK.

### Point 5: preserved output and baseline (block 7)

| Table | Rows |
|---|---|
| empresa_taxonomy_category | 767 |
| empresas | 406 |
| migrations | 127 |
| migrations.max_batch | 58 |
| taxonomy_audit_log | 163 |
| taxonomy_candidate_concept_links | 10 |
| taxonomy_canonical_concepts | 82 |
| taxonomy_categories | 3497 |
| taxonomy_reviewed_proposals | 15 |
| taxonomy_selection_settings | 1 |
| taxonomy_term_concepts | 145 |
| taxonomy_term_cpv_relations | 9749 |
| taxonomy_terms | 1893 |
| users | 366 |

The output contains catalog facts, migration names and aggregate counts only. It has no customer rows, credentials, hosts or connection strings.

---

## 3. What the deploy's migrations would do on this database (documented DDL impact)

From the facts above, and **not executed**:

1. **`db:skip-mysql-only-view-migrations`**: all 15 rows are already present, so it makes no insert.
2. **`2022_12_02_000000_restore_deferred_foreign_keys`**: reads the catalog, finds four satisfying keys, issues **no DDL** and adds one ledger row.
3. **`2026_10_09_120000_create_taxonomy_category_requests_table`**:
   - creates an empty table with two indexes and FKs to `empresas(id)` and `users(id)`. Creating those FKs takes a short `SHARE ROW EXCLUSIVE` lock on `empresas` and `users`. The new table is empty, so there is nothing to validate.
   - runs `ADD COLUMN … VARCHAR(255) NULL` with no default on the one-row singleton. This is a catalog-only change with no table rewrite, under a short `ACCESS EXCLUSIVE` lock on the singleton.
4. **Expected afterwards:** `migrations` = 129 and `max_batch` = 59. All other block-7 counts stay the same. The exception is legitimate application activity between now and the deploy, which could add rows to `users`, `empresas` or the audit log. That is why the plan re-runs block 7 just before the deploy.

---

## 4. Proposed staging execution (NOT authorized here, for the orchestrator to record)

1. Re-run this same preflight (same files, same hashes) immediately before deploying, and stop on any change.
2. Do a non-force update of `feature/upgrade-filament-v3` to the accepted candidate head. That is a fast-forward, because the candidate was created from `a6e9db4` and the runtime branch has not moved. Let `deploy-contabo.yml` run.
3. Verify the deployed SHA and the workflow run, then: the migrate log shows exactly the two migrations; `migrations` is 129; the four FKs are unchanged (block 5); `taxonomy_category_requests` exists and is empty; the protected counts match the pre-deploy baseline.
4. UI checks: the app and the manual page load, the five final labels appear on the affiliate form, and there are no 500s. **Do not submit the request form on staging** unless recipient handling has been made explicit. A real submission sends mail to the configured recipient.

---

## 5. Guardrails kept
- No push to `feature/upgrade-filament-v3`, no deploy and no workflow run. The last "Deploy a Contabo" run is still the one from 2026-10-06.
- No change to the ledger, schema or data on any shared database. The only statements were the SELECTs above, inside a READ ONLY transaction.
- Nothing was written into the live app directory or the live container. The inputs were staged in a directory outside the app.
- No secret was read into the conversation, printed or committed. Only `DB_CONNECTION` and `APP_ENV` were read from `.env`.
- No taxonomy APPLY, no change to proposals #420–#422, the frozen payloads or the Hyperdrive config. No MySQL replay, view rebuild or SMTP send.
- One foreground session, no subagents, no polling, no automated reviews.

## 6. Limitations
- **Engine version.** Staging runs PostgreSQL 17.6 and the integrated suite ran on 16.15. Both pending migrations use plain DDL (`CREATE TABLE`/`INDEX IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`, catalog reads through Laravel), with nothing version-specific known. The suite still did not run on 17.
- **Snapshot.** This is the state at 17:47Z. Step 1 of §4 exists for that reason.
- **Handoff validator.** `docs/orquestador/validate_handoff.mjs` fails one check (`ids dropped without retention: 6080004466`). It fails the same way at the accepted `b384e1a`, so this round did not cause it. The rule belongs to the retired autonomous review loop and was left alone as out of scope.

## 7. Files
- `docs/deploy/preflight_integration_candidate_0010.sql`: v2, the 13 blocks
- `docs/deploy/run_readonly_preflight.php`: the read-only runner
- `audit/integration_candidate_0010/live_preflight/attempt2/`: **the live result**: `output.txt`, `exit.txt`, `inputs.sha256`, `context_before.txt`, `context_after.txt` and an empty `stderr.txt`
- `audit/integration_candidate_0010/live_preflight/attempt1_runner_died/`: the failed first attempt (no query reached the database)
- `audit/integration_candidate_0010/live_preflight/fixture_dryrun/`: the harness dry run (`preflight_output.txt`, `summary.txt` and an empty `preflight_mutation_scan.txt`)
- `audit/orchestrator_handoff.json`: the `integration_candidate.live_preflight` entry
