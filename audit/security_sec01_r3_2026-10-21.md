# CPV-SEC-01-R3: company relationships, priority searches and permission decisions (sanitized)

- **Authorization:** Issue #2 `6316775771` (owner, 2026-10-21 13:05Z). Previous release: CPV-SEC-01-R1-R2-DEPLOY (`cf01e9c`, delivery `6316560013`).
- **OWNER_UAT (previous release):** PASS, per the owner's chat confirmation dated 2026-10-21, as recorded in `6316775771`. No per-action or browser detail is inferred beyond that confirmation.
- **BRANCH:** `audit/sec-01-r3-company-boundaries`, taken from the verified checkpoint `eb03b4ce089dc49fc3fb180904247647f21ebf94`. Earlier candidates and worktrees are untouched.
- **BASE_SHA:** `eb03b4ce089dc49fc3fb180904247647f21ebf94`. Its runtime equals the deployed `cf01e9c`; only docs were added on top.
- **TESTED_HEAD:** `e95169c47baeb323bf04bfbe4a39272ccdca2cc4` (focused and full isolated suite, see REGRESSION).
- **REVIEW_HEAD:** the commit that adds this report, the register and the checkpoint.
- **Fails-before evidence branch:** `audit/sec-01-r3-fails-before` @ `9fb04695ae90f4a4d0be568ea23ca4dca7e04b1d` = the pre-fix code (`b45fd83`) plus the final R3 test file. It is an evidence-only branch and never a candidate.
- **DEPLOYED:** NO.

> **OWNER ALERT (F-SEC01-R3-04, High, live).** A remaining privilege path defeats the deployed account guard and impersonation gate: a non-super-admin holder of the role-editor permission can make itself privileged. Roles in the real configuration hold that permission. **Fastest mitigation, an owner action with no code:** remove the role-management permissions from every non-super role. The code rule is proposed under PERMISSION_DECISIONS. Mechanism details are restricted.

## RELATION_MANAGER_MATRIX (seven Empresa managers, executed, synthetic)

**Names were verified** against `EmpresaResource::getRelations()`. R1 covered Contacts, Users and Chambers; they are not counted again.

**Actors:**
- ALFA affiliate: verified, linked, with the affiliate's company and module permissions as a literal set;
- BETA affiliate;
- a linked editor without the module write permissions;
- the same ALFA user after its link is revoked, or after its company edit permission is withdrawn, with the page already mounted.

**Oracle:** business literals, not the gate. Refused attempts are driven the way the browser does (mount by name and record key, fill, call) and judged by database state. A refusal must be the safe 404; any other exception fails.

| Manager | Operations exercised | Allowed (ALFA, own) | Denied / invariant |
|---|---|---|---|
| Assets (Recursos) | header «Recursos humanos» save (+ forged `empresa_id`) | saved on ALFA | BETA rows unchanged; no row moved or added to BETA |
| Management (Gestión) | header «Calidad» save (+ forged `empresa_id`) | saved on ALFA | BETA unchanged |
| Experiences | add; edit/delete with BETA's entry key | ALFA entry added | BETA entries unchanged; ALFA did not gain BETA's key |
| Presence | create (+ forged `empresa_id`); edit/delete BETA id; mixed bulk-export selection; editor without presence permissions | created on ALFA | BETA not edited or deleted; export selection resolves only ALFA; delete refused by the module policy for the editor |
| Sustainabilities | create (+ forged `empresa_id`); delete BETA id | created on ALFA | BETA not deleted; shared area catalog unchanged |
| Services (legacy, read-only) | list; detach/delete/edit/attach/create/dissociate on own and BETA keys | own links listed | no such action exists or runs; catalog and links unchanged; BETA link not listed |
| TaxonomyCategories | search-and-add (leaf + family in one request); confirm a self-declared row; confirm / toggle-principal / remove on BETA's row; remove own row | only the leaf is linked, as `self_declared`, with no approver; own link removed | family not linked; self-declared origin not changed; BETA origin, flag and row unchanged; the shared taxonomy rows are unchanged |
| **All seven + the company page** | write after the link is revoked, with the page already mounted | — | **before the fix: written (F-SEC01-R3-01)**; after: every request refused (404), nothing written |
| ~~Assets,~~ Management, Presence | write after the company edit permission is withdrawn, with the page already mounted | — | before: written; after: refused (404). *Corrected 2026-10-21 (review `6318588005`): executed for the Management header save and the Presence delete only.* |
| All seven | manager bound directly to BETA (harness only; defence in depth) | — | before: mounts; after: refused (404) |

