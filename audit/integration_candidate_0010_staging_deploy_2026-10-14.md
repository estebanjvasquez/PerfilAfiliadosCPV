# Staging deployment of CPV-INTEGRATION-CANDIDATE-0010

- **Instruction:** Issue #2 comment `6174797031`, the staging execution instruction issued under the existing TASK-0010 staging authorization
- **Deployed SHA:** `9b447eb15e80bddc4958430a8fc60acf5150ff4d`, checked out on staging and built into the running image
- **Accepted runtime candidate:** REVIEW_HEAD `b384e1a` / TESTED_HEAD `6c25bb3`. The delta `b384e1a..9b447eb` touches only `docs/deploy/**` and `audit/**`.
- **Workflow:** "Deploy a Contabo" run `38612101908`, event `push`, **conclusion `success`**, 18:04:53Z to 18:05:31Z. https://github.com/estebanjvasquez/PerfilAfiliadosCPV/actions/runs/38612101908
- **Outcome:** the deploy ran exactly the two expected migrations and nothing else. The four foreign keys are unchanged (same oid and definition). The new table is empty, and the configuration row is unchanged apart from the new null column. Protected counts are identical to the baseline. There were no 5xx responses and no application errors. Labels were verified authenticated, and the request form was opened and **not submitted**.

The deploy is complete and is waiting for the owner's review. Nothing was done in production, on `main`, or to the taxonomy.

---

## 1. Sequence

| UTC | Step | Result |
|---|---|---|
| 17:5x | Fetched refs: runtime `a6e9db4`, candidate `9b447eb`, fast-forward possible, no change under `.github/` | OK |
| 17:58:27 | Pre-deploy state recorded and previous image preserved as `perfilafiliados-app:pre-0010-004d24e` | OK |
| 17:58 | Preflight (same files and hashes as the accepted one), pre-deploy snapshot, post-deploy check run as a negative control | COMPATIBLE / OK / 25 PARAR as expected |
| 18:00:29 | **Preflight repeated immediately before the push** | COMPATIBLE, 0 stops, identical to 17:58 |
| ~18:04 | **Owner** pushed the fast-forward `a6e9db4..9b447eb` from their own terminal, non-force | OK |
| 18:04:53 to 18:05:31 | Workflow run `38612101908` | success |
| 18:07:22 | Post-deploy verification and snapshot | 37 OK, 0 stops |
| 18:10 to 18:17 | Authenticated smoke and browser captures | see §4 |
| 18:19:29 | Post-deploy state recorded | OK |

**About the push.** The session's permission classifier blocked my push to the deploy branch twice, even after the owner's chat authorization. I did not try to get around it. The owner ran the exact command from their terminal: `git push origin 9b447eb…:refs/heads/feature/upgrade-filament-v3`, with output `a6e9db4..9b447eb`. The last preflight before the push ran at 18:00:29Z, and the push came about 4 minutes later. Nothing in between changes the ledger, and the post-deploy baseline (§3) matches the pre-push one exactly.

## 2. Preflight before the push

The preflight ran with the same files that were accepted: SQL sha256 `d70bb7a1…69e3a` and runner `562d852c…7da9c`, taken from `9b447eb`. Both runs, 17:58 and 18:00:29, gave **COMPATIBLE: 51 OK, 21 INFO, 14 BASELINE, 0 stops**. Their full output is **identical** to the accepted run from 17:47, apart from the timestamp line. No baseline count changed, so there was no concurrent activity to explain.

**The previous state is preserved** (`pre_state.txt`):
- staging HEAD `f8a98df`
- container `e26ae9260fc1`, started 2026-10-06T11:43:57Z
- image `sha256:ce97a62f…`, built by the 2026-10-06 deploy of `004d24e`, now tagged **`perfilafiliados-app:pre-0010-004d24e`**. The tag is the only change made on the host, and it only preserves what was there.
- nginx image `sha256:02b1b2a0…`
- `.env` sha256 `085c617c…`, with key names only and no values
- `docker-compose.yml` sha256 `08322da2…`

The recovery procedure is unchanged. No rollback was needed or run.

## 3. What the deploy did (read-only verification after it)

**Verification (`postdeploy_integration_candidate_0010.sql`, 9 blocks, 37 OK, 0 stops):**

