# CPV-EXPORT-ACCESS-REVIEW: access to `pdf/{empresa}` and `xls/{empresa}`

- **Instruction:** Issue #2 `6262799754` (owner, 2026-10-19 08:16Z). Review, isolated proof and a repair proposal. **No runtime change, no deployment.**
- **Branch:** `review/export-access`.
- **BASE_SHA:** `edf20ed4a1fbd8b2d6b8cee3d62af2a58bfa6d7a` (`fix/verification-completion`, accepted in `6262657488`).
- **Commits on this branch:** test, fixture and report files only. `git diff edf20ed..HEAD -- app routes resources config database` is **empty**.
- **Code under review vs code running:**
  - the routes, controller and export are **identical** in the base candidate and in the deployed runtime `0f8bdad` (`git diff 0f8bdad edf20ed -- routes/web.php app/Http/Controllers/PdfController.php app/Exports/JoinViewExport.php resources/views/pdf.blade.php app/Filament/Pages/JoinViews.php`: only the OVID/ISO labels in `pdf.blade.php`);
  - the staging metadata below was read from the running `0f8bdad`.
- **Deployed:** NO.

## 1. Effective configuration (confirmed, staging read-only, 2026-10-19)

`audit/runs/export_access/staging_route_metadata.txt` was produced by a PHP script inside the app container. It read only:
- route metadata;
- object names from `information_schema` (no data rows, inside a READ ONLY transaction).

| Route | Methods | Effective middleware |
|---|---|---|
| `pdf/{empresa}` (name `pdf`) | GET, HEAD | `web` group only: EncryptCookies, AddQueuedCookies, StartSession, ShareErrors, VerifyCsrfToken, SubstituteBindings |
| `xls/{empresa}` (name `xls`) | GET, HEAD | same |

- **Global stack:** TrustProxies, HandleCors, PreventRequestsDuringMaintenance, ValidatePostSize, TrimStrings, ConvertEmptyStrings, DisableBackButtonCache.
- **No authentication, verification or authorization middleware** on either route.
- **Staging's default connection** is `pgsql`. The five report views the exports read (`capacityView`, `catalogoView`, `ClientsView`, `FinanceView`, `ManagementDetView`) exist there. 13 report views in total, with definition hashes in `staging_view_definitions_md5.txt`.

## 2. Code path

| | PDF | XLS |
|---|---|---|
| Route | `Route::get('pdf/{empresa}', PdfController::class)` | `Route::get('xls/{empresa}', fn () => Excel::download(new JoinViewExport, 'join-views.xlsx'))` |
| Binding | typed `Empresa $empresa` → implicit route-model binding (404 if the id does not exist) | **none**: the closure takes no parameter, so `{empresa}` is never resolved |
| Authorization | none in the controller | none |
| Data | `pdf.blade.php` → `JoinViews::index*($record->id)`, which reads the report views; `EmpresaModuleStatus::flagsFor()` | `JoinViewExport::collection()`: the company id is `explode('/', explode('/', $_SERVER['REQUEST_URI'])[2])` → `where('capacityView.id', '=', …)` |
| Fields | full profile: name, RIF, address, city, website, phone, **contacts**, founding year, **capital, origin, annual billing**, status, employees, sectors, services, installations, clients, management systems, resources, experience, presence, sustainability, chambers | one row: the same identity, contact and **financial** columns, plus sector, services, installations, clients and ISO/management columns |
| Response | DomPDF `stream()`, `Content-Disposition: inline; filename=.pdf` (`$empresa->number` does not exist: empty file name), `Cache-Control: no-cache, private` | `BinaryFileResponse`, `attachment; filename=join-views.xlsx`, **`Cache-Control: public`** |
| UI caller | **Empresas** list, column «PDF» (`EmpresaResource::table`). The list only shows companies linked to the user (`getEloquentQuery()->whereRelation('users', …)`) | **none**: the table action that linked to it is commented out |

**Intended policy, from the existing code:**
- **Profile access** is permission plus link: `EmpresaPolicy::viewAny/view` need `view_any_empresa`/`view_empresa`, and the record has to be linked to the user through `empresa_user` (the resource query). Another company's edit page answers **404**.
- **Admins are no exception** in the resource: the synthetic Super Admin sees only the companies they are linked to. `super_admin` has `define_via_gate = false`.
- **General reports for the Cámara** are panel pages behind Shield page permissions (`HasPageShield`): CompletionView, GenCatalog, FinanceView, ClientsView, ManagementDetView and so on. They are **not** affected and were not audited further.
- **Public sharing:** no documented use. The manual (line 28 and §Abrir su empresa) describes the PDF only as «descargar el perfil **de su empresa**» from the Empresas list.