**Binding layer.** A manager's owner record is server-issued and `#[Locked]`. Lazy loading carries mount parameters inside a checksummed Livewire snapshot (Livewire v3.8.7). So a browser cannot point ALFA's page at BETA; the direct-binding row covers the harness-only path.

## SEARCH_MATRIX (three resources, declared before reporting)

**Chosen** from the 13-resource inventory by sensitive-data and privilege impact:

1. **CustomerResource.** It holds people's names and e-mail addresses. It is a global admin catalog, not company-owned.
2. **TaxonomyCandidateTermResource.** It holds unreviewed crawler candidates and reviewer notes, which are internal governance data.
3. **RoleResource (Shield).** It holds privilege data. It is in the inventory, but Shield's configuration disables its global search.

**Not counted as new:**
- `UserResource` is not globally searchable at all; that is asserted, but not counted.
- Empresa search was already covered in R1.

The **output** itself was checked (categories, titles, details, counts, links), using synthetic markers only.

| Resource | affiliate | read-only staff (all view permissions) | customer reader only | no permission |
|---|---|---|---|---|
| Customer | none | found (1); link opens (200) | found (1); link opens (200) | none |
| Candidate term | none | found (1); link opens (200) | none | none |
| Role | none | none (configuration), even for a role reader | none | none |

- No actor ever sees the customer's e-mail in titles, details or links.
- A lookup by e-mail finds no person.
- Reviewer notes never appear in the search output.
- Global catalogs are not treated as leaks merely because they are global.

## FINDINGS_AND_FIXES

| ID | Severity | Status | Summary (sanitized) |
|---|---|---|---|
| **F-SEC01-R3-01** | High | **Confirmed and fixed in the candidate** | The company edit page and its relation managers did not re-check company access after the page opened. The link was applied only when the page resolved its record, module policies check permissions only, and several module actions have no authorization of their own. A page, or a saved snapshot of it, opened while linked kept writing after the link was revoked or the edit permission withdrawn. |
| F-SEC01-R3-02 | Medium | **Confirmed in isolation** (restricted probe, 2 modules); **not fixed this round** (fix budget used by R3-01) | Some company-module actions do not apply that module's own write permissions. A linked user with the company edit permission but only *view* rights on a module could still write that module. Bounded by F-SEC01-R3-01's rule (link + company edit permission). Next step: one small authorization fix with a regression. |
| F-SEC01-R3-03 | Low | **Confirmed in isolation** (restricted probe); **not fixed this round** | The server-side guard for company category links checks the taxonomy level but not that the category is active, so a forged identifier can link an inactive category. Next step: one small server-side filter with a regression. |
| **F-SEC01-R3-04** | **High — owner alert** | **Confirmed in isolation** (restricted probe); **not fixed this round**; part of F-02 | A non-super-admin holder of the role-editor permission can make itself privileged in a way **that defeats both deployed guards** (F-SEC01-01 impersonation and F-SEC01-02a account grants), because the role editor does not protect the privileged role's definition. The restricted role snapshot shows that non-super roles in the real configuration hold role-editor permissions, so **this is a live risk on staging**. Mechanism and steps are restricted. See PERMISSION_DECISIONS. |
| Q-SEC01-R3-05 | Governance question | Owner decision | The meaning of the «Confirmada» (`validated`) origin is ambiguous. The manual says a company confirms suggestions, while the approver field is described as the Cámara's approval. No change was made. |
| Q-SEC01-R3-06 | Hygiene | Registered | A tracked PHP `error_log` file exists under the Empresa relation-manager directory, committed in the first commit (the policies directory has one too). Next step: review and remove in a hygiene round. Not read into this report. |

