# CPV-SEC-01-R2: account privileges and path boundaries (sanitized)

- **Authorization:** Issue #2 `6310273754` (owner, 2026-10-21), activating the account and path checks of SEC-02/SEC-03 for these two surfaces only. Prior round: delivery `6294285848`, review `6297739082`. **Review corrections:** `6310331211` (R2-REV-01…04), addressed here.
- **BASE_SHA:** `0139e954ec22f0a6bfa2377968a6a3af86c15874` (the accepted, frozen F-01 candidate). A new branch was taken from it; the F-01 candidate was not amended.
- **BRANCH:** `fix/sec-01-account-path-boundaries`.
- **TESTED_HEAD (second review, R2-REV-05):** `f4dd8fb2c1945f8f4839f710192221672ebca3b0` — focused and full isolated suite (see REGRESSION). Earlier: full `6bf8582aa8e7155c10ca560fb6089d7b5bef73ef` (606/7,016), focused `e7ba59d9deb35198bc9fb3fac857255ebaa9e0e2`; previous REVIEW_HEAD `51a923e`.
- **REVIEW_HEAD:** the commit that adds this updated report and checkpoint.
- **DEPLOYED:** NO.

> **Second review (`6310331211`, «SECOND REVIEW UPDATE — NARROW ATOMICITY CORRECTION»), addressed here as R2-REV-05.** The earlier claim that Filament already made the account + role save atomic was **incorrect** and is retracted (dated note in FIX below): pinned Filament v3.3.55 ships panel transactions off and this candidate had not enabled them. Transactions are now enabled explicitly on `CreateUser` and `EditUser`, on the account and pivot connections, with failure-injection checks asserted before test teardown. See ATOMICITY.

> **Review-corrections summary (`6310331211`).** The privilege-grant control was moved to BEFORE the role relationship is persisted (it was an after-sync cleanup); a persistence-boundary test proves the forbidden role is never written. Traversal assertions now read decoded PDF text, assert the exact denial and record the layer, and no OS file is targeted. The access matrix gained a business-level independent oracle for high-value entrypoints and now fails on unexpected 5xx (except the counted JoinViews defect). The cumulative register `docs/security/SECURITY_STATUS.md` is carried into this branch and updated. Sections below reflect these corrections.

## F-01 deployment status (read-only, prominent)

**F-01 is accepted but NOT deployed. The live risk remains.** On 2026-10-21 staging runs `87dd997`; `feature/upgrade-filament-v3` is at `87dd997`; `User::canImpersonate()` is **absent** from the running checkout; login 200. Reviewing F-01 did not remove the live exposure. The fix stays independently deployable on `fix/sec-01-impersonation-gate` (reviewed head `613c359`), awaiting a separate owner deployment authorization. This round does not touch it.

## A. Independent authorization — corrections to the prior matrix

- **Independent expectations:** the new `AccountPrivilegeTest` and `ImpersonationActionTest` assert the APPROVED BUSINESS RULES written as literals, not the gate's own result. Both allowed and denied paths are asserted.
- **Unexpected 5xx:** the new tests assert specific statuses (no catch-all). `PathBoundaryTest` fails on any 5xx. The only accepted non-pass remains the narrowly identified `JoinViews` reliability defect (backlog), still counted as the single runner "finding", not swallowed.
- **Representative privileged actor:** the privilege tests build a super admin WITH the explicit management permission (`update_user`), since the bare role is not representative under the isolated Shield configuration.
- **Full impersonation action:** `ImpersonationActionTest` asserts the action is authorized and offered to a super admin, performs the take, then leaves through the package route and restores the session. The denied path (a user-list viewer) is kept. (The click itself returns a redirect that unmounts the Livewire component, so `take()` stands in after the authorization assertion; stated as a limitation.)

## B. USER_CREATION_AND_UPDATE_MATRIX (executed, synthetic)

Business rule: `create_user`/`update_user` manage ordinary accounts; only a super admin grants the privileged role (`super_admin`) or modifies an account that holds one.