## 3. Isolated proof (`tests/Feature/ExportAccessCharacterizationTest.php`, 3 tests, OK)

- **Run:** runner `infra/contabo-test-runner` @ `2b3471f`, at `af23c3f`, on disposable PostgreSQL.
- **Data:** synthetic companies **ALFA** (owner A), **BETA** (owner B) and **GAMMA** (no linked user), with distinct markers.
- **Report views:** created inside the test transaction from `tests/Fixtures/pgsql_report_views/`, copied from `feature/supplhi-postgres-buscador` @ `5df23fa`. A test asserts that all **13 definitions are md5-identical to staging's**.
- **Declared double:** `$_SERVER['REQUEST_URI']` is set to the requested path, which is what php-fpm does.
- **Spies:**
  - a view composer records which company the PDF rendered (and the body starts with `%PDF`);
  - the XLS file is opened with PhpSpreadsheet and searched for markers;
  - `DB::listen` records which company ids the report-view queries used.

| Actor | PDF A | PDF B | PDF C | XLS A | XLS B | XLS C |
|---|---|---|---|---|---|---|
| guest (no session) | 200 ALFA | **200 BETA** | **200 GAMMA** | 200 ALFA | **200 BETA** | 200, no name row |
| unverified, linked to A | 200 ALFA | **200 BETA** | **200 GAMMA** | 200 ALFA | **200 BETA** | 200, no name row |
| verified owner of A | 200 ALFA | **200 BETA** | **200 GAMMA** | 200 ALFA | **200 BETA** | 200, no name row |
| verified, no company | **200** | **200** | **200** | **200** | **200** | 200 |
| linked to A, no `view_empresa` | **200** | **200** | **200** | **200** | **200** | 200 |
| super_admin with permissions, not linked | 200 | 200 | 200 | 200 | 200 | 200 |

| Manipulated input (guest) | Result |
|---|---|
| PDF, nonexistent id | 404 (binding) |
| XLS, nonexistent id | 200, an empty sheet (headers only) |
| XLS `…/{A}?x=1` | **500**: the query string ends up inside the id taken from `REQUEST_URI` |
| XLS `…/abc` | **500**: a non-numeric id reaches PostgreSQL |

In every 200 case the report-view queries ran **for the requested company**: there is no check before querying or rendering. Raw lines: `audit/runs/export_access/phpunit.txt` (`EXPORT | …`).

## 4. Findings

| # | Finding | Status | What could be exposed |
|---|---|---|---|
| F1 | **PDF: no authentication and no authorization.** Any visitor, including a guest, gets any company's full profile by id: IDOR, plus sequential integer ids. | **Confirmed** (isolated proof + effective middleware read on the running staging). No live document was fetched. | The full profile, including contacts, phone, RIF, capital, origin, annual billing, employees and clients. Also for companies with no linked user. |
| F2 | **XLS: no authentication and no authorization.** Same IDOR. | **Confirmed** | One company's row: identity, contacts and financial columns. |
| F3 | **XLS selects the company from `REQUEST_URI`**, not from a validated route parameter. | **Confirmed** | It does not widen the scope beyond the id in the path: the query is filtered to **one** company, as you noted, **not all of them**. But it is fragile: a query string or a non-numeric id gives 500, and the result depends on the deploy path. |
| F4 | XLS is sent with `Cache-Control: public`. | **Confirmed** (header). Shared caching was not tested; Cloudflare was not changed or probed. | A shared cache could, depending on its rules, store a company's file. |
| F5 | **Denial before work:** today there is none, and the queries run in every case. | Confirmed | — |
| F6 | The PDF file name is empty (`.pdf`). | Confirmed, cosmetic | — |
| F7 | The plain `auth` alias (`App\Http\Middleware\Authenticate`) redirects guests to `route('login')`, which **does not exist**. Using it for the fix would give guests a 500. | Confirmed (test) | — (affects how to fix) |

`EmpresaPgsql` and the other inherited fixes do not touch these paths. No other route serves these exports: the Shield-gated panel report pages use their own Livewire actions and were not audited beyond identifying the gate.

## 5. Proposed access matrix

