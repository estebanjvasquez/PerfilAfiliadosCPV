# CPV-VERIFICATION-FLOW-AND-COMPLETION: email verification without a 500, and the cached completion after «No Aplica»

- **Authorization:** Issue #2 `6215451160` (owner, 2026-10-16 12:25Z). It covers implementation and isolated tests. **No** deployment. SMTP/provider configuration is deferred to another round.
- **Branch:** `fix/verification-completion`.
- **BASE_SHA:** `10616afcf0b6f75248bd54a0903d93b3a9b51d85`. This is the accepted integrated candidate `fix/first-save-postgres` (TESTED_HEAD `b9b41a0`, accepted in `6215360219`).
  - Verified before branching, read-only (12:27Z):
    - the remote `fix/first-save-postgres` is at `10616af`;
    - `feature/upgrade-filament-v3` is at `0f8bdad`;
    - the staging checkout `/opt/perfilafiliados` is at `0f8bdad`, with 0 tracked modifications. Six untracked directories under `public/css|js` are the published Filament assets.
  - The worktrees `wt-task-0010a`/`wt-task-0010b` and the runner worktree are untouched.
- **TESTED_HEAD:** `3e684c569c035bc2944319f63996b45cbceab902`. The focused and full suites ran at this SHA (§6).
  - The browser check ran at `3f639b7`. `git diff 3f639b7 3e684c5` touches only the manual text, its anchor test and `docs/deploy/CORREO_DEL_SISTEMA.md`.
- **REVIEW_HEAD:** the commit that adds this report. It is documentation and evidence only.
- **Deployed:** NO.

## 1. Investigation

### A. Unverified user

- **Panel:** `AdminPanelProvider` put Laravel's `EnsureEmailIsVerified` in `authMiddleware`, but never enabled Filament's `->emailVerification()`.
  - The middleware redirects to `verification.notice`, which does not exist. So any authenticated, unverified user got **HTTP 500** on every panel page.
- **Registration was broken too.** This was found while testing.
  - Filament's `Register::register()` dispatches **`Filament\Events\Auth\Registered`**, not Laravel's event. So the app's `SendEmailVerificationNotification` listener never ran.
  - Filament then builds its own notification with `Filament::getVerifyEmailUrl()`, which needs the route `filament.admin.auth.email-verification.verify`. That route did not exist either.
  - **Before the fix**, a registration through the real page threw `RouteNotFoundException: Route [filament.admin.auth.email-verification.verify] not defined`. That happened after the user had been created and committed (`audit/runs/verification_completion/prefix/phpunit.txt`).
- **Mail:** `MAIL_MAILER=log` on staging; `array` in the isolated stack. Neither sends anything outside the server.

### B. «No Aplica» and the cached completion

- **Callsites of `EmpresaModuleStatus::setStatus()`:**
  - `NoAplicaAction` (whole module, 5 tabs);
  - the section toggles of Gestión and Recursos (sub-type);
  - the five models' `created` hooks.
- **The «on» path:** `updateOrCreate` → the model's `saved` → `EmpresaCompletionObserver` → `Empresa::refreshCompletionPercentage()`. The cache stays correct.
- **The «off» path:** a **query-builder** `delete()`. No model event fires, so there was no refresh: `empresas.completion_percentage` stayed stale until the scheduled `empresas:refresh-completion --only-stale`, every 15 minutes.
  - **Reproduced before the fix:** the cache was 25 against a fresh 13, in all five modules.
  - **Sub-type paths:** they were masked in practice. The section save that follows saves the module row, and that save refreshes the cache.
- **What is affected:** the badges **Completado: N%** and «Completado del perfil» compute live from `moduleBreakdown()`, so the screen was right after a reload. The stale value was the cached column itself.
  - Today it is read by the reconciliation command. The fix keeps it consistent for any reader.
- **`refreshCompletionPercentage()` already sets `timestamps = false`.** A cache recalculation does not touch `empresas.updated_at` (the «profile freshness» date).

## 2. Changes (runtime, `10616af..3e684c5 -- app resources`)

### A. Verification

