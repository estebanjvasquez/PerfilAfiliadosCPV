# TASK-0010C: operating category requests

- **Instruction:** Issue #2 comment `6187768951`
- **Branch:** `feature/task-0010c-request-operations`, created from the remote runtime `9b447eb15e80bddc4958430a8fc60acf5150ff4d`. No push to `feature/upgrade-filament-v3` and no deploy.
- **TESTED_HEAD:** `349ea61078fe5b6b88abc0a90333958c0ea9f220`
- **REVIEW_HEAD:** the commit that adds this report on top of TESTED_HEAD. The delta is audit-only (this report, `audit/task_0010c/**`, `audit/orchestrator_handoff.json`).
- **Harness:** `infra/contabo-test-runner @ 246b0d073ae486cfe27d97f3a2e6268328a3b8cd`, run `20261015T085118Z-349ea61078fe`
- **Result at TESTED_HEAD:**
  - **suite `OK (481 tests, 2869 assertions)`** (full Unit+Feature, no failures, errors, risky tests or deprecations);
  - **browser 55/55**;
  - isolation 19/19, schema prep 14/14, fixtures 8/8;
  - governed taxonomy tables unchanged;
  - staging 200/200;
  - no leftover containers.

Request #1 on staging was **not touched**. No SMTP was configured, no real mail was sent and no secret was read or changed.

---

## Part 1: closing the 0010 post-deploy report (point "CURRENT STATE")

Committed on `feature/integration-candidate-0010` at `8a7664f8897d7414408a31cbd0f6880840e15f2a`: `audit/integration_candidate_0010_staging_deploy_2026-10-15_followup.md`. Read only, with aggregates only:

| | |
|---|---|
| Staging | still `9b447eb`; no new push, deploy or run |
| Requests | **1** (#1): `pending_review`, notice **`failed`** / **`recipient_not_configured`**, no recipient, no send date |
| Recipient configured | **no** |
| Transport | **`MAIL_MAILER=log`**, the only key read; there are no SMTP values to read |
| Laravel log | 1 `WARNING` 08:16:02, "registrada sin correo - destinatario no configurado" |
| Protected counts | identical to the deploy baseline, except `taxonomy_category_requests` 0 → 1 |

The owner's screenshot matches the database's status code. The deploy report's assumption of an empty table no longer holds because of #1, and nothing was deleted to make the numbers match.

## Part 2: what TASK-0010C implements

### 1. "Solicitudes de categoría" (Taxonomía CPV)
- A **read-only** list:
  - number, date, company, need, reason, review status, notice status and failure reason;
  - search by number, company or need, and filters by notice, review and reason;
  - newest first;
  - a menu badge counting notices that are `failed`/`pending`.
- **Detail:**
  - the full request (reason, need, detail, terms tried);
  - company and requester **from the stored snapshot**;
  - the categories the company had when it submitted;
  - the notice block with "Qué hacer" guidance and a link to the settings page;
  - the **notice history**.
- **No create, edit or delete** (the policy returns false for all three), and nothing publishes categories.
- **Access only for Super Admin through `TaxonomyCategoryRequestPolicy`**, registered explicitly. It is a role check and not a Shield permission, so it cannot be assigned to other roles from the roles screen. The policy also protects direct URLs (403 checked in PHPUnit and in the browser), and the service checks the role again before a retry.

### 2. "Configuración de solicitudes"
- A new page (`/admin/configuracion-de-solicitudes`), Super Admin only, which is **the only field** for the recipient. It uses the same column as before.
- It shows the **server's sending mode** by name only (`log`/`array`/`smtp`) and what it means. No host, user or credential.
- "Límites de selección" **keeps its URL and its role restriction**. In place of the field it shows a link, and its `save()` writes only the two limits, so it can no longer overwrite the recipient (tested).
- Saving the recipient **does not resend** earlier requests, and the confirmation message says so.

### 3. "Reintentar aviso"
- Only for `failed` or `pending` requests, and only for Super Admin. It is disabled when no recipient is configured, with a tooltip and guidance.
- The confirmation shows **the recipient** and **what the sending mode means**, and states that no other request is created.
- It reuses **the stored request and the same Mailable** (the snapshot). A test changes the company's name after submission and confirms the mail goes out with the original name. **No request is created and none is rewritten** (tested field by field).
- **Concurrency:**
  1. A conditional `UPDATE` of a single statement reserves the request with a token.
  2. The mail is sent **with no transaction or lock open**.
  3. A short closing transaction records the attempt and releases the reservation only if it still owns it.
  4. An abandoned reservation expires after 10 minutes, so the request stays recoverable.
- **Audit:** the new table `taxonomy_category_request_delivery_attempts` records number, type (initial, manual retry or historical), actor, recipient, mode, result, code and times.
- **Nothing retries automatically.**

### 4. Honest transport
- New state **`simulated`**: with `log`/`array` nothing leaves the server, and that state is now recorded instead of `sent`.
- **`sent`** now reads **"Aceptado por el servidor de correo"**, and the admin text states that this does not confirm delivery to the mailbox.
- For a simulated notice the **affiliate** sees the same message as for a pending notice, with their number. The transport, "simulado" and "servidor" never appear (tested).
- `mail_sent_at` is set **only** for `sent`.
- The global `MAIL_MAILER` was not changed.

### 5. Missing recipient
The request is still saved. The list and the detail explain what to configure, with a link. Configuring the recipient does not resend anything.

On staging, **#1 becomes visible** once deployed, and gets **one `historical` history row** derived from what it already had (§4). The migration does not change #1 itself. **No retry of #1** is made or proposed until the owner decides how the recipient will be handled.

### 6. Manuals
- **Affiliate manual** (`manual_usuario.md`, seen by everyone):
  - the **request number** is the reference;
  - what **"Solicitud registrada, aviso por correo pendiente"** means: it is registered, the Chamber will still review it, and there is no need to send it again;
  - the Chamber sees requests in its own panel.

  It does not mention transport, simulation or retries (tested).
- **Admin help** (`ayuda_solicitudes_admin.md`, a separate page, Super Admin only, reached from the "Ayuda" buttons):
  - exact navigation;
  - recipient configuration;
  - the four states and the two failure reasons;
  - manual retry and the history;
  - what the company sees.

  It contains no repository paths, secrets or server configuration (tested).

### Migration `2026_10_15_120000_add_delivery_operations_to_taxonomy_category_requests` (additive)
- Columns: `delivery_transport`, `delivery_claim_token`, `delivery_claimed_at`.
- The status CHECK is widened to `pending|sent|failed|simulated`; any other value is still rejected (tested).
- The attempts table, with a UNIQUE on (request, number) and CHECKs on type and result.
- **Backfill:** it only **inserts** one `historical` row for requests already evaluated. It **never updates** a request. Running it twice inserts nothing new (tested: an existing request and the settings compared byte for byte before and after).
- `down()` refuses if any `simulated` requests exist, instead of rewriting them.

## Tests

| | Tests | Assertions |
|---|---|---|
| Accepted integrated candidate (`6c25bb3`) | 465 | 2698 |
| **TASK-0010C (`349ea61`)** | **481** | **2869** |

**+16 new tests** in `tests/Feature/Filament/TaxonomyCategoryRequestOperationsTest.php`:
1. list and detail, with an existing failed request visible;
2. line breaks and escaping in the detail;
3. affiliate blocked from list, detail, settings and help, **also by direct URL (403)**;
4. nobody can create, edit or delete;
5. retry disabled without a recipient, and the service refuses without recording anything;
6. a retry resends the original request (snapshot) **without creating or rewriting** anything;
7. a second retry never sends a second notice;
8. a concurrent retry is refused while another holds the reservation, and an abandoned reservation expires;
9. only Super Admin can retry, even calling the service directly;
10. a failed retry is recorded and stays retryable;
11. `log` and `array` are recorded as `simulated`, never as `sent`;
12. under simulation the affiliate sees "pending" with their number and no transport;
13. the settings page shows the mode without secrets, and saving does not resend;
14. admin help and affiliate manual each with the right content;
15. viewing or retrying writes only the request and its history, never a taxonomy table;
16. the migration preserves the existing request and settings and only backfills history.

**Existing tests changed, each with its reason in the code:**
- `TaxonomyCategoryRequestTest` (2) and (6) set `mail.default=smtp`. They assert `sent`, which now requires a transport that sends externally. With PHPUnit's `array` the honest result is `simulated`, and `Mail::fake`/`shouldReceive` prevent any network send.
- `TaxonomyCategoryRequestTest::super_admin_can_configure_…` moves to the new page. It now also asserts that "Límites" no longer has the field and that saving the limits keeps the recipient.
- `TaxonomyCategoryRequestTest::a_request_never_writes_…` also allows the history table, explicitly. It still requires that no taxonomy table is written.
- `TaxonomyCategoryRequestsMigrationTest` (two cycles) reverts 0010C before 0010A and reapplies it afterwards. The history table's FK requires that order, as a real rollback in reverse order would.

**Isolated runs, in order** (nothing hidden):
1. `418b37b`: **PHP fatal**, see Finding A.
2. `e7e11a8`: **2 errors and 3 failures**:
   - the 0010A migration test was not rolling back in order;
   - the 0010A write test did not know about the history table;
   - the deferred list test needed `loadTable`;
   - JSON was escaping "ó".
3. `b6d686f` filtered: `OK (47 tests, 531 assertions)`.
4. `321e97f` full: `OK (480, 2865)`.
5. **`349ea61` full: `OK (481, 2869)`** ← TESTED_HEAD.

## Browser (desktop 1440 / mobile 390), `ux_check_0010c.mjs`: 55/55

This ran in the disposable stack with synthetic users, a synthetic company and a synthetic failed request. The test app's transport is `array`, so nothing can leave the container. What it checked:
- A guest is sent to login on the 3 screens. The affiliate gets **403** on all 3, sees none of the request's content, and the item does not appear in their menu (the menu was confirmed as actually read).
- On mobile and desktop, the list, detail, settings and help each show **visible content (`opacity=1`, measured)**, **no page-level horizontal overflow** and no internal strings.
- The missing-recipient warning is shown, and the retry is **visible but disabled** without a recipient.
- "Límites" links to the settings page instead of having a second field.
- Settings shows "Simulado (modo «array»)", saves and confirms.
- After that the request **is still failed** (no automatic resend). The retry becomes **enabled**. The confirmation **names the recipient**, **explains the simulation** and says **no other request is created**. The result is reported as **simulated, not as sent**. The history shows the manual retry.

**The first browser run gave 50/54.** Three failures were defects in my script: the menu read with `innerText` from the wrong `nav`, and the "Falló" badge matched the hidden filter option. **One was a real interface defect:** a simulated notice still showed the label *"Aceptado por el servidor de correo"*. That label belonged to the date field, but it repeated the status text word for word. It is fixed in `321e97f`.

On the screenshots I also fixed the title-cased "Solicitudes De Categoría" and the blank lines in the detail (`349ea61`). The screenshots in `audit/task_0010c/run_349ea61/ux/` were **reviewed by eye**.

The harness gained `--ux-script` and the synthetic Super Admin and request seed (`infra/contabo-test-runner` `d6533d0`, `246b0d0`). The harness's own "RESULT ux" summary line still says "3 widths x 2 schemes", left over from the manual's check. The real detail for this run is in `ux_results.json`: 2 widths, light theme.

## Findings to review

**A. A dormant PHP fatal on staging since `a5265ce` (2026-09-15), not fixed here.** `EmpresaPgsql::taxonomyCategories()` (`belongsToMany`) is incompatible with `Empresa::taxonomyCategories(): HasMany`, so **loading the `EmpresaPgsql` class at all is fatal**. The `empresa()` relation of `CompanyCrawlRun`, `CompanyPage`, `CompanyTermMatch`, `EmpresaTaxonomyCategory` and `TaxonomyCategoryRequest` use it. No current screen loads it, which is why it never showed. My first version of the list did load it, and the first isolated run died.

0010C avoids it: the list uses the company name from the snapshot. The class itself is left untouched because it is outside this task's scope. **It needs its own task**: any future screen or job that touches one of those relations will fail with a 500.

**B. Concurrency is tested at the level of the reservation, not with two real processes.** The tests reproduce an active reservation and one that has expired. The guarantee that two simultaneous `UPDATE`s cannot both claim the row comes from PostgreSQL row locking on a single conditional statement. It was not measured with two simultaneous HTTP requests.

**C. A `simulated` notice is not retryable.** That follows the instruction, which limits retry to failed/pending. If SMTP is enabled later, notices that were simulated under `log` would need a separate decision (for example, allowing their retry once).

## What a future deploy of C would do (not authorized, not executed)
- **One pending migration:** `2026_10_15_120000_…`. On staging it would widen the CHECK, add three nullable columns, create the history table, and **insert one `historical` row for #1** (`failed`, `recipient_not_configured`, mode not recorded). **#1 does not change.**
- With `MAIL_MAILER=log`, any retry after configuring a recipient would be recorded as **`simulated`**, honestly, and no mail would leave. SMTP needs a separate owner decision with provider and recipient, provisioned securely.
- Before deploying it would need its own preflight, of the kind used for 0010.

## Guardrails
- No push to `feature/upgrade-filament-v3`, no deploy, no main or production.
- No SMTP configured, no real mail sent, no recipient set on staging, and no retry of #1.
- Request #1 and the data on staging were not changed. The only reads were aggregates in a READ ONLY transaction.
- No secrets read or changed (only the `MAIL_MAILER` key). No taxonomy APPLY. No merge of unrelated branches. No orchestrator reactivation.
- One foreground session, no subagents, no polling and no Anthropic reviews.