| Surface | Actor | Attempt | Expected | Result |
|---|---|---|---|---|
| Create user | staff (create_user) | set `roles=[super_admin]` | created ordinary; no super role | **PASS** (fixed) |
| Edit user | staff (update_user) | set target `roles=[super_admin]` | target not elevated | **PASS** (fixed) |
| Edit self | staff (update_user) | set own `roles=[super_admin]` | no self-elevation | **PASS** (fixed) |
| Open edit | staff (update_user) | open a super_admin account | 403 | **PASS** (fixed) |
| Create user | super admin | set `roles=[super_admin]` | granted | **PASS** (allowed) |
| Create user | staff (create_user) | set `roles=[plain]` | ordinary role attached | **PASS** (allowed) |
| Open edit | super admin (update_user) | open a super_admin account | 200 | **PASS** (allowed) |
| Create user | staff | password hashed/hidden | hash stored, hidden in toArray | **PASS** |
| Edit user | staff | blank password | old hash kept, not nulled | **PASS** |
| Public registration | guest | forged `roles` + `email_verified_at` | ordinary, unverified, no role | **PASS** |
| Create user | staff | display name `admin` | no role/authority | **PASS** |

**Fails-before (`f3f7c5f`, no fix):** the four privilege-injection rows failed (the elevation succeeded), proving the gap. Allowed and registration rows passed before and after.

## C. PATH_SURFACE_MATRIX (directed inventory + canary)

**Sinks inventoried** (request/Livewire-controlled values that could reach the filesystem):

| Sink | Request value | Handling | State |
|---|---|---|---|
| Company download route `{empresa}` | path segment | `ctype_digit` + length ≤ 18; not a path | tested |
| Download query `secciones` | query | allowlisted keys (`CompanyDossier::sectionsFrom`); not a path | tested |
| Manual / help render | — | fixed `resource_path(...)`, no request input | inventoried, not request-driven |
| PDF renderer (dompdf) | — | logos are fixed `public_path(...)`; no user value placed as img src / `url()` | inventoried; not reachable |
| Uploads / temp files | — | no `FileUpload` component in the panel; Livewire upload/preview are signed | inventoried → SEC-02/03 |

**Canary test (R2-REV-02):** a harmless canary file was placed OUTSIDE the app root (a disposable system-temp directory). **No OS or credential file is ever targeted.** Benign relative payloads reference the canary by name; absolute ones point at it. Payloads cover `../`, `..\\`, `....//`, URL-encoded and double-encoded `%2e%2e%2f`/`%252e`, backslash-encoded, absolute, `file://`, and a URL-encoded absolute path.
- **`{empresa}` segment:** every payload → **404**, no PDF generated, no 5xx. The **layer** is recorded per payload: when the value reaches the controller it returns the exact «Archivo no disponible» view with **0 report-view queries and 0 PDF renders**; a slash-bearing value the router rejects before the controller is a safe 404/403. Canary content never disclosed, no filesystem path echoed.
- **`secciones`:** every payload → the **complete profile** (allowlist fallback). For each, the **decoded PDF text** (`pdftotext`) is asserted free of the canary content (compressed bytes alone are not proof). No file, no 5xx, no leak.
- **Side effects:** the canary file is unchanged (content re-checked) and the legitimate numeric id still downloads a real PDF.
- **Result:** no traversal established on the tested surfaces — a negative result for exactly these surfaces, not a claim about all file surfaces. Evidence: `sec01r2_path_*.json` (restricted copy; aggregates here).

**Normalization note:** payloads were sent raw with `rawurlencode` on the segment so routing delivered the encoded value to the stack, exercising its own decoding rather than a client that pre-normalizes. No conclusion is drawn about nginx/CDN from an in-process Laravel test.

## INDEPENDENT_EXPECTATIONS_AND_5XX (R2-REV-03)

