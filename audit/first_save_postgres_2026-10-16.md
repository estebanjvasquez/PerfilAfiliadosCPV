# CPV-FIRST-SAVE-POSTGRES-COMPATIBILITY: the first save of the five profile modules works on PostgreSQL

- **Authorization:** Issue #2 `6212967641` (owner, 2026-10-16 09:57Z). It covers implementation and isolated validation. Deployment is **not** authorized.
- **Prerequisite:** `fix/model-compatibility-labels` @ `0895258` (accepted in `6212784682`).
- **Branch:** `fix/first-save-postgres`.
- **BASE_SHA:** `0895258670f0bbf18476a33d20c361b674b471d5`. It contains the accepted EmpresaPgsql/labels repair and the continuity startup files.
  - Verified before branching (10:02Z, read-only): the remote `feature/upgrade-filament-v3` and the staging checkout are both at `0f8bdad`, with 0 modifications.
  - No other local work was touched. The worktrees are untouched.
- **Correction round:** Issue #2 `6214463281` (manual review of delivery `6213980289`, CORRECTIONS_REQUIRED): failure-path atomicity. See §12.
- **TESTED_HEAD:** `b9b41a0ee6dcef0323a52537cfe067dd2a187f8a`, which includes this correction. Focused, full-suite and browser runs were all made at this SHA (§12).
  - The first delivery's TESTED_HEAD was `fea5355`; the full suite then ran at `e67c3ca`. That history is kept in §7.
  - Later commits are docs/audit only.
- **REVIEW_HEAD:** the commit that adds this report revision.
- **Deployed:** NO.

## 1. Investigation: exact SQL and event paths

| Model | Connection | Hook before the fix | Unique index (staging, read-only `pg_indexes`) |
|---|---|---|---|
| `Management` | default (`pgsql` on staging) | `created`: `update management set management.user_id = ? where empresa_id = ?`, then `EmpresaModuleStatus::setStatus(empresa, gestion, false)` | `(empresa_id, user_id)` |
| `Asset` | default | same pattern on `assets`, then status `recursos` | `(empresa_id, user_id)` |
| `Experience` | default | same pattern on `experiences`, then status `experiencias` | `(empresa_id, user_id)` |
| `Presence` | default | same pattern on `presences`, then status `presencia` | `(empresa_id, user_id)` |
| `Sustainability` | default | same pattern on `sustainabilities`, then status `sostenibilidad` | none |

- **One observer:** `EmpresaCompletionObserver` (`AppServiceProvider::boot()`) listens to `saved`/`deleted` on all five models and recomputes `empresas.completion_percentage`. Before the fix, the exception in `created` stopped `saved` from running, so the cache was also left stale.
- **Other occurrences:** each file also has older variants of the same statement inside `/* … */` comments. Only one statement per model is active.
- **Staging's default connection is `pgsql`** (`.env`, checked by name only).

**Defects:**
1. **The SQL is rejected.** PostgreSQL rejects a table-qualified SET target with `SQLSTATE[42703] column "<table>" of relation "<table>" does not exist`.
2. **It is not atomic.** `created` fires **after** the INSERT, and with no enclosing transaction the INSERT has already committed by then. Neither Filament table actions nor the panel use a transaction: `CanUseDatabaseTransactions` defaults to false, and only page actions call it. So the first save fails **after** leaving a committed partial row: `user_id` NULL, and the module's «No aplica» is not cleared.
3. **The row scope is too wide.** `where empresa_id = ?` rewrites the actor of **every** row of the company, not just the created one. This matters for Sostenibilidad, which has several rows per company.

**Who creates what (the real UI actions):**