| Check | Result |
|---|---|
| V2 Both new migrations registered, in the same batch, which is the latest | `2022_12_02_000000` and `2026_10_09_120000`, batch **59** |
| V3 The latest batch contains **exactly** those two | yes, so nothing else ran |
| V4 Pending migrations for `migrate --force` at the deployed SHA | **none** |
| V5 `taxonomy_category_requests` columns against the migration | 16/16 match in type and nullability |
| V6 Constraints | PK, `empresa_id → empresas(id) ON DELETE CASCADE`, `user_id → users(id) ON DELETE SET NULL`, and the two CHECKs. 5 in total, none missing. |
| V7 Indexes | `_pkey`, `_empresa_idx`, `_status_idx`. 3 in total. |
| V8 `taxonomy_selection_settings.category_request_recipient_email` | `varchar(255)` nullable. The singleton still has 1 row, with **0 rows holding a recipient** (it stays unconfigured). |
| V9 The four deferred foreign keys | still `public.<table>(id)`, NO ACTION, validated, one per column |

**The FK repair issued no DDL.** The snapshot reads `oid` and `pg_get_constraintdef()` for the four keys. Before and after are **identical**: oids 20006, 20011, 20021 and 20016 with the same definitions. A recreated key would have a new oid.

**Snapshot, before vs after (`pre_snapshot.txt` / `post_snapshot.txt`).** These are the only differences:
- `migrations`: 127 → **129**, `max_batch` 58 → **59**. That matches the instruction's arithmetic exactly, because the fresh baseline was still 127/58.
- the latest batch now contains the two new migrations
- `taxonomy_category_requests`: absent → **exists, 0 rows**
- the configuration row: identical values (`id`, `created_at`, `updated_at`, `max_categorias_principales=null`, `max_categorias_secundarias=null`) plus the new key `category_request_recipient_email: null`

**Every protected count is identical:** empresa_taxonomy_category 767 · empresas 406 · taxonomy_audit_log 163 · candidate_concept_links 10 · canonical_concepts 82 · taxonomy_categories 3497 · reviewed_proposals 15 · selection_settings 1 · term_concepts 145 · term_cpv_relations 9749 · taxonomy_terms 1893 · users 366. No concurrent activity showed up.

**On the run's speed (38 s).** The image was built at 18:05:18Z from cached layers, because the candidate does not change `composer.*` or the `Dockerfile`. The ledger confirms that the migrations ran. GitHub only serves the job log with authentication, so the full workflow log was not read. The evidence that the migrations ran is the database itself (V2, V3, V5 to V8).

## 4. Smoke test

### What is solid

**HTTP through Cloudflare and nginx** (`post_state.txt`): `/` 200, `/admin/login` 200, `/admin/manual-de-usuario` as a guest returns 302 to login. Maintenance mode is off.

**Authenticated, in-process (`docs/deploy/smoke_authenticated_readonly.php` → `smoke_authenticated.json`, 24/24 PASS).**

Logging in through the form would have needed a real user's password, and creating a test user would be a write to the shared database. Neither is authorized. Instead, the script runs **inside the app container as `www-data`**, sets an **existing** user on the guard (no login and no persisted session, because session and cache are switched to `array`), and sends **real GETs through the app's HTTP kernel**: routes, middleware, Filament. It all happens inside **a READ ONLY pgsql transaction ending in ROLLBACK**, with `Mail::fake()` and `Notification::fake()`.

| | |
|---|---|
| `/admin` | 200 |
| `/admin/manual-de-usuario` | 200, and it quotes **all five final labels** (`SOLICITUD_ACTION_LABEL`, `_MODAL_HEADING`, `_NECESIDAD_LABEL`, `_JUSTIFICACION_LABEL`, `_TERMINOS_LABEL`), with no provisional marker |
| `/admin/empresas/{id}/edit` for that user's company | 200 |
| Relation manager | the button carries the final label |
| **Modal "Solicitar revisión"** | **opened** (`mountedTableActions = [solicitarRevisionCategoria]`). It shows the heading, the three field labels, the description ("NO crea una categoría") and the submit button. **The submit action was never called.** |
| Requests | 0 before, 0 after |
| Mail | 0 sent or queued |

**Caveat: the user turned out to be `super_admin`, not an affiliate.** The script picks the first existing user linked to a company, and its check is named "usuario afiliado existente", but the roles field it reports says `super_admin`. I tried to repeat the run excluding super_admin, and the session's permission classifier blocked it ("Production Reads"). I did not insist. So the evidence shows the deployed form and labels as **an administrator** sees them. Affiliate-role access to this action is covered by the suite at the accepted SHA (synthetic users, 465 tests), not by this live smoke test.

