# CPV-MODEL-COMPATIBILITY-AND-LABELS: `EmpresaPgsql` loads again; OVID and ISO 9001/17025 labels

- **Authorization:** Issue #2 `6211997650` (owner, 2026-10-16 09:03Z). It covers implementation and isolated validation. Deployment is **not** authorized.
- **Direct reference read:** `6190871814`, the read-only investigation. No other comments were traversed.
- **Branch:** `fix/model-compatibility-labels`.
- **BASE_SHA:** `28014c2cb56ef5f75bb9339545a045635ee81854`. This is the accepted continuity checkpoint (`6211970495`).
  - It contains the deployed runtime `0f8bdad` plus docs/audit and the root startup files only (`git diff 0f8bdad 28014c2` under app/resources/routes/config/database/public/composer/docker/.github is empty).
  - Verified live, read-only, at 09:05Z: the VPS checkout is at `0f8bdad`, with 0 modifications and image `sha256:37e023fd…`. The remote `feature/upgrade-filament-v3` is also at `0f8bdad`.
- **TESTED_HEAD:** `2f1e6fdd9a363bfa57c1a8e523375e6e963bcbd1`. This is the final runtime code; the later commits are docs/audit only.
- **REVIEW_HEAD:** the commit that adds this report.
- **Deployed:** NO.

## 1. Root cause and fix

**Root cause:**
- `Empresa::taxonomyCategories(): HasMany` returns `EmpresaTaxonomyCategory` link records.
- `EmpresaPgsql extends Empresa` redefined it with no return type, as `belongsToMany(TaxonomyCategory, 'empresa_taxonomy_category', …)->withPivot(['origen','approved_by','approved_at'])`.
- PHP checks a user-defined override's signature when the class is **loaded**, so loading `EmpresaPgsql` was a fatal error, before any query ran.

**Reproduced before the fix:**
- With `git show 28014c2:app/Models/EmpresaPgsql.php` loaded through Composer's autoload in a separate `php -r` process, it exits 255 with: `PHP Fatal error: Declaration of App\Models\EmpresaPgsql::taxonomyCategories() must be compatible with App\Models\Empresa::taxonomyCategories(): Illuminate\Database\Eloquent\Relations\HasMany`.
- After the fix, the same check exits 0 and prints `LOADED`.
- The regression test repeats both checks in a subprocess (§3).

**Fix (`app/Models/EmpresaPgsql.php`):**
- The incompatible override is **removed**. The subclass now inherits the parent's typed link-record relationship.
- `$connection = 'pgsql'` and `$table = 'empresas'` stay explicit.
- **Not done:** the parent's return type was not removed, and there is no `#[ReturnTypeWillChange]`, no error suppression, no cast to the wrong relation and no change to the default connection.
- **No new relationship API:** no consumer needs direct categories (§2).

Only a comment changed in `TaxonomyCategoryRequestResource.php`: it no longer says the class "does not compile". The column still shows the request's snapshot on purpose, because a request keeps what was sent.

## 2. Caller impact

`grep` over `app/`, `routes/`, `database/`, `resources/`, `config/` and `tests/`:

| Contract | Real consumers |
|---|---|
| `taxonomyCategories()` as link records (HasMany) | `TaxonomyCategoriesRelationManager` (`$relationship = 'taxonomyCategories'`; `->taxonomyCategories()->create([...origen, es_principal...])`); `TaxonomyCategoryRequestTest`. Both use `Empresa` and are **unchanged**. |
| `taxonomyCategories()` as direct categories (BelongsToMany) | **none.** Only the broken override declared it, and nothing could call it, because the class never loaded. |
| `empresa()` → `EmpresaPgsql` | `CompanyCrawlRun`, `CompanyPage`, `CompanyTermMatch`, `EmpresaTaxonomyCategory`, `TaxonomyCategoryRequest`. These are `belongsTo(EmpresaPgsql::class, 'empresa_id')` and unchanged. They now resolve instead of failing fatally. |

**Who resolves that relation today:** no `->empresa` or `with('empresa')` usage on those five models was found in `app/`.
- The request resource reads the snapshot.
- The crawler command looks up companies through `DB::connection('mysql')`. That dependency is separate and out of scope, so this repair does **not** claim to fix the crawler.