| File | Change |
|---|---|
| `app/Providers/Filament/AdminPanelProvider.php` | `->emailVerification(App\Filament\Pages\Auth\EmailVerificationPrompt::class)`; Laravel's `EnsureEmailIsVerified` removed from `authMiddleware` |
| `app/Filament/Pages/Auth/EmailVerificationPrompt.php` + `resources/views/filament/pages/auth/email-verification-prompt.blade.php` | Filament's prompt page, in Spanish, with honest wording and a «Cerrar sesión» button |
| `app/Models/User.php` | `sendEmailVerificationNotification()` sends Filament's `VerifyEmail` with the **panel's** signed URL. It is the one sending path |
| `app/Filament/Pages/Register.php` | the registration's notification goes through `User::sendEmailVerificationNotification()`: one message per registration |

What `->emailVerification()` provides:
- **Guard:** every panel page and resource route gets Filament's `verified:filament.admin.auth.email-verification.prompt` middleware.
  - The prompt and logout routes are only behind `Authenticate`, so there is **no redirect loop**, and an unverified user can always log out.
  - Guests still go to the login page.
- **Link:** `GET /admin/email-verification/verify/{id}/{hash}` with `signed` and `throttle:6,1` middleware, handled by Filament's controller and Laravel's `EmailVerificationRequest`.
  - It is authorized only when the **authenticated** user's id **and** `sha1(email)` match the link, so a link cannot verify anybody else.
  - Expiry: `auth.verification.expire` (60 min).
  - Already verified: a no-op.
  - Afterwards, `redirect()->intended(panel)` returns the user to the page they wanted.
- **Resend:** the prompt's `resendNotification` action, a Livewire action of the authenticated user's own page.
  - It is throttled by Filament's `WithRateLimiting` at **2 per minute**, then «Demasiados intentos de reenvío».
- **Honest wording:**
  - **When delivery is active:** the page says «Le enviamos un correo…» and the resend says «Le reenviamos el correo de verificación».
  - **When it is not** (`log`/`array`, using the **existing** criterion `TaxonomyCategoryRequestService::transportSendsExternally()`):
    - the page says «El sistema todavía no envía correos… no llegará a su buzón por ahora… comuníquese con la Cámara»;
    - the resend says «Pedido registrado: … este mensaje no llegará a su buzón».
  - In both cases, no mode, transport name or configuration is shown.
- **Non-sending behaviour, the smallest honest one:** the framework flow is kept (the notification goes to the configured mailer). With `log`, the message is written to the server log, as every mail in that mode already is.
  - There are no retries, no queue and no new vendor.
- **Access after verifying:** the user's own roles and permissions only. Verification assigns no role and links no company.
  - Tested: a user without permissions sees Inicio, and Empresas answers the existing 403.
- **Unchanged policy:** manual and map stay blocked while unverified (`TaxonomyMapPageTest::access_is_exactly_the_manuals_including_unverified_users` is green).

### B. Completion