**Real browser on the public page** (`render/`, Playwright 1.49.1, desktop 1366×900 and mobile 390×844): the login page returns 200, the email field is visible, there is no horizontal scroll and no 5xx. The screenshots `login_desktop.png` and `login_mobile.png` were reviewed by eye.

### What is NOT evidence (kept, marked `manual_static_capture_INVALID/`)

I tried to photograph the **authenticated manual** without logging in. The browser loaded the URL and its main document was replaced by the HTML the app had rendered. **It failed twice, and the first two checks "passed" anyway:**
- v1 (`file://`): the scripts were blocked by the null origin, Alpine never started, and the content stayed hidden. The page came out blank, yet the check passed because it counted DOM nodes.
- v2 (same origin, checking "visibility"): **the captured HTML has no external `<script>` tags.** Livewire and Filament inject them during parts of the request lifecycle that a direct `kernel->handle()` call skips. Alpine is `undefined`, `.fi-main-ctn` has `opacity: 0`, and the screenshot is blank again. Playwright still reports "visible", because it does not treat opacity 0 as hidden.

Both `render_results.json` files say PASS, and **they must not be read as evidence**. That is why they sit in that folder. **Desktop and mobile rendering of the authenticated pages remains unverified on staging.** It was checked in the isolated environment at the accepted code (UX 40/40, comment `6174354488`). Doing it here needs a real authenticated session, which is the owner's decision to make.

### Logs
- **Laravel** (`storage/logs/laravel.log`), since 17:40Z: **2 ERROR entries, both from this session's tooling.** 17:45:16 is `Undefined constant "STDOUT"` (the first preflight attempt, before the deploy). 18:10:05 is a `UrlGenerator … null given` (the first start of the smoke script). **The application's own traffic logged no errors.**
- **nginx** since the push (18:04Z): 38 × 200 and 1 × 302. **No 5xx and no 503.** No request arrived during the maintenance window.

## 5. After the deploy

`post_state.txt`:
- HEAD `9b447eb`
- container `ab1c95c8f527`, started 18:05:29Z
- image `sha256:1502ed3d…`
- the previous image is preserved under the tag
- `.env` and `docker-compose.yml` **have the same hashes as before**: configuration unchanged
- maintenance mode off

## 6. Guardrails kept
- One deploy only: one non-force fast-forward and one workflow run. No workflow edits, no merge of the whole supplhi branch.
- No `migrate:fresh`, rollback, data restore, ledger repair or taxonomy APPLY. No change to `main`, production or secrets. No orchestrator service restarted.
- No form submitted, no mail sent, recipient still null. No user created and no customer data read. Only aggregates and configuration values were read. The user's name and email were replaced in the HTML before it was saved, and that HTML is not in the repository.
- All database checks ran inside READ ONLY transactions ending in ROLLBACK.
- One foreground session, no subagents, no Anthropic reviewers. Workflow status was polled every 30 s, with a limit, for this one run.

## 7. Remaining limitations
1. Desktop and mobile rendering of the authenticated pages was not verified on staging (§4).
2. The live authenticated smoke test ran as `super_admin`. It was not repeated as an affiliate because the permission classifier blocked that run (§4).
3. The full workflow log was not read, because it needs authentication. The proof that the migrations ran is the database itself.
4. The suite ran on PostgreSQL 16.15 while staging runs 17.6. This was declared before and is accepted.
5. Repeating the preflight required **before** the push happened about 4 minutes before the owner's push, and was not part of the same command.
6. This round adds tooling under `docs/deploy/` (snapshot, post-deploy check, smoke script). It is committed on the candidate branch **after** the deploy, with the same hashes that ran. The runtime branch stays on the deployed SHA `9b447eb`.

## 8. Files
- `docs/deploy/snapshot_integration_candidate_0010.sql` (sha256 `d0562d4d…d216`), `docs/deploy/postdeploy_integration_candidate_0010.sql` (`8d9f5cd1…769e`), `docs/deploy/smoke_authenticated_readonly.php` (`d58ec682…adb3`, exactly the version that ran)
- `audit/integration_candidate_0010/staging_deploy/`: `pre_state.txt`, `post_state.txt`, `pre_preflight.txt`, `prepush_preflight.txt`, `pre_snapshot.txt`, `post_snapshot.txt`, `pre_postdeploy.txt` (negative control), `post_postdeploy.txt`, `smoke_authenticated.json`, the empty `*.stderr` files, `render/` (login), `manual_static_capture_INVALID/` and `tools/` (the browser and diagnostic scripts)
