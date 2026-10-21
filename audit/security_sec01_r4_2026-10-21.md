# CPV-SEC-01-R4-ROLE-ADMIN: role administration exclusively for super_admin (sanitized)

- **Authorization:** Issue #2 `6318625636` (owner, 2026-10-21 14:32Z), following delivery `6317664383` and review `6318588005`.
- **Owner rule (binding, 2026-10-21):** only a user who currently holds the configured `super_admin` role may administer roles. A generic permission or access to user management is not an exemption, and a lesser role must not be able to alter the privileged role's definition.
- **BRANCH:** `fix/sec-01-r4-role-administration`, from the frozen accepted R3 candidate `66f5ce8192f20282238b0727d2a6f40807703d6c`. Ancestry was verified; R3 was not amended.
- **BASE_SHA:** `66f5ce8192f20282238b0727d2a6f40807703d6c`.
- **TESTED_HEAD:** `b51fa3ecd21c85d65e489c7bf195f98791135da5` (see REGRESSION).
- **REVIEW_HEAD:** the commit that adds this report, the register, the admin help and the checkpoint.
- **CODE_DEPLOYED:** NO. Staging still runs `cf01e9c`. R3 (`66f5ce8`) is accepted and is **not** deployed either.

## A. CONTAINMENT (live role data): **BLOCKED — not performed**

- **What happened:** the read-only pre-check ran, but the single authorized write was **refused by this executor's platform permission classifier** («remote shell writes»). It was not bypassed and no other route was tried. **No live role or permission data was changed.**
- The owner was alerted at once in `6318733204`, with three ways forward.
- **Pre-check** (read-only, `SET TRANSACTION READ ONLY`, on the verified deployment `cf01e9c`, connection `pgsql`, guard `web`):
  - **Permission set:** derived from the installed resource. Shield's resource prefixes on the `role` identifier give the 12 abilities `RolePolicy` checks; 6 of them exist as permissions. No wildcard.
  - **Holders outside super_admin:** 2 non-super roles, with 6 permissions each, giving **12 role-permission assignments**. The roles have 1 user and 0 users. **0 direct user grants.** No similarly named role exists under any other guard.
  - **Admin continuity:** the super_admin role holds all 6 itself. Its **4 verified accounts** hold role administration through that role alone.
  - **Stop conditions:** none triggered. The subtraction would have been one transaction, asserting exactly those 12 rows and aborting on drift, followed by the application's permission-cache reset.
- **Restricted** (executor-local, outside git and webroot): the before-snapshot, the reviewed apply script and the inverse plan. Restoring anything requires owner approval.
- **Status:** the **R3-04 live risk remains** until the owner acts or allows the write. The fastest route, in the app as super_admin, is: **Roles** → each non-super role → uncheck its **Role** permissions → save.

## A2. MANUAL CONTAINMENT: owner-confirmed, then VERIFIED read-only (`6319443716`)

- **Review `6319407886`:** code accepted at `97737ba`, not deployed; containment still blocked at that point.
- **Owner confirmation (`6319443716`):** the owner removed the Role permissions from the non-super roles in the app. This is recorded as owner-confirmed and was then verified independently.
- **Verification** (2026-10-21 ~15:20Z, runtime `cf01e9c`, `SET TRANSACTION READ ONLY`, no write and no cache mutation; if the cache entry had been absent, the probe would have used an in-memory store):
  - **Recomputed:** 12 derived names, 6 existing permissions, 0 wildcards. The earlier snapshot was not reused.
  - **Outside super_admin:** **0 role assignments** (12 before) and **0 direct user grants**.
  - **super_admin continuity: PASS.** One super_admin role holds all 6, and all **4 verified accounts** pass every role-administration gate check.
  - **Cache and access:** `file` store; the entry is present and **matches the database** (0 non-super pairs, 6 super pairs), so the UI saves invalidated it and it was rebuilt. In a fresh process (what the next request sees), the deployed gate gives **0 of 325** non-super users any role-administration ability. Staging returns 200.
  - **Unchanged:** 5 roles, 329 memberships, 366 users. super_admin and the two roles that never held Role permissions are identical to the 2026-10-20 restricted baseline.