| Actor | PDF of own company | PDF of another company | XLS of own company | XLS of another company | General reports (panel pages) |
|---|---|---|---|---|---|
| Guest | → login | → login | → login | → login | → login (as today) |
| Logged in, unverified | → «Verifique su correo» | → «Verifique su correo» | → «Verifique su correo» | → «Verifique su correo» | → «Verifique su correo» (as today) |
| Verified affiliate with `view_empresa`, linked | **allowed** | **404** | **allowed** (*decision D2*) | **404** | per Shield page permission (unchanged) |
| Verified, linked, without `view_empresa` | 404 (or 403, *decision D3*) | 404 | 404 | 404 | unchanged |
| Verified user with no company | — | 404 | — | 404 | unchanged |
| Cámara staff (role to be named) | allowed if linked | **allowed only with a new explicit permission**, e.g. `export_any_empresa` (*decision D1*). Without it: 404, same as the Empresas list today | as PDF | as PDF | unchanged (Shield) |
| Missing or invalid id | 404 | 404 | 404 | 404 | — |

**Denial behaviour:**
- **404, not 403,** for a company the user may not see. That is consistent with the edit page and does not confirm that the id exists.
- **The check runs before any report query or rendering.** Route-model binding loads only the `empresas` row; no view query and no template run.

## 6. Minimal repair proposal (not applied)

1. **Middleware:**
   - Register both routes with panel-aware middleware: either inside the Filament panel (`->authenticatedRoutes(...)`), so they get its `Authenticate` and redirect to `filament.admin.auth.login`, or in `routes/web.php` with `Filament\Http\Middleware\Authenticate` behind the panel set-up.
   - Add `verified:filament.admin.auth.email-verification.prompt`.
   - **Do not** use the plain `auth` alias (F7).
   - Keep the route names, or update the single UI caller (the «PDF» column).
2. **Policy:** add `EmpresaPolicy::export(User $user, Empresa $empresa)`:
   - true if the user has `view_empresa` **and** is linked in `empresa_user`;
   - **or** has `export_any_empresa` (D1);
   - otherwise `abort(404)`.
   - Apply it as `->can('export', 'empresa')` on both routes, so it runs after binding and before the controller.
3. **XLS:**
   - replace the closure with a controller that takes `Empresa $empresa` (binding: 404 for missing or non-numeric ids);
   - give `JoinViewExport` an explicit constructor `__construct(private int $empresaId)` and remove `REQUEST_URI`;
   - name the file per company (for example the RIF).
4. **Headers:** both responses `Cache-Control: private, no-store, max-age=0` and `X-Content-Type-Options: nosniff`. PDF file name from RIF or name (sanitized).
5. **Kept as is:** the owner's PDF from the Empresas list; the Cámara's panel reports (Shield). Data and roles are not touched; D1 needs only a new permission assigned by the owner.
6. **Tests:**
   - turn `ExportAccessCharacterizationTest` into the matrix of §5 for PDF and XLS: guest, unverified, owner, other company, no company, no permission, staff with and without D1, missing id, `?x=1`, `abc`;
   - assert **zero report-view queries and no `pdf` view render** on every denied path (the same spies);
   - assert owner content (markers) and the headers;
   - one short browser check: the «PDF» column still downloads for the owner, and another id gives 404.
7. **Documentation:** the manual (§Abrir su empresa, Errores frecuentes): «solo puede descargar el perfil de las empresas asociadas a su usuario». An admin/operator note on the new permission if D1 is accepted. No change to the general-report docs.

## 7. Policy decisions pending (owner)

- **D1 — Cámara staff downloading any company.** Should Cámara staff download a non-linked company's PDF/XLS?
  - **Safe default:** no. Same as the Empresas list today; staff use the Shield-gated general reports.
  - **If yes:** a new explicit permission (`export_any_empresa`) assigned by the owner to the staff role. A nominal admin role is never enough by itself.
- **D2 — the per-company XLS.** It has no UI link today.
  - **Option (a):** keep it for owners with the same rule as the PDF.
  - **Option (b):** reserve it for Cámara staff (D1 permission).
  - **Option (c):** remove the route.
  - **Safe default:** (b) or (c). The affiliate already has the PDF.
- **D3 — status code for a linked user without `view_empresa`.** **Default:** 404, like other denials.

## 8. Live data and limits

- **No live download**, no enumeration of real ids, no staging user, no mail, no role or credential change.
- **Staging reads:** route metadata, object names and view-definition hashes only, inside a READ ONLY transaction.
- **Not tested:** real proxy/CDN caching (only headers); HEAD requests specifically; the deployed PDF rendering on staging. The isolated rendering uses staging-identical view definitions with synthetic data.
- **This is a targeted review** of two routes, not a broad security audit.