- **`AccessMatrixTest`** now carries a business-level independent oracle (`INDEPENDENT_EXPECTATIONS`) for high-value entrypoints — Empresa list and edit (own vs other company), the user and role admin lists, the manual and the dashboard — expressed as `allow` / `deny` / `login` / `verify`, where `deny` accepts either 403 or 404 (the policy is denial; the exact safe status is an implementation nuance). These rows are asserted independently of the gate.
- **The full 792-request sweep stays a gate-comparison characterization**, explicitly labelled as such in the evidence (`oracle` field): a wrongly permissive gate would satisfy both sides of its own comparison, so it is not claimed as independent business-policy coverage.
- **Unexpected 5xx/exception now FAILS** the matrix, except the one narrowly-identified `JoinViews` reliability defect (`KNOWN_RELIABILITY_FAILURES`, counted in the evidence, not swallowed).
- **Representative privileged actor:** the account tests build a super admin WITH the explicit `update_user` permission, since the bare role is not representative in the isolated Shield configuration; the matrix's bare-role super-admin rows are treated as `deny` for company data (no auto cross-company access).
- **Impersonation:** the allowed action remains partial coverage — the real table action is asserted authorized/offered, then `take()`+leave restore the session; the UI click returns a redirect the Livewire harness cannot follow. Stated plainly, not worked around further.

## FINDINGS_AND_FIXES (opaque IDs; mechanisms restricted)

| ID | Severity | Status | Safe summary |
|---|---|---|---|
| F-SEC01-02a (= F-02 account facet) | High | **CONFIRMED and FIXED** (not deployed) | A non-super-admin who can manage users could grant the privileged role (on create, on edit, or to itself) and could open/modify a privileged account. Fixed server-side BEFORE the role is persisted. The F-02 role-editor/configuration facet stays an owner decision (finding not closed). |
| F-04 | Low | **FIXED** | The unused public Livewire test form `/form` was removed (route, class, view), with a route regression. |
| F-05 | Medium (suspected) | **Not reproduced; redundant code removed** | The only browser-callable method on the user edit page returned data to the framework, not the browser, and the blank-password case is already handled by the form. The redundant lookup was removed; a blank-password edit keeps the old hash (tested). No credential exposure reproduced. |
| F-07 | Low (SEC-03 lead) | Confirmed benign here | `User::$fillable` includes `email_verified_at`; no panel form exposes it, and registration does not self-verify (tested). Left for SEC-03; not changed, to avoid breaking factories/verification. |
| F-02 (role editor) / F-03 (affiliate catalog creates) / F-06 (co-user unlink) | — | Owner decisions | Live role data and business-rule decisions. No live role changes were made. Sanitized before/after proposal below. |

**Cap:** one connected high-risk root cause fixed (privilege grants at the user write boundary), plus the bounded F-04 cleanup. Others registered.

## FIX (F-SEC01-R2-A = F-02 account facet) — enforced BEFORE persistence (R2-REV-01)

- **`App\Support\Security\PrivilegedRoleGuard`** authorizes the role set at the write boundary, BEFORE the sync:
  - the role Select's `->saveRelationshipsUsing()` (in `UserResource::form`) computes the authorized role ids and syncs only those, so a forbidden privileged role is **never written** — there is no after-save cleanup window. ~~This runs inside Filament's existing create/edit database transaction, so the account and its authorized roles persist atomically.~~ **Retracted 2026-10-21 (second review):** that transaction was not active — Filament v3.3.55 defaults it off and the pages had not enabled it. Atomicity is now provided explicitly (R2-REV-05, ATOMICITY below).
  - a non-super-admin can neither add nor remove the privileged role: the synced privileged set equals the record's current privileged roles; their non-privileged roles are whatever the form set. A super admin keeps full control.
  - opening (hence modifying) an account that already holds the privileged role is blocked in `EditUser::authorizeAccess`, checked fresh (a pivot query, not mounted/cached state) at mount and at the start of save.