**Preserved:**
- **Link metadata:** `origen`, `es_principal`, `approved_by` and `approved_at` live on the link record, which is returned as is.
- **Results:** the existing query results.
- **Access:** authentication and company visibility (inherited).
- **No changes to:** schema, migrations, categories or data.

## 3. Labels (`ManagementRelationManager` and the other outputs of the same fields)

**Evidence for OVID:**
- the field is `ovid`;
- `BuildEmpresaSearchDocuments` labels it `'OVID'`;
- `JoinViewExport` headers say `"OVID"`;
- `SyncEmpresaCertifications` and both exports put it under **seguridad**.

Nothing in the project supports «COVID». So it was relabelled. Its meaning is **not explained**, because no authoritative project source defines it.

| Place | Before | After |
|---|---|---|
| «Seguridad» modal, checkbox `ovid` | `COVID.` | `OVID` |
| Summary column «Seguridad» (`ovid`) | `COVID` | `OVID` |
| Company profile PDF (`resources/views/pdf.blade.php`, both branches) | `COVID` | `OVID` |
| Admin view `management-det-view.blade.php`, column header | `COVID` | `OVID` |
| Export `ManagementDetExport`, header | `COVID` | `OVID` |
| «Calidad» modal, `iso9001` / `iso17025` | no label (Filament showed «Iso9001» / «Iso17025») | `ISO 9001` / `ISO 17025` |

- **Unchanged:** field names, stored values and certification rules.
- **Already explicit, so not touched:** the PDF (`ISO 9001:2015 …`, `ISO 17025: 2005 …`) and the summary column (`ISO 9001`, `ISO 17025`).
- **No sweep:** there was no broader relabelling or spelling sweep.

## 4. Manual

`resources/manual/manual_usuario.md` §«Sistemas de gestión» (`#sistemas-de-gestion`, the anchor is unchanged):
- the Seguridad line now reads «(por ejemplo, ISO 45001 u OVID)», which matches the visible checkbox;
- «ISO 9001 o ISO 17025» was already there and still matches.

`EmpresaPgsql` has no visible effect, so its part needs no manual change. PDF-baseline coverage is unchanged: `UserManualContentTest` is green.

## 5. Tests

Everything ran on the isolated Contabo runner (`infra/contabo-test-runner`), with disposable PostgreSQL and synthetic data only. Staging returned 200 before and after every run, and no `cpvtest-*` container was left.

**New `tests/Feature/Database/EmpresaPgsqlCompatibilityTest.php`** (5 tests):
- **Before the fix:** the old override, copied under another class name, fails to load **in a subprocess** (non-zero exit plus the exact "must be compatible … HasMany" message). The PHPUnit process survives.
- **After the fix:** `EmpresaPgsql` loads in a subprocess (exit 0, `LOADED`).
- **Connection and relation:** it uses `pgsql` and `empresas`, does not redefine the relation, and `taxonomyCategories()` is a `HasMany` of `EmpresaTaxonomyCategory` on `pgsql` with `empresa_id`.
- **Same records:** through `Empresa` and through `EmpresaPgsql` you get the same link records. A validated principal keeps `origen`, `es_principal`, `approved_by`, `approved_at` and its category; a self-declared secondary one keeps its own values.
- **Inverse relations:** the five `empresa()` relations, with one synthetic row each, are `BelongsTo` on `empresa_id`. Each resolves lazily and eagerly (`with('empresa')`) to an `EmpresaPgsql` with the same id on `pgsql`, and from that company the link records are still reachable.

**New `tests/Feature/Filament/ManagementLabelsTest.php`** (4 tests):
- the affiliate (with the Shield management permissions) sees «OVID» and no «COVID», and saving writes `ovid`;
- the Super Admin sees «ISO 9001» / «ISO 17025» (not «Iso9001»), and saving writes `iso9001`;
- the loaded summary table says OVID, and the PDF, admin view and export have no «COVID»;
- the manual section says «ISO 45001 u OVID».

**Runs:**

