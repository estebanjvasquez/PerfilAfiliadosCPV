# CPV-SEC-01-R2: account privileges and path boundaries (sanitized)

- **Authorization:** Issue #2 `6310273754` (owner, 2026-10-21), activating the account and path checks of SEC-02/SEC-03 for these two surfaces only. Prior round: delivery `6294285848`, review `6297739082`.
- **BASE_SHA:** `0139e954ec22f0a6bfa2377968a6a3af86c15874` (the accepted, frozen F-01 candidate). A new branch was taken from it; the F-01 candidate was not amended.
- **BRANCH:** `fix/sec-01-account-path-boundaries`. Tests fail-before at `f3f7c5f`; the fix and corrections follow.
- **TESTED_HEAD:** `f0f9a18d90ae953706ac3a57b28374a5ba491338` (full isolated suite and focused run).
- **REVIEW_HEAD:** set at delivery — the commit that adds this report and the checkpoint on top of `f0f9a18`.
- **DEPLOYED:** NO.

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

**Canary test:** a harmless canary file was placed OUTSIDE the app root (system temp). Both request-controlled values were driven with traversal payloads — `../`, `..\\`, `....//`, URL-encoded and double-encoded `%2e%2e%2f`/`%252e`, backslash-encoded, absolute, `file://`, and a full URL-encoded absolute path.
- **`{empresa}` segment:** every payload → **404**, no PDF generated, no 5xx, canary content never disclosed, no filesystem path echoed. The legitimate numeric id still downloads.
- **`secciones`:** every payload → the **complete profile** (allowlist fallback), never a file, no 5xx, no leak.
- **Result:** no traversal established on the tested surfaces. This is a negative result for exactly these surfaces, not a claim about all file surfaces. Evidence: `sec01r2_path_*.json` (restricted copy; aggregates here).

**Normalization note:** payloads were sent raw with `rawurlencode` on the segment so routing delivered the encoded value to the controller, exercising the stack's own decoding rather than a client that pre-normalizes.

## INDEPENDENT_EXPECTATIONS_AND_5XX

- Expectations are literal business rules, not the gate under test.
- New tests assert exact statuses; `PathBoundaryTest` fails any 5xx.
- `JoinViews` reliability defect is the one known, explicitly-registered non-security failure (backlog), counted, not hidden.

## FINDINGS_AND_FIXES (opaque IDs; mechanisms restricted)

| ID | Severity | Status | Safe summary |
|---|---|---|---|
| F-SEC01-R2-A (= F-02 account facet) | High | **CONFIRMED and FIXED** (not deployed) | A non-super-admin who can manage users could grant the privileged role (on create, on edit, or to itself) and could open/modify a privileged account. Fixed server-side at the user write boundary. |
| F-04 | Low | **FIXED** | The unused public Livewire test form `/form` was removed (route, class, view), with a route regression. |
| F-05 | Medium (suspected) | **Not reproduced; redundant code removed** | The only browser-callable method on the user edit page returned data to the framework, not the browser, and the blank-password case is already handled by the form. The redundant lookup was removed; a blank-password edit keeps the old hash (tested). No credential exposure reproduced. |
| F-07 | Low (SEC-03 lead) | Confirmed benign here | `User::$fillable` includes `email_verified_at`; no panel form exposes it, and registration does not self-verify (tested). Left for SEC-03; not changed, to avoid breaking factories/verification. |
| F-02 (role editor) / F-03 (affiliate catalog creates) / F-06 (co-user unlink) | — | Owner decisions | Live role data and business-rule decisions. No live role changes were made. Sanitized before/after proposal below. |

**Cap:** one connected high-risk root cause fixed (privilege grants at the user write boundary), plus the bounded F-04 cleanup. Others registered.

## FIX (F-SEC01-R2-A)

- **`GuardsPrivilegedRoles`** trait on `CreateUser` and `EditUser`:
  - only a super admin may grant or remove the privileged role, or open a privileged account;
  - enforced after the relationship sync (`afterCreate` / `afterSave`), because the role Select saves separately from mass assignment;
  - the privileged-account open is blocked in `authorizeAccess` (mount and every hydrated request);
  - checks hit the role pivot directly (not the form's cached relation), and the actor's grant authority is snapshotted BEFORE the write, so a self-grant cannot flip it.
- **Preserved:** super admins keep every operation; ordinary staff still create and edit ordinary users and assign non-privileged roles; passwords stay hashed and hidden; registration, verification and reset are untouched.
- **Scope:** the two panel pages only — not a global model event — so factories, seeders and other trusted writers are unaffected.
- **Also:** removed the redundant `EditUser::mutateFormDataBeforeSave` (F-05) and the `/form` surface (F-04).

## REGRESSION / FOCUSED / FULL / BROWSER

- **Fails-before:** `f3f7c5f`, focused (`AccountPrivilege|PathBoundary|ImpersonationAction`): 4 privilege-injection failures; path and denied-impersonation pass.
- **Passes-after, focused:** `f0f9a18` (`AccountPrivilege|PathBoundary|ImpersonationAction|AccessMatrix|SurfaceInventory`): **OK (22 tests, 264 assertions)**.
- **Full isolated suite:** `f0f9a18`, **OK (606 tests, 7,013 assertions)**, run `20261021T074038Z-f0f9a18d90ae`; staging 200 before and after.
- **Browser:** not run. The changed flows are a server-side write guard and a removed route, both covered by HTTP/Livewire tests; the impersonation UI click is a redirect the harness cannot follow (stated).

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

## MANUAL

Unchanged. Affiliate behaviour is the same; the guard only limits privileged-role grants between staff accounts, and the removed `/form` had no documented use.

## RESUME_ENTRYPOINT

Branch `fix/sec-01-account-path-boundaries` @ REVIEW_HEAD. Read `docs/orquestador/SESSION_RESUME.md` and `docs/plans/SECURITY_REVIEW_PLAN.md`, then execute only the next assigned Issue #2 comment. F-01 and this round are accepted-pending-deployment; managerial work stays on hold and its frozen planning branch is never promoted.