| File | Change |
|---|---|
| `app/Models/EmpresaModuleStatus.php` | `setStatus(false)` deletes model by model (at most one row, unique `empresa_id+module+sub_type`), so `deleted` fires and `EmpresaCompletionObserver` refreshes through the established `Empresa::refreshCompletionPercentage()` |
| `app/Filament/Support/NoAplicaAction.php` | `->databaseTransaction()` (Filament's action transaction): the flag and the cache refresh commit together, on the default connection, which is the connection of `EmpresaModuleStatus` and `Empresa` |

- **Not done:**
  - the formula is not duplicated;
  - no data is deleted on toggle;
  - no migration, backfill or permission change.
- **Authorization:** unchanged. It is the same relation-manager policy (`view_any_*`) and the company scoping of `EmpresaResource`. Tested at the real invoked path.

## 3. Verification tests (`EmailVerificationFlowTest`, 9 tests, real routes and pages)

Declared test doubles:
- **Captcha:** `App\Support\Turnstile` is replaced in the container by a double that answers yes or no. There is no Cloudflare call.
- **Mail:** Laravel's `array` transport renders the real message, so the real link URL is generated, and keeps it in memory.
- **Resend:** `Notification::fake()`.

Used links are not printed.

| Case | Result |
|---|---|
| Real `Register` page, captcha double yes | user created **unverified**, logged in, no role, no company; exactly **1** mail to the registered address containing the panel's verify link |
| Unverified on Inicio, Empresas, Manual, Mapa | each redirects to the prompt; the prompt is 200, in Spanish, «todavía no envía correos», «Cámara»; no «Hemos enviado», no mode/config |
| The mail's link, same user | verified; returns to the panel; Inicio 200; the prompt now redirects out; still no role or company |
| Captcha double no | no account, no mail |
| Event | Filament's `Registered` once; Laravel's `Registered` not dispatched (so no duplicate) |
| Unverified with a linked company | the company edit page redirects to the prompt |
| Logout from the prompt | works; afterwards the prompt redirects to the login page (no loop) |
| Link without a session | redirect to login, nobody verified |
| User B's link with A's session | **403**, neither verified |
| Tampered signature; altered id | **403** |
| Valid app signature but another email's hash | **403** (request authorization) |
| Expired (+61 min) | **403** |
| Valid own link; repeated | verified once; the repeat changes nothing |
| Resend ×3 under a non-sending transport | «Pedido registrado» twice, then «Demasiados intentos de reenvío»; exactly 2 notifications |
| Sending transport named (`smtp`, `Notification::fake`, nothing leaves) | the wording says «Le enviamos un correo» / «Le reenviamos…» |
| Guest; verified user | guest → login; verified → the prompt redirects out; the manual is 200 |

`NewAffiliateOnboardingTest` now verifies through the **real link** (`User::sendEmailVerificationNotification()`, captured with `Notification::fake()`). It no longer uses `markEmailAsVerified()`. Then it creates the company and makes the five first saves as before.

## 4. Completion tests

`NoAplicaCompletionTest` (8 tests) runs through the real panel actions with real events. The expected value is always a fresh `Empresa::completionPercentage()`.
- **Whole module, ×5 modules, no module rows:** on, on, off, off, on, off.
  - After each step, cache = fresh.
  - At the end, the value is back to the start and no flag row is left.
  - `empresas.updated_at` is unchanged.
  - The other company is unchanged (cache, `updated_at`, flags).
- **Existing data:** Gestión Calidad saved, Ambiente «No Aplica» per section, Recursos humanos, one Sostenibilidad row.
  - Module on/off for Gestión, Recursos and Sostenibilidad keeps cache = fresh.
  - The module tables are byte-identical: no data is deleted or changed.
  - The section flags are untouched.
- **Sub-type:** Gestión Credibilidad and Recursos Maquinaria, on, then off with data. Cache = fresh each time.
- **Authorization:**
  - a user without the module permission gets 403 at the action, and nothing is written;
  - another company's user gets 404 on the edit page.

**Rollback** (`FirstSaveAtomicityTest`):
- **Setup:** no outer test transaction; a container double of `EmpresaCompletionObserver` runs the real refresh, then throws.
- **Cases:** Gestión and Sostenibilidad, on and off.
- **Measured inside the failing step:**
  - transaction level 1;
  - the model and `Empresa` both on `pgsql`;
  - the refresh had written a non-sentinel value.
- **After the failure:**
  - the flag is as before;
  - the cached completion is still the sentinel;
  - the full company snapshot is identical;
  - the other company is unchanged.
- **Retry without the failure:** the flag is committed, cache = fresh, and `updated_at` is untouched.

**Before the fix** (`d0e6947`, tests only; `prefix/phpunit.txt`):
- the cache stayed at 25 against a fresh 13 in all five modules, and the toggles diverged;
- the rollback cases ran at level **0**: «on» committed the flag, and «off» never reached a refresh;
- the verification cases failed with the missing routes.

## 5. Browser (runner `infra/contabo-test-runner` @ `2b3471f`: `ux_check_verification.mjs` + `ux_seed_verification.php`, app @ `3f639b7`): **30/30**

The seed:
- creates an unverified synthetic user with no role or company;
- gives the synthetic company real Gestión data (ISO 9001);
- signs one verification link for the served host.
  - The runner now passes `CPV_UX_BASE_URL` to the seed, and the app runs with a file cache, so the throttle is visible.
  - The APP_KEY is ephemeral to the run.
  - The link line is **redacted** in the committed `ux_seed.log`, and the verify paths in the logs too.

| Who / where | Checked |
|---|---|
| Unverified, mobile dark | lands on the prompt; no horizontal overflow |
| Unverified, desktop light | after login → prompt (no 500); heading and honest wording; no «enviamos»; `/admin`, `/admin/empresas`, manual, map → prompt (200); resend #1 and #2 «Pedido registrado … no llegará a su buzón»; #3 «Demasiados intentos de reenvío»; «Cerrar sesión» → login |
| Same user, signed link | verified, lands in `/admin` (200); Empresas answers the existing **403** (no permissions granted by verifying) |
| Affiliate, desktop light and mobile dark | Gestión badge 17% with ISO 9001 saved → «No Aplica» on → after reload **100%**, ISO 9001 still shown → off → after reload **17%**, ISO 9001 still shown; no overflow |

Screenshots: `audit/runs/verification_completion/ux/` (6). The admin view was not repeated: the admin uses the same page and action.

## 6. Tests

| Run | SHA | Result | Evidence |
|---|---|---|---|
| Before the fix (expected red) | `d0e6947` | 36 tests: 9 errors and 10 failures, all the bugs above | `prefix/` |
| **Focused** (`EmailVerificationFlow\|NoAplicaCompletion\|FirstSave\|NewAffiliateOnboarding\|ManagementLabels\|EmpresaPgsqlCompatibility\|TaxonomyCategoryRequest\|TaxonomyCategoriesRelationManager\|TaxonomyMapPage\|UserManual\|CompanyCrawl\|ProfileHelpWidget\|HomeBranding`) | `3e684c5` | **OK (139 tests, 2,029 assertions)** | `focused/` |
| **Full isolated suite** (Unit+Feature) | `3e684c5` | **OK (568 tests, 4,336 assertions)** | `full/` |
| Browser | `3f639b7` | **30/30** | `ux/` |

## 7. Manual and documentation

- **`resources/manual/manual_usuario.md`:**
  - a new subsection **Verificar su correo electrónico** `{#verificar-correo}`, pinned in `UserManualContentTest`. It covers:
    - the screen;
    - the blocking;
    - **«por ahora el sistema todavía no envía correos… comuníquese con la Cámara»**;
    - the steps for when mail is active: same browser, 60 minutes, own account only, spam, 2 requests per minute;
    - no company access just from verifying;
    - «Cerrar sesión».
  - **«No Aplica»:** toggling never deletes data; removing it returns to the data's percentage; the percentage updates on save («recargue» if not).
  - **Errores frecuentes:** three rows, updated or new.
  - **Cuándo contactar a la Cámara:** updated.
  - Existing anchors and PDF-baseline coverage are kept (`UserManualContentTest` is green).
- **`docs/deploy/CORREO_DEL_SISTEMA.md`** (the admin/operator side):
  - the inventory row is updated;
  - a new section covers verification while mail is inactive: what the user sees; the criterion; that `log` writes the message, with its link, to the server log; that this round **does not activate delivery**.
- **No promise that messages arrive today.**

## 8. Integration and live data

- **One branch on top of the accepted candidate:** EmpresaPgsql/labels, the first-save repair and transactions, and the continuity startup files are all kept.
  - `history/**` is untouched.
  - The earlier suites are green inside the full run.
- **Not done:**
  - no deploy-branch push, no merge to `main`, no deployment;
  - no live mail, SMTP, secret, recipient, permission or user change;
  - no live user was verified;
  - no data cleanup and no taxonomy change.

## 9. Findings outside this scope (reported, not fixed)

- **`pdf/{empresa}` and `xls/{empresa}` (`routes/web.php`) have no authentication middleware.** Any visitor who knows or guesses a company id can download that company's profile PDF (and the join-views export).
  - This predates this round, which does not touch it. **Recommended as a separate, prioritized round.**
- **`EditEmpresa` fails with an empty `cities` catalog** («Undefined variable $value»). This is the known backlog item; the browser seed adds the minimal catalogs.

## 10. Limitations

- **Mail is not delivered.** `PENDING_OWNER_CONFIGURATION`; the live configuration is unchanged.
  - Fixing the pages and routes **does not activate email delivery**. With staging's `log`, a new affiliate **cannot verify by themselves today**, and real new-affiliate onboarding is **not** fully operational until the mail round.
  - This round gives the Cámara no manual verification procedure.
- **`log` writes the verification message, link included, to the server log.** That is framework behaviour for every mail in that mode. The log is not public.
- **Doubles:** captcha, transport and notifications are doubles. There is no real Turnstile, SMTP, inbox or mail client.
- **Livewire update requests:** they do not re-run `verified`. Livewire persistent middleware does not include it, as before this round. An unverified user cannot load a protected page in the first place, so they hold no component snapshot from one.
- **Not verified on staging,** because it is not deployed.
- **Scope of the evidence:** no claim of concurrency or exactly-once behaviour for resends or toggles; sequential tests only. This is measured coverage of the paths above, not a guarantee of zero bugs.