| Module | Tab / action | First-create path | Update path | Cardinality |
|---|---|---|---|---|
| Sistemas de gestión | 6 header actions: Calidad, Ambiente, Credibilidad y transparencia, Seguridad, Gestión de proyectos, Seguridad de la información, plus module «No Aplica» | `saveFieldsWithNoAplica` → `saveFields`: `management()->first()` or `create([])`, then `update($data)` | same helper, `update` | 1 row per company |
| Recursos en Venezuela | 4 header actions: Recursos humanos, Maquinaria, Instalaciones, Inventario (repeaters), plus «No Aplica» | `saveFieldWithNoAplica` → `saveField`: `assets()->first()` or `create([])`, then `update([field])` | same helper | 1 row |
| Experiencia relevante | Agregar experiencia; row actions Editar and Eliminar; «No Aplica» | `saveEntry`: `experiences()->first()` or `create(['exp_year'=>[]])`, then `update(exp_year)` | `saveEntry(key)`, `deleteEntry(key)` | 1 row; experiences are UUID-keyed entries in `exp_year` |
| Presencia internacional | Filament `CreateAction` (a wizard with offices and international-experience repeaters), `EditAction`, `DeleteAction`, «No Aplica» | `presence()->create($data)` (Filament) | `EditAction` | 1 row («Crear» is hidden once it exists) |
| Enfoques de sostenibilidad | Filament `CreateAction` (Area, Status), `DeleteAction`, «No Aplica» | `sustainabilities()->create($data)` | none (create/delete) | several rows |

## 2. Fix

**Models** (`app/Models/{Management,Asset,Experience,Presence,Sustainability}.php`):
- the active `DB::update(...)` is removed;
- the actor is assigned in a new `creating` hook, `$item->user_id = Auth::id();`, so it travels in the **INSERT of the created row itself**;
- the `created` hook keeps `EmpresaModuleStatus::setStatus(..., false)` unchanged.

What this preserves and changes:
- **Same column and actor:** `Auth::id()`.
- **Same connection:** the model's default; no raw SQL is left.
- **Row scope:** narrowed to the created row. For the four one-row modules, the effect is identical. For Sostenibilidad, an older row no longer gets the newest creator's id.
- **Unique `(empresa_id, user_id)`:** a conflict, if any, now happens at the INSERT and leaves nothing behind. No new failure path appears, because one-row modules never insert a second row.

**Narrow transactions** (only where one user action runs several statements):
- `ManagementRelationManager::saveFieldsWithNoAplica`, `AssetsRelationManager::saveFieldWithNoAplica` and `ExperiencesRelationManager::saveEntry` wrap section status + first insert (with its hook) + update in `DB::transaction`.
- *(Corrected in §12.)* The first delivery left the Filament `CreateAction`s of Presencia and Sostenibilidad without a transaction and called them «a single INSERT». That was wrong. After the INSERT, they also run the `created` status hook (a DELETE on `empresa_module_status`) and the `saved` completion refresh (an UPDATE on `empresas`). Since `b9b41a0` they use Filament's action transaction. There is still no application-wide transaction rewrite.

**Found while covering the Experiencia update path, and fixed** (`ExperiencesRelationManager::buildRow`):
- The virtual table rows use the entry's UUID as `id`, but `Experience` casts its key to `int`. So `getKey()` turned «9f3c…» into 9 and «e1…» into 0, and two entries could share a key; **Editar/Eliminar could act on the wrong experience**.
- The virtual row now uses `setKeyType('string')` and `setIncrementing(false)`.
- Evidence before this fix: `audit/runs/first_save/key_collision/phpunit.txt`. At `1646726` (models fixed, key not), an edit addressed by UUID silently did nothing (`'2021'` expected, `'2020'` kept).

**Not done:**
- no skipped events, no `withoutEvents`/`createQuietly`, no catch-and-ignore;
- no change to the default connection;
- no schema change, migration, backfill, deletion or actor reassignment.

Diff: `git diff 0895258..e67c3ca -- app` covers the 5 models and 3 relation managers.

## 3. Coverage matrix

All test rows are created through the **real** relation-manager actions (Livewire), with **real events**, on disposable PostgreSQL with synthetic users, companies and catalogs. The only raw inserts are the catalogs (`tests/Feature/Filament/Concerns/ProfileModuleFixtures.php`). Tests:
- `FirstSaveModulesTest` (16, transactional);
- `FirstSaveAtomicityTest` (15, **non-transactional**, cleans up after itself): 1 success/retry test, 10 injected post-INSERT failures and 4 injected update/delete failures (§12);
- `NewAffiliateOnboardingTest` (1).