- **Removed** the earlier after-sync approach (the `GuardsPrivilegedRoles` trait) and its unused snapshot helper.
- **Preserved:** super admins keep every operation; ordinary staff still create/edit ordinary users and assign non-privileged roles; passwords stay hashed and hidden; registration, verification and reset are untouched.
- **Scope:** the user panel pages only — not a global model event — so factories, seeders and other trusted writers are unaffected.
- **Also:** removed the redundant `EditUser::mutateFormDataBeforeSave` (F-05) and the `/form` surface (F-04).
- **Residual (not closed):** this fixes the account-writing facet of F-02. The role editor (RoleResource) reach and the privilege-delegation configuration remain an explicit owner decision; limiting one named role does not resolve all delegation paths.
- **Staff-visible behaviour:** a non-super-admin that tries to assign a privileged role sees an in-UI warning that it was not applied; the rest of the save succeeds. Nothing changes for affiliates, and there is no user-administration help page; this is recorded in `docs/security/SECURITY_STATUS.md` (§5 R2).

## ATOMICITY (R2-REV-05, second review)

- **Cause (confirmed in the pinned source):** `Panel::hasDatabaseTransactions()` defaults to `false` in v3.3.55, and the page hooks `beginDatabaseTransaction()`/`commit…`/`rollBack…` are no-ops until enabled. Neither `AdminPanelProvider` nor the user pages enabled them, so the earlier "already atomic" statement was wrong.
- **Configuration (page-scoped; the panel default is unchanged):** `App\Filament\Resources\UserResource\Concerns\UsesAccountWriteTransaction`, used by **both** `CreateUser` and `EditUser`:
  - `hasDatabaseTransactions()` returns `true` for these two pages only;
  - the begin/commit/rollback hooks open the transaction on the connections the writes **actually use** — the `User` model connection (account row) and the configured role model's connection (Spatie writes `model_has_roles` through the related role query) — instead of the default connection by assumption. Today both resolve to the same connection (`pgsql` in the isolated runner), and a test asserts that.
  - Filament begins before validation and rolls back on any `Throwable`, so nothing written during the request survives a failure.
- **Pre-sync authorization preserved:** `PrivilegedRoleGuard` still reduces the role set BEFORE `sync()`; the transaction supplements it.
- **Failure injection (`tests/Feature/Security/AccountWriteAtomicityTest.php`):** a failure is thrown right after the **last** write of the request, and the state is asserted **after the failed request and before teardown** (the outer test transaction is still open, so it cannot mask a missing application transaction). The boundary is also instrumented: transaction-begin events per connection, and the nesting level seen by the failing statement must be above the test wrapper's.
  - **create** (fail after the role-pivot insert, which follows the account insert) → no account and no role link remain;
  - **edit** (Filament syncs relationships first, then updates the account; fail after the account update) → the prior name, email and role links are restored;
  - **complete create and edit** still persist and leave no transaction open;
  - **forged privileged role + failure** → the privileged id is never bound in a pivot write, and nothing remains.
- **Fails-before `309146b` (tests only, no fix):** 3 of 5 failed — create and edit: the failing statement ran at nesting level 1 (no application transaction); forged create: the account was left behind after the failure (the privileged id was still never written). The connection check passed.
- **During the fix:** a first attempt redeclared the framework's trait property, which is a PHP class-composition fatal (caught at `package:discover` on the runner at `c13718e`, before any test ran); replaced by overriding `hasDatabaseTransactions()` at `f4dd8fb`.

## REGRESSION / FOCUSED / FULL / BROWSER

- **Second review, focused, `f4dd8fb`** (`AccountWriteAtomicity|AccountPrivilege|PathBoundary|ImpersonationAction|AccessMatrix|SurfaceInventory|EmailVerificationFlow|NewAffiliateOnboarding|ExportAccess`): **OK (45 tests, 1,058 assertions)**, run `20261021T123209Z-f4dd8fb2c194`; staging 200 before and after.
- **Second review, full isolated suite, `f4dd8fb`:** **OK (611 tests, 7,042 assertions)** (606 earlier + the 5 new atomicity tests), run `20261021T123422Z-f4dd8fb2c194`, first attempt green; staging 200 before and after. No rerun for the later docs-only commit.
- *Earlier rounds, kept as recorded:*

