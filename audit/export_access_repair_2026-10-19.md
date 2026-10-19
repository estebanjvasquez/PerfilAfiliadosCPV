# CPV-EXPORT-ACCESS-REPAIR-AND-ERROR-PAGES: PDF/XLS downloads only for the linked company, plus safe Spanish 404s

- **Authorization:** Issue #2 `6264837159` (owner, 2026-10-19 10:12Z). It covers the repair, the useful 404 and checking the unverified-user screen. **No** deployment.
- **Branch:** `fix/export-access`.
- **BASE_SHA:** `dc7972821ad9d826a7d3922f67c63aea1019bec6` (`review/export-access`: tests, fixtures and report only, on top of the accepted runtime candidate `edf20ed`).
  - `edf20ed` contains the EmpresaPgsql/labels, first-save, verification and «No aplica» fixes and the continuity files; all are kept.
- **TESTED_HEAD:** `8e01cacf0a9be978244bd32daf08f7355a70caaf`. The focused, full and browser runs were all made at this SHA (§6).
- **REVIEW_HEAD:** the commit that adds this report. It is documentation and evidence only.
- **Deployed:** NO. The running staging is still `0f8bdad`, which has the open routes described in `audit/export_access_review_2026-10-19.md`.

## 1. Access rules implemented (owner-approved)

| Actor | Own linked company (PDF / XLS) | Another company, no company, no permission, missing or invalid id |
|---|---|---|
| Guest | → panel login; nothing generated | → panel login; nothing generated |
| Logged in, unverified | → «Verifique su correo electrónico»; nothing generated | → «Verifique su correo electrónico»; nothing generated |
| Verified affiliate with `view_empresa` | **200** | **404 «Archivo no disponible»**, the same body for every case; nothing generated |
| Verified, linked, without `view_empresa` | 404 | 404 |
| Cámara staff | 200 only if linked and with `view_empresa`; same rule | 404. There is no cross-company exception and no `export_any_empresa` |

- **Shield-gated general reports:** unchanged.
- **Per-company XLS:** kept under the same rule; no UI button added.

## 2. Changes (`dc79728..8e01cac -- app routes resources`)

| File | Change |
|---|---|
| `app/Providers/Filament/AdminPanelProvider.php` | `->authenticatedRoutes()`: `GET /admin/descargas/empresas/{empresa}/pdf` and `…/xlsx` (`filament.admin.export.pdf\|xls`), behind the **panel's** `Authenticate` (login of the panel) and `verified:filament.admin.auth.email-verification.prompt`. The paths have no `.pdf`/`.xlsx` extension: some CDNs cache by extension |
| `app/Http/Controllers/EmpresaExportController.php` (new) | numeric-id check → `Empresa::find` → `EmpresaPolicy::export` → **only then** `Pdf::loadView('pdf')` or `Excel::download(new JoinViewExport($id))`. Denials return the same 404 (HTML view or JSON). Private headers; `perfil-<rif>` file names |
| `app/Policies/EmpresaPolicy.php` | `export(User, Empresa)`: verified email, `view_empresa`, and an `empresa_user` link |
| `routes/web.php` | `/pdf/{id}` and `/xls/{id}` **only redirect** to the new routes. They never touch the database, so the response does not reveal whether the company exists, and old links keep working. `PdfController` is removed: it was unrouted |
| `app/Exports/JoinViewExport.php` | `__construct(int $empresaId)`. **No more `$_SERVER['REQUEST_URI']`** |
| `app/Filament/Pages/JoinViews.php` + its view | The other constructor caller: the Cámara's Shield-gated report page. It now takes its company id once, at mount, from the raw query string, and only if numeric. Its view uses that id instead of `REQUEST_URI`. Its Excel button exports that company; before, it failed on every Livewire request. Its access gate is unchanged |
| `app/Filament/Resources/EmpresaResource.php` | the «PDF» column links to `filament.admin.export.pdf` |
| `resources/views/errors/partials/error-shell.blade.php`, `errors/export-not-available.blade.php`, `errors/404.blade.php` (new) | self-contained Spanish error pages (inline CSS, light/dark, mobile), with no session, user or panel dependency and no technical detail |

**Columns:** all the XLS columns are profile data an affiliate already sees or edits: identity, address, contacts, billing/origin/property/status/employees, sectors/services, installations, clients, management systems. No column had to be removed.