**Fix for F-SEC01-R3-01** (one connected root cause, the round's limit):
- `App\Support\Security\EmpresaAccess::mayEdit()` applies the edit page's own conditions, read fresh: authenticated, verified, linked now (from the pivot), and allowed to update the company.
- The trait `ReauthorizesEmpresaAccess` runs that check in Livewire's `hydrate`, that is on every request after the first, on `EditEmpresa` and on all 10 Empresa relation managers. The 3 R1 managers share the same root cause.
- `ReauthorizesEmpresaOwner` also checks when a relation manager mounts.
- A refusal is the same 404 a user gets for a company that is not theirs.
- No schema, role data or configuration change.

**Legitimate effect:**
- A user who still has access sees no difference.
- A revoked user's open page answers 404 on its next action; reloading leads to the usual «not found».

**Test fixtures adjusted:** five existing test files mounted the tab with users lacking the company edit permission. That permission is required by the edit page, which hosts the tabs, so no browser user could reach those tabs that way. The fixtures now get it. One existing test now accepts the 404 refusal alongside the earlier 403, consistent with the R2-REV-03 oracle where a denial may be 403 or 404.

## PERMISSION_DECISIONS (sanitized proposals; no live change)

This was determined in isolation, through one restricted probe in the disposable runner fixture with synthetic users and roles. Code authorization and role-data changes are kept apart. Real role mappings stay restricted.

**F-02, role editor and privilege delegation (now evidenced by F-SEC01-R3-04, High).**
- *Intended actors:* only a super admin administers roles and their permissions. Staff with user management keep managing ordinary accounts (already enforced by F-SEC01-02a).
- *Allowed/denied:*
  - deny create, update and delete of roles to everyone but a super admin;
  - deny any change to the privileged role's definition, and any change that would make another role privileged, to everyone but a super admin.
- *Minimum rule (code):* `RolePolicy` create/update/delete require the super admin role. A server-side guard on the role save protects the privileged role's definition. The guards (`PrivilegedRoleGuard`, `canImpersonate`) identify the privileged role robustly. Exact rule and tests are in the restricted notes, to be reviewed with the fix.
- *Role-data alternative or complement (owner action, no code):* remove the role-management permissions from every non-super role. This is the **fastest live mitigation**, because the code fix needs a round and a deployment.
- *Workflow impact:* staff who maintain roles today would need a super admin for that. The restricted snapshot shows which roles are affected; restricted handoff on request.
- *Synthetic evidence:* restricted probe (A1–A3, A5–A8 confirmed). A direct self-grant through the editor page (A4) was **not established** by the probe. That is not proof of protection.
- *Rollback:* re-grant the removed permissions to those roles; revert the code commit.

**F-03, global catalog writes by the affiliate role.**
- *Intended actors:* catalogs shared by all companies are maintained by Cámara staff, not by affiliates.
- *Confirmed effect (restricted probe, D):* an actor with the affiliate role's catalog permissions can open the create page of two global catalogs. The catalog names are in restricted evidence.
- *Minimum rule:* a **role-data change only**, removing those two create permissions from the affiliate role. No code change is needed, because the resource policies already honour the permission.
- *Workflow impact:* none known for normal affiliate use. Please confirm no affiliate is expected to add those entries.
- *Rollback:* re-grant the two permissions.

**F-06, unlinking co-users from a company.**
- *Intended actors:* to be defined by the owner. The proposal is that only a holder of explicit company-user management, or a super admin, may unlink another user from a company. Any linked user may still unlink themselves, if the owner wants that.
- *Minimum rule (code):* authorize the users-tab detach on that explicit authority, not on page access alone. It stays inside F-SEC01-R3-01's re-check.
- *Workflow impact:* companies whose users manage colleagues would need that authority assigned. No automatic privilege change.
- *Evidence:* R1 behaviour (unchanged this round). R3 adds that a revoked user can no longer act from an open page.
- *Rollback:* revert the code commit.

**Recommended order:**
1. Owner removes the role-editor permissions from non-super roles **now** (F-02 / R3-04 live mitigation).
2. Next bounded round: the F-02 code rule, plus R3-02 and R3-03 (small, independent).
3. Owner decides F-03 (role data) and F-06 (business rule).

## REGRESSION / FOCUSED / FULL / BROWSER

- **Fails-before (final tests on the pre-fix code), `9fb0469`:** `CompanyRelationManagers|PriorityGlobalSearch` gave 13 tests, of which **exactly the 3 re-authorization tests failed**. Revoked: «assets: the request after revocation is refused (404)». Withdrawn permission: refused. Direct binding: mounted. The 7 manager invariants and the 3 search tests passed.
- An earlier fails-before at `b45fd83`, with the earlier test version, showed the same 3 failures.
- **Focused after the fix, `c6e99ae`:**
  - account, auth, security, company-tab and dossier set: 161 tests, 1 failure;
  - the failure was the NoAplica test's 403-vs-404 status, adjusted at `e95169c`.
- **Full isolated suite, `e95169c`:** **OK (624 tests, 7,201 assertions)** (611 earlier plus the 13 new R3 tests), run `20261021T133500Z-e95169c47bae`, first attempt green; staging 200 before and after.
- **Restricted probe:** one `--skip-suite` run at `e95169c` (`20261021T134051Z`). The probe file was untracked on the runner host and removed afterwards; the runner checkout is clean (0 entries).
- **Browser:** not run. The changed behaviour is a server-side refusal of Livewire requests, covered through the Livewire harness.

## COVERAGE_REMAINING

**Relation managers:**
- The prior inventory says 23. A recount at this SHA gives **24** active (resource, manager) registrations: Empresa 10, plus 14 on other resources. That is a +1 mismatch against the inventory, flagged rather than silently resolved.
- Tested so far: R1 3 + R3 7 = **10**.
- **Remaining: 14 by the recount** (13 by the prior denominator).

**Global search:**
- 13 in the inventory, of which **12 are effectively searchable** (RoleResource is disabled by Shield configuration and now asserted).
- Tested: Empresa (R1), Customer and Candidate term (R3), plus Role as not searchable.
- **Remaining: 9.**

**Unchanged:** SEC-02 (general exports, files, cache), SEC-03 (remaining identity, session and input), SEC-04 (dependencies, infrastructure) and SEC-05 (consolidation). The narrow R2/R3 checks do not complete those phases.

## MANUAL

- **Affiliate manual:** unchanged. A linked user with edit rights sees no behaviour change. The only change is that a page left open after losing access refuses its next action with the existing «not found» answer, and that needs no new instruction.
- **Admin help:** none to amend (none exists for this; see R2). The behaviour is recorded in the register.

## PUBLIC_EVIDENCE_SCREEN / RESTRICTED_HANDOFF

- **Public:** test code for the fixed path and for passing invariants, the fix, and this sanitized report. Screened: no secret, token, real identifier, real role mapping, or reproduction steps for an unresolved path.
- **Restricted** (executor-local, outside git and webroot, `%USERPROFILE%\.cpv-restricted\sec-01\r3\`): the probe script and its output for F-SEC01-R3-02/03/04 and F-03.
  - The probe ran once in the disposable runner fixture, as an untracked file on the runner host, which was removed after the run.
  - Its output stays in the runner's local artifacts, never in git.
  - Handoff is on owner request, through a private channel.

## RESUME_ENTRYPOINT

Branch `audit/sec-01-r3-company-boundaries` @ REVIEW_HEAD. Read `docs/orquestador/SESSION_RESUME.md` and the register `docs/security/SECURITY_STATUS.md`, then execute only the next assigned Issue #2 comment. Managerial work stays on hold.