- **Fails-before (original injection), `f3f7c5f`:** the four privilege-injection tests failed; path and denied-impersonation passed.
- **Fails-before (review corrections), `3e311f8`** (updated tests against the earlier after-sync approach): the **persistence-boundary** assertions failed — proving the old approach wrote the forbidden role to the pivot and then removed it within the transaction (*note 2026-10-21: within the same request; no application transaction was active — see ATOMICITY*). (One matrix expectation was also corrected there from a 403/404 literal to business-level allow/deny.)
- **Passes-after, focused, `e7ba59d`** (`AccountPrivilege|PathBoundary|ImpersonationAction|AccessMatrix|SurfaceInventory`): **OK (22 tests, 267 assertions)**.
- **Full isolated suite, `6bf8582`:** **OK (606 tests, 7,016 assertions)**, run `20261021T081844Z-6bf8582aa8e7`; staging 200 before and after. (A first run hit an unrelated, pre-existing flaky collision in `CompanyDossierPdfTest` — a random taxonomy code `CPV-'.random_int(900,999)`, not a file changed this round; the rerun was green. Registered as a reliability flake to de-randomise later.)
- **Browser:** not run. The changed flows are a server-side write guard and a removed route, both covered by HTTP/Livewire tests; the impersonation UI click is a redirect the harness cannot follow (stated as partial coverage).

## REMAINING_COVERAGE_AND_OWNER_DECISIONS

- **Pending test batches (unchanged from round 1):** 20 of 23 relation managers; global search on 12 of 13 resources. To be done in one declared bounded batch next.
- **Owner decisions (no live change made):**
  - **F-02 role editor:** a non-super-admin role-management holder can edit roles. Proposal: restrict role create/update/delete and the role editor to super_admin; impact — ordinary staff lose role editing (believed unused by non-admins); confirm.
  - **F-03:** the affiliate role can create two global catalogs. Proposal: remove those create permissions; impact — none for normal affiliate use.
  - **F-06:** a company-linked user can unlink co-users. Proposal: restrict unlinking to explicit company-user-management authority; no automatic privilege change.
- **Not started:** SEC-02…05 beyond the two surfaces activated here. Managerial work remains on hold.

## PUBLIC_EVIDENCE_SCREEN / RESTRICTED_HANDOFF

- **Public:** test code (synthetic names, generic permissions), the fix, and this sanitized report. Screened: no secret, token, real identifier, role-to-permission mapping, or exploit payload for unresolved paths. The canary targets a disposable temp file, never a real secret.
- **Restricted** (executor-local, outside git/webroot, `%USERPROFILE%\.cpv-restricted\sec-01\`): the raw path-payload results and the finding mechanisms. Handoff on owner request via a private channel; not assumed readable by another executor.

## MANUAL (R2-REV-04)

- **Affiliate manual:** unchanged — nothing an affiliate sees or does changes.
- **Staff-facing change:** a non-super-admin that tries to assign a privileged role now gets an in-UI warning that the role was not applied (the rest of the save succeeds). There is no user-administration help page in the project to carry this; it is recorded in the cumulative register (`docs/security/SECURITY_STATUS.md` §5 R2) rather than invented as a new doc. The removed `/form` surface had no documented use.

## RESUME_ENTRYPOINT

Branch `fix/sec-01-account-path-boundaries` @ REVIEW_HEAD. Read `docs/orquestador/SESSION_RESUME.md`, `docs/plans/SECURITY_REVIEW_PLAN.md` and the cumulative register `docs/security/SECURITY_STATUS.md`, then execute only the next assigned Issue #2 comment. F-01 is accepted (deployment unaccredited); R2 had a second CHANGES REQUESTED (atomicity, R2-REV-05), addressed in this delivery, not yet accepted; neither is deployed. Browser/coverage limits stay as stated (allowed impersonation is a direct manager call, not an end-to-end browser test). Managerial work stays on hold and its frozen planning branch is never promoted.