- **Collateral effect, F-SEC01-R4-01** (opened in the register):
  - Shield's role editor syncs only the permissions it displays. Because the custom-permission tab is disabled, saving a role **silently drops** every permission the form does not show. Staging has 116 such permissions, and 73 of them are referenced in code.
  - Against the restricted 2026-10-20 baseline:
    - ~~the role with no users lost 115 hidden permissions besides its Role ones;~~
    - ~~the role with one user lost 1 permission the app checks, in user management.~~
    - **Attribution corrected in `6320241495`:** the defect removed **109 hidden permissions, all on the role with no users**. The role with one user held no hidden permissions and lost none to the defect. Separately, the saves cleared **visible** checkboxes outside Role: 6 user-management ones on the unoccupied role, and 1 (the user list) on the occupied role. See `audit/security_sec01_r4_01_2026-10-21.md`.
  - Nothing was added. This only reduces access; it is not an exposure.
  - The exact diff is restricted. Restoring anything needs owner approval.
  - **Until the editor is fixed, do not save the super_admin role in it.** It holds all 116 hidden permissions.
- **Restricted:** the verification scripts, the after-state and the diff, all in `r4/` outside git.

## B. SERVER_SIDE_SURFACE_AND_RULE

**Installed role-administration surface** (Shield 3.9.10, `RoleResource`; vendor not edited):
- **Pages:** List, Create, View and Edit.
- **Actions:** the table's Edit and Delete and its bulk Delete, the Edit page's Delete, and the View page's Edit.
- **Permission editor:** the role form. `EditRole`/`CreateRole` `afterSave`/`afterCreate` sync permissions and *create* any permission named in the form data.
- **Search:** global search is disabled by configuration.
- All of it is authorized through the app's `RolePolicy` (registered in `AuthServiceProvider`). That is Shield's supported extension point, and it is where the rule now lives.

**Rule (code):**
- **`RolePolicy`:** every ability (view-any, view, create, update, delete, delete-any, force-delete, restore, replicate, reorder) requires `PrivilegedRoleGuard::userHasPrivilegedRole($user)` **and** the explicit permission.
  - The super_admin check is a fresh pivot query on each request. A page opened before the role was removed is therefore refused on its next request, even if the user still holds the generic permissions directly.
  - The explicit permission stays required because Shield's gate bypass is off here (`define_via_gate = false`).
- **`PrivilegedRoleDefinitionGuard`** (registered at boot) applies at the model boundary, to Eloquent writes that dispatch model events. It is not a database constraint and does not intercept raw SQL, query-builder mass updates or deletes, `saveQuietly` or `withoutEvents` (precision requested in `6319407886`):
  - the privileged role cannot be renamed, moved to another guard or deleted;
  - no other role may take its name, under any guard;
  - creating it when none exists, under the default guard, stays possible for setup;
  - its permissions remain editable by a super admin;
  - a refused change raises an authorization error (403) and changes nothing.
- **Unchanged:** ordinary user management (R2), the privileged-grant guard (F-SEC01-02a), the impersonation gate (F-SEC01-01), company re-authorization (R3) and per-company export.

## C. REGRESSION / FOCUSED / FULL / BROWSER

`tests/Feature/Security/RoleAdministrationTest.php` (5 tests) uses independent literals, synthetic users and roles, and state asserted before teardown:
- **HTTP**, for list, create, view, edit and edit-of-the-privileged-role:
  - guest goes to login, and unverified goes to verification;
  - affiliate, no permission, user manager, a non-super with every role permission through a role, and one with them as direct grants are all **denied**;
  - super_admin gets **200**;
  - the gate is also checked for update, delete, replicate, force-delete, restore, view-any, create, delete-any and reorder.
- **Lesser actors' Livewire paths:**
  - rename its own role to the privileged name and add user-management permissions;
  - rename the privileged role;
  - create a role;
  - delete through the table, bulk delete (mixed with the privileged role), and delete from the Edit page.
  - Result: **every role name, guard and permission link is unchanged**, no role is created and there is no self-elevation.
- **Revocation after mount:** a super admin who also holds the generic permissions directly opens Edit and List, then loses `super_admin`. Their next requests (field update and save; table delete) are refused, nothing changes, and the gate denies.
- **Allowed super-admin path:** list (200); create with a permission; rename and replace permissions; delete; bulk delete of two disposable roles. The privileged role remains.
- **Identity:** rename, guard change and deletion of the privileged role; another role taking its name; creating a same-name role under another guard; renaming it through the page as a super admin. Each was asserted immediately, and all were refused and left unchanged.