| Run | SHA | Result | Evidence |
|---|---|---|---|
| Focused (`EmpresaPgsqlCompatibility\|ManagementLabels\|TaxonomyCategoryRequest\|TaxonomyCategoriesRelationManager\|UserManual\|CompanyCrawl`) | `2f1e6fd` | **OK (61 tests, 673 assertions)** | `audit/runs/model_compat_labels/focused/` |
| **Full isolated suite** (Unit + Feature), once | `2f1e6fd` | **OK (515 tests, 3,157 assertions)**, 1:31 | `audit/runs/model_compat_labels/full/` |
| Browser (`ux_check_management.mjs` + `ux_seed_management.php`, runner @ `fc1100b`) | `2f1e6fd` | **51/51** | `audit/runs/model_compat_labels/ux/` |

**Earlier red runs** at intermediate heads were test-authoring problems only:
- `EmpresaPgsqlCompatibilityTest` was green from the first run;
- the label test needed the affiliate's Shield permissions, the admin's permissions through its role (`define_via_gate` is false), and to load the deferred table.

**Browser check:**
- For the affiliate and the Super Admin, at 1440 and 390 px, in light and dark:
  - the «Sistemas de gestión» tab and its actions are reachable;
  - the summary shows OVID and no COVID;
  - «Seguridad» has a checkbox labelled «OVID»;
  - «Calidad» has «ISO 9001» and «ISO 17025»;
  - no page overflow with either modal open.
- In desktop light only:
  - the affiliate ticks OVID, and it persists when the modal is reopened;
  - the admin ticks ISO 9001, and it persists;
  - the admin also sees the affiliate's OVID.
- There are 16 screenshots: `gestion-{affiliate,admin}-{light,dark}-{desktop,mobile}-{seguridad,calidad}.png`.
- The seed grants Shield permissions (company and management) to the two synthetic users, links the synthetic admin to the synthetic company, and inserts one `management` row without events (see §6).

## 6. Finding: pre-existing, not fixed, outside this authorization

`Management::created()` runs `update management set management.user_id = ? where empresa_id = ?`. PostgreSQL rejects the table-qualified SET column: `SQLSTATE[42703]: column "management" of relation "management" does not exist` (`audit/runs/model_compat_labels/prefix_management_hook_failure_phpunit.txt`, run at `9177f96`).
- **Where it applies:** one **active** statement with the same pattern is in each of `Management`, `Asset`, `Experience`, `Presence` and `Sustainability`. The other occurrences in those files are inside `/* … */` comments.
- **Likely effect on staging:** staging's default connection is pgsql (the `.env` has `DB_CONNECTION=pgsql`, checked by name only). So **the first save** of those modules for a company that has no row yet probably fails there. Updating an existing row does not run the hook.
- **Not verified live:** no live crash probe, as the instruction asks.
- **Fix candidate** for a separately authorized round: unqualified `set user_id = ?`, which is valid in both MySQL and PostgreSQL.
- In this round, the tests and the browser seed insert the `management` row without events, so that the labels' update path is tested on its own.

## 7. Continuity

- **Carried forward:** the branch starts from `28014c2`, so the accepted startup files (`CLAUDE.md`, `AGENTS.md`, `docs/orquestador/{SESSION_RESUME.md, current_task.md, ENVIRONMENT.md, STARTUP_DRY_RUN.json, check_tools.ps1}`, `history/**`) are carried into this runtime candidate unchanged, apart from the refreshed checkpoint. That is the carry-forward the continuity closure requires.
- **When they reach the deploy line:** they do so if and when this candidate is deployed.
- **Checkpoint:** refreshed with this task's state. The startup stays bounded, and credentials stay external.

## 8. Limitations

- Not deployed, so nothing here is verified on staging.
- The admin browser check uses permissions granted in the disposable stack. Production keeps its own role permissions, which were not inspected.
- The `Management`/`Asset`/`Experience`/`Presence`/`Sustainability` create hooks are left as they are (§6).
- **Backlog, unchanged:**
  - `Empresa::users()` without auth;
  - empty `cities`;
  - empty `empresas` count;
  - search ancestor state;
  - workflow filtering;
  - the unrelated PDF;
  - the crawler's mysql dependency.