| Module | First-create action (company with no row) | Options exercised | Update path | Permissions | Result | Evidence |
|---|---|---|---|---|---|---|
| Gestión | Calidad (ISO 9001 ✓, ISO 17025 ✗, Otros ✓ + repeater name) | then the 5 other sections: Ambiente, Credibilidad, Seguridad (OVID + Otros repeater), Proyectos, Seguridad info; unchecked values; section «No Aplica» on and reverted; module «No Aplica» cleared by the first save; Otros with an empty name rejected | the same row is updated; sibling sections are preserved; the actor is unchanged; repeat click → still 1 row | affiliate with Shield `*_management`; a user without them → `HttpException` 403; admin through its role | **pass**: 1 row, `user_id` = actor, all fields persisted | `FirstSaveModulesTest::management_*` (5 tests); browser «Gestión» steps |
| Recursos | Recursos humanos (repeater: type, junior/medium/senior) | then Maquinaria, Instalaciones, Inventario; a missing type is rejected without writing; Maquinaria «No Aplica» on (data kept), then reverted with new values | 4 types → the same row; values per type persisted | `*_asset` | **pass** | `assets_*` (2 tests); browser «Recursos» |
| Experiencia | Agregar experiencia (year, infrastructure sector, description) | a 2nd experience (a 2nd **entry**, not a row); Editar one entry (the other untouched); Eliminar exactly the chosen one; a missing year is rejected | entries inside one row | `*_experience` | **pass** (after the key fix) | `experiences_*` (2 tests); browser «Experiencia» (2 entries, edit) |
| Presencia | Crear (offices ✓ + repeater with country/surface/employees/active; international experience ✓ + repeater with role/amount/clients) | «Crear» hidden afterwards; Editar (offices off, experience changed); a missing required role/amount is rejected | `EditAction` on the same row; actor unchanged | `*_presence`; the admin's first create is attributed to the admin | **pass** | `presence_*` (2), `an_admin_first_save_*`; browser «Presencia» |
| Sostenibilidad | Crear (area, status) | a 2nd row by **another user of the same company** keeps each row's own actor; a missing status is rejected; several rows remain possible | create/delete only | no policy exists in the app (none required) | **pass**: 2 rows `[owner, colleague]` | `sustainability_*`; browser «Sostenibilidad» (2 rows) |
| Isolation | — | 2 companies, 2 users: A's save does not touch B; B's edit page returns **404** for A; A's own page returns 200 | — | company visibility through the `empresa_user` link (`EmpresaResource::getEloquentQuery`) | **pass** | `two_companies_do_not_leak_*` |
| No session | — | `management()->create()` with no authenticated user → row created with `user_id` NULL, no user invented (the column is nullable; the UI always has a session) | — | — | **pass** | `without_a_session_*` |
| Atomicity / retry | all 5 first saves, with the module «No aplica» set beforehand | — | retry: one-row modules stay at 1; Sostenibilidad goes to 2, which is legitimate | — | **pass**: exactly 1 committed row with the actor, «No aplica» cleared, the cached `completion_percentage` equal to a fresh computation | `FirstSaveAtomicityTest` (success path) |
| Failure after the INSERT | all 5 first saves × 2 injected steps (after the `created` status hook; after the completion write) | other company with its own row and «No aplica» | Presencia edit and delete, Sostenibilidad delete, Experiencia eliminar, each failing after the completion write | — | **pass at `b9b41a0`**: nothing committed, «No aplica» and the cached completion unchanged, the other company unchanged, then a clean retry. Red before the correction for the 4 Presencia/Sostenibilidad cases and the 4 update/delete cases | `FirstSaveAtomicityTest` (§12) |
| Previous repair | EmpresaPgsql relations, OVID/ISO labels, request #1 flow, manual/map/home | — | — | — | **pass** | `EmpresaPgsqlCompatibility`, `ManagementLabels`, `TaxonomyCategoryRequest`, `TaxonomyCategoriesRelationManager`, `UserManual`, `ProfileHelpWidget`, `HomeBranding`, `CompanyCrawl` (focused run) |