Runs:
- **Fails-before at `4953be6`** (final tests, unfixed code): **4 of 5 fail**, which are the 4 protections. The allowed super-admin path passes.
- **Focused at `7896bbf`:** role, account, impersonation, matrix, inventory, path, company-tab, search, export, verification, dossier and onboarding set. 115 tests, with 2 non-passing:
  - the matrix's independent oracle still encoded the old rule for the role list (staff and the role manager allowed); it was updated to the owner's rule at `b51fa3e`;
  - the registered random-code `CompanyDossierPdfTest` collision, unrelated.
- **Full isolated suite at `b51fa3e`:** **OK (629 tests, 7,344 assertions)** (624 plus the 5 new), run `20261021T144952Z-b51fa3ecd21c`, first attempt green; staging 200 before and after. Not rerun for the docs-only REVIEW_HEAD.
- **Browser:** not run. The rule is server-side and covered by HTTP and Livewire tests.

## D. RELATED FINDINGS AND ACCOUNTING CORRECTIONS (requested in `6318588005`)

- **Relation-manager denominator, resolved:** **24 relation managers** are registered, as exact (resource, manager) pairs at this SHA. R1's inventory listed **23 entries** because `CountryResource` registers its States and Cities managers inside one `RelationGroup`, which the inventory counted as a single entry. No registration changed since R1. **Tested: 10 (R1 3 + R3 7). Remaining: 14.**
- **Search accounting, separated:**
  - **12** resources are eligible for global search;
  - **3** have had an enabled-search execution (Empresa in R1; Customer and TaxonomyCandidateTerm in R3);
  - **1** disabled resource was tested as not searchable (Role). That is valuable, but it is **not** an enabled-search execution;
  - **9 enabled resources remain.**
- **R3 revocation claim, corrected** to the executed cases:
  - *link revoked with the page open:* Assets header save, Management header save, Experiences delete, Presence delete, Sustainabilities delete, TaxonomyCategories remove, and the company page's field update and save (7 cases);
  - *company edit permission withdrawn:* **Management header save and Presence delete only**, not Assets as R3's table said;
  - *manager bound to another company:* all seven, at mount.
- **Unchanged, next bounded round:** F-SEC01-R3-02 (module write checks) and R3-03 (inactive categories). F-03 (global-catalog role data) and F-06 (co-user unlink) remain separate owner decisions.

## E. MANUAL_ADMIN_HELP

- `docs/manual_usuario/manual_administrador.md` §9.1 «Quién administra los roles». It covers the exclusive rule, the staff workflow that is no longer available, the protected privileged role, what happens after removal, and what stays unchanged. It is linked from the index and from the role table.
- The affiliate manual is unchanged, because nothing an affiliate sees changes.

## REMAINING_RISKS / LIMITATIONS

- ~~**Live risk (High) remains until containment is applied:** the role-editor path can still bypass the deployed guards on staging (`cf01e9c`).~~ **Contained in the data and verified (A2).** The code repair (accepted, `97737ba`) is **not deployed**, so re-granting the Role permissions to another role would reopen the path.
- **F-SEC01-R4-01** is open: the role editor drops hidden permissions on save (A2).
- Removing the named grants (containment) and the code repair are separate protections. Neither substitutes for the other; both are needed.
- Arbitrary active sessions are not claimed to be invalidated. Each request re-checks after the code repair.
- No browser run.

## PUBLIC_EVIDENCE_SCREEN / RESTRICTED_HANDOFF

- **Public:** the rule, the tests, sanitized counts and this report. Screened: no role mapping, no role or account identifiers, no reproduction steps of an unresolved path, no secret.
- **Restricted:** `%USERPROFILE%\.cpv-restricted\sec-01\r4\` (snapshot, apply script, verification script, inverse plan). Handoff is on owner request, through a private channel.

## RESUME_ENTRYPOINT

Branch `fix/sec-01-r4-role-administration` @ REVIEW_HEAD. Read `docs/orquestador/SESSION_RESUME.md` and the register, then execute only the next assigned Issue #2 comment.