## 3. The 404s

- **Export 404**, the same for missing, invalid and unauthorized:
  - **Text:** «Archivo no disponible — No encontramos el archivo solicitado o su cuenta no tiene acceso para descargarlo.»
  - **Possible causes:** an incomplete or outdated link; only linked companies can be downloaded; open Empresas and download from your company; if it persists, contact the Cámara.
  - **Links:** «Ir a Empresas», «Ir al Inicio».
  - **JSON** (`Accept: application/json`): `{"message":"Archivo no disponible","detail":"…"}`.
  - **Headers:** status **404** and the private headers.
  - **Proven:** the body is byte-identical across all denial cases.
  - **Not shown:** id, name, RIF, permission, SQL, path or exception.
- **General 404** («Página no encontrada»): neutral wording, «Ir al Inicio» and «Iniciar sesión».
  - It does not echo the requested URL.
  - It works with no session (unknown route), and for another company's edit page, which already answered 404 and now shows this page.
  - JSON stays Laravel's `{"message": …}` with `APP_DEBUG=false`. Staging has `app.debug=false` (read-only config read, 2026-10-19); the isolated stack runs with debug on, so the test sets it off.
- **Other errors:** not redesigned.

## 4. Unverified users (retained from `edf20ed`, re-verified)

- **What they get:** an unverified account opening any panel page **or an export URL** lands on «Verifique su correo electrónico», with no 500 and no loop.
  - The page says the account exists, that verification is pending and protected content is unavailable, that the system **does not send mail yet**, and to contact the Cámara.
  - It promises no manual verification screen.
- **Kept:** signed-link security, resend throttling and logout.
  - The `EmailVerificationFlowTest` (9 tests) and `NewAffiliateOnboardingTest` are green in the focused and full runs.
  - The export case is new in `ExportAccessTest` and in the browser check.

## 5. Outputs and cache

- **Permitted outputs:**
  - **PDF:** `application/pdf`, `inline; filename=perfil-<rif>.pdf`, body `%PDF`, rendered for the requested company.
  - **XLS:** spreadsheet content type, `attachment; filename=perfil-<rif>.xlsx`, containing **only** the requested company's marker.
  - **Both, on GET and HEAD:** `Cache-Control: private, no-store, max-age=0`, `Pragma: no-cache`, `X-Content-Type-Options: nosniff`.
- **Prior-cache risk (unresolved; owner action):**
  - Before this candidate, the XLS answered `Cache-Control: public`.
  - Staging is behind Cloudflare (`cf-cache-status: DYNAMIC` on the login page; header read only).
  - Cloudflare's default does not cache extensionless paths like `/xls/{id}`, but its rules were not inspected or changed.
  - **If** a «Cache Everything»-style rule covers `/xls/*` or `/pdf/*`, the owner should purge those paths after deploying.
  - The new headers do not invalidate old cache entries.
  - Recorded in `docs/deploy/DESCARGAS_PERFIL.md`.

## 6. Tests

`ExportAccessTest` (8 tests) replaces the review's characterization test; no test expects an unauthorized download to succeed.
- **Real routes** with synthetic markers ALFA/BETA/GAMMA.
- **Declared double:** the report views, created inside the test transaction from `tests/Fixtures/pgsql_report_views/` (copied from `feature/supplhi-postgres-buscador` @ `5df23fa`); one test asserts their 13 definitions are md5-identical to staging's.
- **Spies:** a `pdf` view composer and `DB::listen` on the report views. **On every denied path, both are zero.**

| Test | Covers |
|---|---|
| guests | PDF/XLS × {A, B, missing, `abc`} × GET/HEAD → panel login, nothing generated; old `/pdf`, `/xls` → plain redirect, nothing generated, and the same response for an existing and a missing id |
| unverified | PDF/XLS × GET/HEAD → the verification page, nothing generated; the page explains and says mail is not sent |
| owner | PDF 200 (`%PDF`, 1 render, inline, `perfil-<rif>.pdf`, private headers); XLS 200 with only ALFA, attachment, private; `?x=1` is harmless (200, ALFA); HEAD 200 with the headers; the «PDF» link route |
| denials | owner→B, owner→company without users, no company, linked without `view_empresa`, non-linked super_admin with permissions, missing id, `abc`, a 30-digit id: each one 404, identical body, safe text, no names or RIFs, no technical strings, private headers, HEAD 404, nothing generated; JSON identical for unauthorized and missing |
| linked staff | super_admin linked to A → PDF and XLS 200 |
| general 404 | unknown URL as guest (no URL echo, login link), another company's edit page (no data), JSON without detail |
| JoinViews | `new JoinViewExport($id)` returns only that company; the page mount accepts only a numeric raw query string (`?22` → 22; `abc`, `1?2` and empty → null) |