## 4. Reproduction before the fix, and atomicity

Pre-fix SHA `009e088` contains the tests and the original models (`audit/runs/first_save/prefix/phpunit.txt`): 18 tests, 10 errors and 4 failures.
- **Every first save** fails with `SQLSTATE[42703] … column "<table>" of relation "<table>" does not exist`, at `app/Models/<Model>.php` (the `DB::update` line).
- **`FirstSaveAtomicityTest`, without a transaction, as in the real app:** each of the 5 modules threw and left **`rows left committed=1 user_id=[null]`** with **the module «No aplica» still set**.
- **After the fix:** each first save commits exactly one row with the actor and clears «No aplica». A retry does not duplicate one-row modules.

## 5. New affiliate (isolated)

`NewAffiliateOnboardingTest` covers:
- a user created as registration leaves it (unverified);
- the email verification is **simulated** with `markEmailAsVerified()` and `Mail::fake()`; nothing is sent, and SMTP is not configured on staging;
- Shield permissions granted as the role would grant them (a declared assumption);
- the user then creates their company through the **real `CreateEmpresa` page** (RIF, name, city, sector, status), and `Empresa::created()` links them;
- the edit page returns 200;
- **all five first saves succeed**, each with one row attributed to the new user;
- another user's company returns 404;
- no mail goes out.

**Finding (pre-existing, not fixed, out of scope):**
- An **unverified** user who opens the panel gets **HTTP 500** (`Route [verification.notice] not defined`). The panel's `authMiddleware` uses Laravel's `EnsureEmailIsVerified`, but the panel has no `->emailVerification()`, so that route is missing.
- The test only asserts that such a user does not get in.
- **Fix candidate** for a separate round: enable Filament's email verification or point the middleware at an existing route. It matters as soon as registration mail is activated.
- **Release blocker (`6214463281`):** this candidate is **not** ready for full new-affiliate onboarding while that 500 exists. The test bypasses verification on purpose.
  - The real flow, registration → verification → panel, is a separate next task. SMTP is still pending.
  - The owner decides whether to authorize it. The module repair can be reviewed on its own.

## 6. Browser (runner `infra/contabo-test-runner` @ `a7c6673`, `ux_check_first_save.mjs` + `ux_seed_first_save.php`): **40/40**

- **Starting state:** the seed asserts that the synthetic company has **no** row in any of the five modules (`module rows before={"management":0,…}`).
- **Affiliate, desktop light, through the real forms:**
  - **Gestión:**
    - Calidad with Otros and an empty name is rejected with visible feedback (native constraint validation, «Please fill out this field.»), and the modal stays open;
    - with the name filled, the first save works and the summary shows ISO 9001 and the name;
    - Seguridad and OVID are saved, and Calidad is still checked;
    - Ambiente «No Aplica» persists, and reverting it plus ISO 14001 persists.
  - **Recursos:** first save of Recursos humanos, then Inventario into the same record; the summary shows both.
  - **Experiencia:** a missing year is rejected; two experiences are added; editing one changes only that one.
  - **Presencia:** first create through the wizard; one record, and «Crear» is hidden; the edit with international experience saves.
  - **Sostenibilidad:** a missing status is rejected; two creates give two rows, with a «Creado» notification each time.
- **Admin:** sees and updates the same company (Gestión de proyectos PMI persists).
- **Affiliate and admin in desktop dark, mobile light and mobile dark:** the saved data is visible (2 sustainability rows, ISO 9001 ticked); the modals open with no page overflow.
- **Screenshots:** `audit/runs/first_save/ux/` (22 files).

## 7. Tests

