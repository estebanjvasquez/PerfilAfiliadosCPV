# CPV-SEC-01: surface inventory, access matrix and first confirmed repair (sanitized)

- **Authorization:** Issue #2 `6293112309` (programme) + `6293203444` (start instruction), owner, 2026-10-20.
- **BASE_SHA:** `4a56fafa5117cd6efb739603aa468efbf841cd15` (deployed runtime `87dd997` + checkpoint and plan docs, verified: no runtime diff).
- **Branches** (dependency order):
  1. `audit/security-access-01`: `4a56faf` → tests `1f1dcc6`…`ab0464b` (inventory, matrix, regressions; the impersonation regression fails here).
  2. `fix/sec-01-impersonation-gate`: `ab0464b` → `613c359` (fix) → `7d8531e`/`4819877` (test adjustments only).
  3. `audit/security-access-01` fast-forwarded to `4819877`, then this report and the checkpoint (documentation only).
- **TESTED_HEAD:** `7d8531e` (full isolated suite) and `4819877` (focused; it changes only the inventory test's evidence filter).
- **Deployed:** NO.

This is one bounded round. It is **not** a statement that the system is secure, and SEC-02…05 are not completed.

## 1. Method

- **Environment:** the isolated Contabo runner (`infra/contabo-test-runner` @ `3e76459`) with a disposable PostgreSQL, synthetic users and companies (ALFA, BETA), and staging-identical report views.
- **Staging:** only two read-only checks, with staging 200 before and after every run:
  1. role names, user counts per role and permission names, inside a READ ONLY transaction;
  2. a route and package check.
- **Not done:** no scanner, crawler or fuzzer; no write to staging; no real-company export.
- **Inventory:** taken from the **booted application** (`tests/Feature/Security/SurfaceInventoryTest.php`):
  - every registered route with its gathered middleware;
  - every panel resource with its pages, relation managers, policy and query scope;
  - every custom page with its gate, and every widget;
  - every registered Livewire component, with the public methods its app class declares (the browser can call any public method).
- **Access matrix (`AccessMatrixTest`):** 9 actors × 88 panel entrypoints = **792 real HTTP requests**. Every resource index and create page, edit/view of a real record, and every custom page.
  - **Invariant:** a 200 must match the server-side gate evaluated for that actor (`Resource::can*`, `Page::canAccess`), and company data must stay in the linked company.
  - **Livewire paths:** list, global search, edit with forged protected fields, mixed-company selections, and relation-manager actions sent with another company's record keys. Allowed paths are tested too.
- **Framework:** ASVS 5.0 authorization principles, used as a test checklist and not as evidence.

## 2. SURFACE_COVERAGE (denominators)

| Surface | Inventoried | Tested this round | State |
|---|---|---|---|
| Routes (effective) | 131: panel 114 (all behind the panel `Authenticate`), public 5, API 1 (Sanctum), framework 11 | public allowlist + panel auth asserted for all 131 | verified |
| Public routes | `/`, `form`, `pdf/{id}`, `xls/{id}`, `filament-impersonate/leave` | reviewed; `pdf`/`xls` redirect only (earlier ExportAccess tests) | verified; `form` registered as F-SEC01-04 |
| Panel resources | 30 (all have a policy; 11 with a custom query scope) | index/create for 30 + edit/view for every resource with a record | verified (HTTP) |
| Custom pages | 29: 17 Shield page permission, 9 custom `canAccess`, 3 open to any verified user (Dashboard, Settings: empty page, MyProfile) | 29 × 9 actors | verified (HTTP) |
| Relation managers | 23 (10 on Empresa) | Contacts, Users, Chambers: cross-company record keys + allowed actions | 3 of 23 tested; 20 not tested |
| Livewire components (app) | 151 registered; 30 declare 52 public methods | Empresa list/edit/create, 3 relation managers, Users list | partial; the global-report export methods sit behind page gates re-checked on every request (framework mechanism, not executed) → SEC-02 |
| Global search | 13 searchable resources | Empresa (own found, other not found) | 1 of 13 tested |
| Downloads | protected PDF/XLSX routes, Filament export/import downloads | protected routes: ExportAccess/CompanyDossierPdf kept green | Filament export/import downloads → SEC-02 |
| Uploads / temporary files | Livewire `upload-file` / `preview-file` (signed) | not tested | SEC-02/03 |
| Auth / registration / verification / reset | login, register, prompt, reset | guest/unverified invariants for 88 entrypoints | flows themselves → SEC-03 |
| API | `api/user` (Sanctum, returns the caller) | inventoried | not tested → SEC-03 |
| Debug tooling | `_ignition/*` registered **only in the test image**; staging has no such route and the package is absent (read-only check) | checked | excluded with reason (dev dependency) |

**Split by operation:** read (list/view/search) is tested across the matrix. Write is tested for Empresa edit (allowed and forged fields), contacts, chambers, users detach, create and impersonation. Export is limited to the existing protected-download regressions; reports are SEC-02.

## 3. ACCESS_MATRIX (synthetic, 792 requests, **0 gate violations**)

| Actor | Outcomes over 88 entrypoints | Company scope (Empresa edit) |
|---|---|---|
| guest | 88 → login | — |
| unverified affiliate | 88 → verification prompt | — |
| affiliate ALFA / BETA | 10 ok, 75 forbidden, 3 not found | own company ok; the other → 404 |
| linked read-only staff | 45 ok, 39 forbidden, 3 not found, 1 error | ALFA edit 403 (no update permission); BETA → 404 |
| super_admin role (synthetic, without staging's explicit permissions) | 9 ok | BETA → 404 |
| verified, no permission | 5 ok (Dashboard, Settings, MyProfile, manual and similar), 79 forbidden | 404 |
| revoked from ALFA | 9 ok, 75 forbidden | ALFA → 404; the list shows nothing |
| holder of the role-management permission only | 8 ok, including the role editor | 404 |

**Livewire results (all pass):**
- The list and global search show only the linked company.
- Edit saves allowed fields, but **ignores** forged `rif`, `sector_principal_id`, `id` and `completion_percentage`.
- A mixed ALFA+BETA selection resolves to ALFA only (bulk «Editar» / «Exportar listado»).
- Contacts, users and chambers actions sent with BETA's keys from ALFA's page change nothing. ALFA's own actions work, and detaching a shared chamber keeps BETA's link.
- The edit page cannot be mounted by revoked, unlinked or no-permission users.
- Creation through the panel links the company to its creator only, and forged keys are dropped.

**The single 500:** «JoinViews» as staff. This is the known `JoinViews` defect (backlog), not an access issue.

## 4. FINDINGS (opaque IDs; mechanisms of unresolved items are restricted)

| ID | Severity | Status | Safe summary |
|---|---|---|---|
| F-SEC01-01 | High | **Confirmed and fixed** (not deployed) | A non-super-admin who can see the user list could take over another account's session, including a super admin's. On staging one existing staff role assignment has this reach. Owner notified in `6293694720`. |
| F-SEC01-02 | High (configuration) | Confirmed by matrix: a role-management holder reaches the role editor | A staff role (not super_admin) holds privilege-administration permissions; in Shield, that is equivalent to full privilege. **Owner decision:** restrict role management to super_admin (data change, not authorized here). |
| F-SEC01-03 | Low–Medium (configuration) | Suspected, from permission data; not executed | The affiliate role holds create permissions on two global catalogs. **Owner decision:** remove them, or confirm they are intended. |
| F-SEC01-04 | Low | Confirmed reachable (inventory) | A legacy public Livewire test form (`/form`). No database access; its submit only echoes the sender's own input. Proposal: remove the route. |
| F-SEC01-05 | Medium (privileged-only) | Suspected; not reproduced (cap) | A method on the user edit page that the browser can call may return more account data than intended. Only an actor who can already edit users reaches it. Proposal: a one-line visibility fix, next round. |
| F-SEC01-06 | Decision | Observed | Any user linked to a company can unlink the company's other users, with no extra permission. The business rule is undefined; propose «only staff, or the principal contact». |
| F-SEC01-07 | Low (SEC-03 lead) | Not tested | `email_verified_at` is mass-assignable on `User`; no current form exposes it. Verify in SEC-03. |
| F-SEC01-08 | Info (SEC-04 lead) | Observed | 38 legacy hosting `error_log` files are tracked in git, one of them under `public/`. They contain stack traces and server paths, with no secret keywords. Whether staging serves them was not checked. |
| R-SEC01-01 | Reliability | Confirmed | The company create/edit forms crash when the cities table is empty (an undefined loop variable). Known backlog item. |

**Cap:** one connected High root cause was fixed in this round (F-SEC01-01). The rest are registered and ranked, not fixed.

## 5. FIX (F-SEC01-01)

- **Change:** `App\Models\User::canImpersonate()` returns `hasRole(super_admin)`. The impersonation packages call it when it exists; without it they allowed anyone.
- **Unchanged:**
  - access to Usuarios itself;
  - super admins keep impersonation;
  - roles, permissions, configuration and schema.
- **One runtime file** (`app/Models/User.php`).

## 6. REGRESSION and TESTS

- **Fails before:**
  - `ab0464b`, focused (`SurfaceInventory|AccessMatrix`): 9 tests, **1 failure**, exactly `only_a_super_admin_can_impersonate…` («denied: a user-list viewer does not impersonate»);
  - `517f814`: same failure.
- **Passes after:** `7d8531e`, **full isolated suite OK (593 tests, 6,824 assertions)**, run `20261020T150341Z-7d8531e24fcb`.
- **Focused at `4819877`** (`SurfaceInventory|AccessMatrix|ExportAccess|CompanyDossierPdf`): **OK (25 tests, 2,487 assertions)**.
- **Intermediate runs at `613c359` and `27b0247` errored** in the allowed-path part of my own test: a second Livewire page and a session switch inside one test. The test was simplified; the code did not change.
- **Browser:** not run. The changed flow is an action that is now hidden for non-super-admins; it is covered by the Livewire test.

## 7. PUBLIC_EVIDENCE_SCREEN

- **Pushed:** test code, a one-method fix, this report and checkpoint docs.
- **Screened:**
  - no secret, environment value, token, real account or company identifier;
  - no role-to-permission mapping and no exploit steps for unresolved items;
  - the tests use synthetic names and generic permission sets;
  - the affiliate actor uses only company-related permissions, and its catalog permissions (F-03) are not in the test.
- **Withheld:** the raw matrix and inventory JSON stay in restricted storage. Only the aggregates above are public.

## 8. RESTRICTED_EVIDENCE

- **Location:** executor-local, outside git and webroot (`%USERPROFILE%\.cpv-restricted\sec-01\`).
- **Contents:**
  - the read-only role/permission snapshot;
  - the raw inventory and matrix JSON;
  - the mechanism notes for F-01…F-03.
- **Handoff:** on owner request, through a private channel the owner chooses. Nothing is assumed readable by another executor.

## 9. MANUAL

Unchanged. The manual does not describe impersonation; for affiliates nothing changes.

## 10. PENDING_AND_DECISIONS

1. **Deployment** of `fix/sec-01-impersonation-gate` (separate authorization), or interim containment (see `6293694720`).
2. **F-02 / F-03:** role data changes (owner).
3. **F-06:** the business rule for unlinking co-users.
4. **Next SEC-01 round** (cap reached):
   - F-05 (one line);
   - F-04 (remove `/form`);
   - the 20 untested relation managers;
   - global search on the other 12 resources;
   - a super-admin column with staging's explicit permission set.
5. **Then SEC-02** (reports, exports and files) per plan.