| Run | SHA | Result | Evidence |
|---|---|---|---|
| Review characterization (before) | `af23c3f` | the current exposure, documented | `audit/runs/export_access/` |
| **Focused** (`ExportAccess\|EmailVerificationFlow\|UserManual\|TaxonomyMapPage\|NewAffiliateOnboarding\|FirstSave\|NoAplicaCompletion\|ManagementLabels\|EmpresaPgsqlCompatibility\|TaxonomyCategoryRequest\|TaxonomyCategoriesRelationManager\|CompanyCrawl\|ProfileHelpWidget\|HomeBranding`) | `8e01cac` | **OK (147 tests, 2,636 assertions)** | `audit/runs/export_access_repair/focused/` |
| **Full isolated suite** (Unit+Feature) | `8e01cac` | **OK (576 tests, 4,943 assertions)** | `audit/runs/export_access_repair/full/` |
| Browser | `8e01cac` | **42/42** | `audit/runs/export_access_repair/ux/` |

**Browser** (`ux_check_export.mjs` + `ux_seed_export.php`, runner `infra/contabo-test-runner` @ `0a1abe2`):
- **Affiliate:**
  - the «PDF» column points to the protected route of its own company;
  - its own PDF and XLS download (200, private headers, `perfil-<rif>`).
- **Affiliate, desktop/mobile × light/dark:**
  - another company's PDF and XLS, a missing id and an invalid id all show the **same** «Archivo no disponible» 404, with no other-company data and no overflow;
  - «Ir a Empresas» opens the list.
- **Guest:**
  - an unknown URL shows «Página no encontrada» without echoing the URL;
  - an export URL → login;
  - the old `/pdf/{id}` → 302 to the protected route only.
- **Unverified, mobile dark:** an export URL → the verification page, then «Cerrar sesión».
- **Screenshots:** 7.
- **Runner note:** the panel limits logins to 5 per minute, so the script logs the affiliate in once and reuses the session.

## 7. Manual and operator note

- **`resources/manual/manual_usuario.md`:**
  - a new **«Descargar el perfil»** `{#descargar-perfil}`, pinned in `UserManualContentTest`: only linked companies, with the session and a verified email; the `perfil-<RIF>.pdf` name; the login and verification redirects; what «Archivo no disponible» means and what to do;
  - the summary table now links to it;
  - «Errores frecuentes»: rows for «Archivo no disponible» and «Página no encontrada».
  - Verification/no-mail text from the previous round is unchanged and still accurate. Earlier anchors and the PDF baseline are kept (`UserManualContentTest` green).
- **`docs/deploy/DESCARGAS_PERFIL.md`** (new, Cámara/operator):
  - routes and redirects;
  - who can download (no admin exception) and how to grant access (link plus `view_empresa`);
  - error pages and `APP_DEBUG=false`;
  - headers and the Cloudflare purge note.

## 8. Live data

- No live export, real id or mail was used.
- No role, credential, recipient, Cloudflare or data change.
- **Staging reads:**
  - route metadata, view object names and definition hashes (review round);
  - `app.debug`/`app.env`/`cache.default` and the login page's response headers (this round).
  - All read-only.

## 9. Limitations

- **Not deployed.** Staging (`0f8bdad`) still serves the open routes until a deployment is authorized.
- **Prior cache:** see §5. Cloudflare rules were not inspected; a purge, if needed, is a separate owner action.
- **The `JoinViews` HTML page:** it still fails to render for companies with incomplete data. That predates this round and is unrelated; only its id source and its Excel button were fixed. Its `Pdf` action points to a method that does not exist, which also predates this round. Both are left for a separate round.
- **Mail delivery is still pending** owner configuration. The verification page says so.
- **Scope of the evidence:** the isolated rendering uses synthetic data with staging-identical views. This is measured coverage of these routes, not a broad security audit.