| Run | SHA | Result | Evidence |
|---|---|---|---|
| Before the fix (expected red) | `009e088` | 10 errors and 4 failures, all 42703 / partial rows or test setup | `prefix/` |
| Models fixed, experience key not yet | `1646726` | 17/18; the edit-by-key no-op exposed the key collision | `key_collision/` |
| **Focused** (`FirstSave\|NewAffiliateOnboarding\|ManagementLabels\|EmpresaPgsqlCompatibility\|TaxonomyCategoryRequest\|TaxonomyCategoriesRelationManager\|UserManual\|CompanyCrawl\|ProfileHelpWidget\|HomeBranding`) | `e67c3ca` | **OK (93 tests, 1,090 assertions)** | `focused/` |
| Full, first attempt | `e67c3ca` | aborted: PHP **memory_limit 128M exhausted** after 428 passing tests (a harness limit: the previous full run already peaked at 123 MB) | `full_oom/` |
| **Full isolated suite** (harness `457d445`: phpunit with `-d memory_limit=512M`; the project's `phpunit.xml` is untouched) | `e67c3ca` | **OK (533 tests, 3,484 assertions)**, peak 135 MB | `full/` |
| Focused first-save tests, with the completion-cache assertion | `fea5355` (test-only change over `e67c3ca`) | **OK (18 tests, 332 assertions)** | `focused_completion/` |
| Correction `6214463281`: before / focused / Feature / **full** / browser | `19a67bb` / `b9b41a0` | 8 expected failures / OK 107 / OK 218 / **OK 547** / 7/7 | §12, `atomicity/` |

**Reproduce:**
- `run_tests.sh <sha> --suite Feature --filter "<pattern>"`;
- `run_tests.sh <sha>` for the full suite;
- `run_tests.sh <sha> --skip-suite --ux-script ux_check_first_save.mjs --ux-seed ux_seed_first_save.php` for the browser check.

Each runs on the VPS in `/root/cpv-runner` with `CPV_TEST_ROOT` set (see `ENVIRONMENT.md`).

## 8. Historical data (read-only diagnosis, nothing executed)

`docs/deploy/diag_first_save_null_actor_readonly.php` (READ ONLY transaction, aggregate counts only) → `audit/runs/first_save/staging_null_actor_readonly.txt`:

| Table | rows | NULL actor | NULL actor since 2026-09-01 | companies |
|---|---|---|---|---|
| management | 99 | 0 | 0 | 99 |
| assets | 114 | **1** | 0 | 113 |
| experiences | 94 | 0 | 0 | 94 |
| presences | 53 | 0 | 0 | 53 |
| sustainabilities | 280 | 0 | 0 | 96 |

- **No partial-row signature since the cutover window.** No remediation is needed for this defect.
- **Two historical anomalies, older than September, proposed for a manual review only:** one `assets` row with a NULL actor, and one company with 2 `assets` rows in a one-row module. No action was taken.

## 9. Manual

No instruction changes: same buttons, same fields, same steps. This is a reliability repair that keeps the existing steps, so `resources/manual/manual_usuario.md` is unchanged. The accepted label content («ISO 45001 u OVID») and PDF-baseline coverage are preserved (`UserManualContentTest` and `ManagementLabelsTest` are green).

## 10. Integration and live data

- **One candidate:** `fix/first-save-postgres` contains `0895258` (EmpresaPgsql + OVID/ISO labels + continuity startup files and archives) plus this fix.
- **Not done:** no push to `feature/upgrade-filament-v3`, no merge to `main`, no deployment.
- **Live:** no business, taxonomy, mail or secret change on live systems. The staging reads were the index catalog and the aggregate diagnosis, both in READ ONLY transactions.

## 11. Limitations

- Not verified on staging, because it is not deployed.
- The browser and admin checks use Shield permissions granted inside the disposable stack. Production role permissions were not inspected.
- The unverified-user 500 (§5) and the two historical asset anomalies (§8) are reported, not fixed.
- **A successful path is not proof of rollback.** Rollback is claimed only for the failure points injected in §12: after the `created` status hook, and after the completion write.
- **The transaction contract covers the supported UI/application actions** listed in §12. A direct model-level create, outside these actions (for example `Presence::create()` from a console or a seeder), is **not** wrapped. Its INSERT, hook and completion refresh commit separately unless the caller opens a transaction.
- **No concurrency, idempotency or exactly-once claim.** The retries are sequential, in a single process. Two simultaneous first saves of a one-row module were not exercised. Only Gestión, Recursos, Experiencia and Presencia have a `unique (empresa_id, user_id)` index, and it does not stop two users of the same company from both inserting.
- **New-affiliate onboarding is not ready:** see §5 and §12. The unverified-user 500 is a separate release blocker.
- **Historical data:** «0 NULL actors since 2026-09-01» does not by itself prove there is no historical partial data. §8 stays a proposal for manual review.
- Not exercised: the real registration form (captcha), real email delivery, and every other application feature. This is measured coverage of the paths above, not a guarantee of zero bugs.

## 12. Correction round `6214463281`: failure-path atomicity

**Review finding (accepted):**
- Presencia/Sostenibilidad are **not** «a single INSERT». After the INSERT, they run:
  - `created` → `EmpresaModuleStatus::setStatus(…, false)`, a DELETE on `empresa_module_status`;
  - `saved` → `EmpresaCompletionObserver` → `Empresa::refreshCompletionPercentage()`, an UPDATE on `empresas`.
- With no enclosing transaction, a failure there would leave a committed row and stale state.
- The first delivery's tests only covered successful saves and retries.

**Runtime change (`b9b41a0`, 3 files, +19/−4):**
- **Presencia:** `CreateAction`, `EditAction` and `DeleteAction` use `->databaseTransaction()`. This is the installed Filament 3.3 mechanism, `MountableAction::databaseTransaction()`.
  - `HasActions::callMountedTableAction()` then calls `DB::beginTransaction()` before the action, `commit` after `callAfter()`, and `rollBack` on any `Throwable`, including validation errors.
- **Sostenibilidad:** `CreateAction` and `DeleteAction`, same mechanism.
- **Experiencia:** `deleteEntry` (UPDATE of the entries + completion) is now in `DB::transaction`, like `saveEntry`.
- **Not done:**
  - no model or global transaction change;
  - `NoAplicaAction` is unchanged: it is not a first-save path, and it is outside this correction;
  - no migration, data change or skipped event.

**Update/delete paths: what they actually do, and what protects them:**

| Path | Writes | Protection |
|---|---|---|
| Gestión, any section, also later saves | section status, `create([])` + hooks on the first save, `update`, completion | `DB::transaction` in `saveFieldsWithNoAplica` (first delivery) |
| Recursos, any type | same | `DB::transaction` in `saveFieldWithNoAplica` |
| Experiencia, Agregar / Editar | first `create` + hooks, `update(exp_year)`, completion | `DB::transaction` in `saveEntry` |
| Experiencia, Eliminar | `update(exp_year)`, completion | **new:** `DB::transaction` in `deleteEntry` |
| Presencia, Crear / Editar / Eliminar | INSERT/UPDATE/DELETE + status hook (create) + completion | **new:** Filament action transaction |
| Sostenibilidad, Crear / Eliminar | INSERT/DELETE + status hook (create) + completion | **new:** Filament action transaction |

**Connection scope, measured, not assumed.** Inside the injected step, each case records the connection of:
- the module model;
- the default connection that `DB::transaction` / Filament use;
- `Empresa`, which holds the completion;
- `EmpresaModuleStatus`.

In all 14 cases the four are **`pgsql`**, and the transaction level on the model's connection is **1**. None of these models sets `$connection`, so all use the default, which is `pgsql` in the runner and on staging.

**Injected failures** (`FirstSaveAtomicityTest`):
- **Setup:** no outer test transaction; real Eloquent events; synthetic data cleaned up in `tearDown()`.
- **Injection, test-only:**
  - `created`: a temporary `eloquent.created: <Model>` listener. It is registered after the model's own `created` hook, so it fails right after the status DELETE.
  - `completion`: a container double of `EmpresaCompletionObserver`. It calls the real `saved()`/`deleted()` (the completion UPDATE) and then throws.
  - Production code is not patched.
- **Before:**
  - the company has the module «No aplica»;
  - the cached `completion_percentage` is set to a sentinel (1), so the refresh written inside is observable (13–25 measured inside);
  - a second company has its own row and «No aplica».
- **Asserted after the failure:**
  - the exception surfaced;
  - the INSERT had run inside (1 row seen in the transaction);
  - nothing is committed (full snapshot of the five module tables, status rows and cached completion);
  - «No aplica» is still set and the completion is still the sentinel;
  - the other company is identical.
- **Then the retry, without the injection:**
  - 1 row with the actor;
  - «No aplica» cleared;
  - the cached completion equals a fresh computation;
  - the other company is still identical.

| Case | Before the correction (`19a67bb`, runtime = `dbda7ce`) | After (`b9b41a0`) |
|---|---|---|
| Gestión @ created / @ completion | rolled back (helper `DB::transaction`, level 1); retry OK | same |
| Recursos @ created / @ completion | rolled back (level 1); retry OK | same |
| Experiencia @ created / @ completion | rolled back (level 1); retry OK | same |
| Presencia @ created | **level 0: committed `rows 1`, «No aplica» cleared** | rolled back (level 1); retry OK |
| Presencia @ completion | **level 0: `rows 1`, «No aplica» cleared, completion 25** | rolled back; retry OK |
| Sostenibilidad @ created | **level 0: `rows 1`, «No aplica» cleared** | rolled back; retry OK |
| Sostenibilidad @ completion | **level 0: `rows 1`, «No aplica» cleared, completion 25** | rolled back; retry OK |
| Presencia Editar @ completion | **level 0: row changed, completion 25** | rolled back |
| Presencia Eliminar @ completion | **level 0: row deleted, completion 13** | rolled back |
| Sostenibilidad Eliminar @ completion | **level 0: row deleted, completion 13** | rolled back |
| Experiencia Eliminar @ completion | **level 0: entries changed, completion 25** | rolled back |

Evidence: `audit/runs/first_save/atomicity/`.
- `prefix/phpunit.txt`: 15 tests, 8 failures, exactly the bold cells.
- `focused/phpunit.txt`: the `ATOMICITY …` lines carry each measured probe.

**Tests at `b9b41a0` (isolated runner `infra/contabo-test-runner` @ `457d445`, browser @ `5b6171c`; staging 200 before and after each run):**

| Run | Result | Evidence |
|---|---|---|
| Before the correction, `19a67bb` (tests only) | 15 tests, **8 failures** (expected red, above) | `atomicity/prefix/` |
| Focused (same pattern as §7) | **OK (107 tests, 1,476 assertions)** | `atomicity/focused/` |
| Feature suite | **OK (218 tests, 2,147 assertions)** | `atomicity/full_feature/` |
| **Full isolated suite** (Unit+Feature, at the final code SHA) | **OK (547 tests, 3,870 assertions)** | `atomicity/full/` |
| Browser, short (`ux_check_first_save_tx.mjs` + `ux_seed_first_save.php`; the company starts with 0 rows) | **7/7**: Presencia first create closes, «Creado», 1 row, «Crear» hidden, still there after reload; Sostenibilidad first create closes, «Creado», 1 row after reload | `atomicity/ux/` (2 screenshots) |

The full visual matrix of §6 (40/40, 3 widths × 2 schemes) was not repeated; the forms are unchanged.

**What this proves, and what it does not:**
- **Proved:** for the 5 supported first-save UI paths and the 4 update/delete paths above, a failure after the INSERT at the two identified steps leaves no committed change, and a later sequential retry succeeds. Measured on PostgreSQL, single connection.
- **Not proved:**
  - other failure points, such as a database crash or a failure between COMMIT and the HTTP response;
  - concurrency, idempotency or exactly-once behaviour;
  - direct model-level creates outside these actions (§11).
- **Pre-existing, not fixed, out of scope:** `EmpresaModuleStatus::setStatus(…, false)` deletes through the query builder, so it fires no model event. Turning «No aplica» off from `NoAplicaAction` therefore does not refresh the cached completion by itself. It is noted here for a separate round.
