# CPV-COMPLETE-COMPANY-PDF: a visible «Perfil completo (PDF)», the complete dossier by default, optional allowlisted sections

- **Authorization:** Issue #2 `6270558322` (owner, 2026-10-19 15:11Z). Repair of the company PDF experience after the owner's UAT of the deployed `ce70b7f`. **No** deployment.
- **Branch:** `fix/complete-company-pdf`, from `fix/export-access` @ `37503c8` (checkpoint docs only on top of the deployed runtime `ce70b7f`).
- **BASE_SHA:** `37503c8382989e29c101f556533e0fd5aadddd33`. Deploy branch and staging verified at `ce70b7f` (`git ls-remote`) before branching; worktrees untouched.
- **TESTED_HEAD:** `88713a70171ca80903fedac74cd4507498ad8718`. The full suite, the focused set and the browser check all ran at this SHA (§7).
- **PDF-01 follow-up (§9):** TESTED_HEAD `0392a1417236bd305640ce885ffe30538fdc6960`, focused run only (CSS/header and pagination change).
- **REVIEW_HEAD:** the commit that adds this report and the checkpoint (documentation and evidence only).
- **Deployed:** NO.

## 1. Root cause

There were two different paths, and the visible one was the wrong one.

| Path | What it is | What the owner got |
|---|---|---|
| `IconColumn::make('')` «PDF» in `EmpresaResource::table()` | link to the protected **full-profile** controller (`EmpresaExportController::pdf` → `resources/views/pdf.blade.php`) | **nothing visible**. With an empty field name the column state is `null`, so Filament draws no icon. The header «PDF» is shown above an **empty cell**. Confirmed visually: `audit/runs/export_access_repair/ux/exp-empresas-pdf-column.png` (deployed runtime, synthetic data). The previous round's browser check only asserted that an `<a>` to the route existed; it did not assert a visible control |
| `FilamentExportBulkAction::make('export')` | the generic **table** exporter of `alperenersoy/filament-export`: a configurable modal (file name, format incl. PDF, column filter, preview, print) | the only reachable «PDF». It prints **the Empresas table columns** (name, active, year, city, country, main sector): a listing, not the dossier |

- **The full report was not deleted.** `pdf.blade.php` still renders every legacy section (§3).
- **Second defect, found while fixing the first:** the panel runs `->spa()`. Any internal link is rendered with `wire:navigate`, so a direct link to the download is fetched as a page and **never downloads**. The old column only worked because it opened in a new tab. Fixed with `->spaUrlExceptions(['*/descargas/empresas/*'])`, and pinned by a test that fails without it.
- **Not an external credit report.** «Dun & Bradstreet-style» was taken as «a complete company dossier». There is no score, no DUNS lookup and no provider integration. The PDF states that it is self-declared data and not a credit rating, certification or endorsement.

## 2. What changed (`37503c8..88713a7 -- app resources routes`)

| File | Change |
|---|---|
| `app/Filament/Resources/EmpresaResource.php` | the empty `IconColumn` is removed. Two real row actions come **before the columns** (where «PDF» was, visible on a phone without scrolling the table): **«Perfil completo (PDF)»** (primary button, icon, tooltip, link to the protected route) and **«Elegir secciones»** (icon button with tooltip and accessible name, modal). The bulk exporter is relabelled **«Exportar listado»**, with its own icon, the heading «Exportar listado de empresas» and the description «Exporta solo las columnas de esta tabla. No es el perfil completo…». Visibility: `can('view_empresa')`, checked once (no per-row query) |
| `app/Filament/Resources/EmpresaResource/Pages/EditEmpresa.php` | header action **«Descargar perfil completo (PDF)»** on the company page (same protected link; sections only in the list, to avoid duplicate controls) |
| `app/Providers/Filament/AdminPanelProvider.php` | `->spaUrlExceptions(['*/descargas/empresas/*'])` |
| `app/Http/Controllers/EmpresaExportController.php` | `pdf()`: after authorization, `CompanyDossier::sectionsFrom($request->query('secciones'))`. The PDF is now an **attachment** (a real download), with page numbers «Página N de M», and PDF metadata `Title`. File name: `perfil-<rif>.pdf`, or `perfil-<rif>-parcial.pdf` for a subset |
| `app/Support/CompanyDossier.php` (new) | section allowlist, data gathering once per PDF with safe empty values, and the No aplica flags (module and sub-type). It keeps the report views (`JoinViews::index*`) for general data, clients, chambers, sectors, sustainability and resources, and reads the models where the views lose data (§3) |
| `resources/views/pdf.blade.php` | the **same** renderer and layout (yellow header with both logos, grey section titles, tables, experience cards), repaired: no database calls in the view, every `[0]` guarded, escaped output, «Sí/No» text instead of disabled checkboxes (legible and extractable), sub-type No aplica, `<thead>` repeated on page breaks, headings kept with their tables, remote font removed (dompdf fetched `mdn.github.io` on every render), duplicate heading and wrong machinery value fixed, new **Categorías CPV** section, and a «Contenido» box stating what the document includes or omits |

## 3. PDF_COVERAGE: legacy sections against the current profile

| Legacy section (`37503c8:resources/views/pdf.blade.php`) | Current profile module | Now | Legacy defect found and fixed |
|---|---|---|---|
| Datos Generales: name, RIF, address, city/country, web, phone, foundation year, capital/origin | Datos generales | kept (same source `catalogoView`/`FinanceView`) | `$datos[0]` unguarded |
| — | redes sociales, sector principal/secundario, rango de empleados (`employees_id`) | **added** (they are in the form, not in the old PDF) | missing |
| Contactos (in Datos Generales) | Contactos | kept, read from `contacts` + `contact_empresa.is_principal` | the old parser showed **nothing when there was exactly one contact** (`sizeof(explode(';')) > 1`); principal contact not marked |
| Operación en Venezuela: billing, employees (sum of HR), status | Datos generales / Recursos | kept; both «rango declarado» and «total en Recursos humanos» shown, labelled | — |
| Clientes | Datos generales (clientes) | kept (`ClientsView`), de-duplicated | — |
| Cámaras | Cámaras | kept | — |
| Sectores de Actividad Económica y Servicios | Sectores y servicios | kept | — |
| — | Categorías CPV | **added**: declared or confirmed categories, breadcrumb, principal, origin. Unconfirmed suggestions are excluded and the PDF says so | missing |
| Enfoque de Sostenibilidad | Sostenibilidad (+ No aplica) | kept | — |
| Recursos: Recursos Humanos | Recursos / employee (+ No aplica) | kept; totals computed | grand «Total» column read a view column that comes out 0 |
| Recursos: Maquinarias y Equipos | Recursos / machinery | kept; only rows with data | «Servicios a pozos» showed the quantity twice instead of the value |
| Recursos: Instalaciones | Recursos / facility | kept | a second «Recursos en Venezuela» heading in the middle |
| Recursos: Inventario | Recursos / inventory | kept | — |
| (No aplica de Recursos: only whole module) | sub-type No aplica (employee/machinery/facility/inventory) | **added**: each sub-table says «Marcado no aplica por el afiliado» | sub-type flags ignored |
| Experiencia Relevante | Experiencias (+ No aplica) | kept, **read from the model** | the form stores `exp_year` as a UUID-keyed object; `ExperienceView` wraps it as ONE row with every field null. Experiences saved by the current form came out as an empty card. Proven in the test: `ExperienceView` returns `[null]` for the synthetic company while the PDF lists its six entries |
| Presencia Internacional: offices; experience in other countries | Presencia (+ No aplica) | kept, **read from the model** | `index_pres` keeps only rows with offices, and `PresenceView` pairs offices and experiences by position: experience abroad **without an office disappeared**, and extra experiences beyond the number of offices were lost. Role/amount labels follow the form (incl. `> 10.000.001 USD`) |
| Sistemas de Gestión: Calidad, Ambiente, Credibilidad y transparencia, Seguridad (OVID), Gestión de proyectos, Seguridad de la información, with «Otros» | Gestión (+ No aplica, + sub-type No aplica) | kept, read from `management`, «Sí/No» per certification, «Otros: <name>» | with no management row the old view printed every box unchecked (reads as «No» to everything) and, by code reading (`null != 'NO'`), checked «Otros:» boxes with no text; the `["…"]` JSON artifact of the view is gone; sub-type No aplica ignored |

- **Retired or replaced sections:** none. Every legacy section is present.
- **Three cases per section, never mixed:** data; «Marcado no aplica por el afiliado» (`EmpresaModuleStatus::NO_APLICA_LABEL_LARGO`, the label of the other reports); «No hay información registrada.». Nothing is filled in by assumption.
- **«Dun & Bradstreet»** appears only as the self-declared registration field of the profile form («Dun & Bradstreet (registro declarado por la empresa)»), not as branding.

## 4. CONFIGURATION

- **Default complete.** «Perfil completo (PDF)» (list and company page) and any URL without `secciones` produce all 10 sections.
- **Optional sections, same report.** «Elegir secciones» opens a modal with **«Todas las secciones (perfil completo)»** selected. «Solo las secciones que elija» shows the 10 real dossier sections, all checked, with «Seleccionar todos». «Descargar PDF» downloads `perfil-<rif>-parcial.pdf`. The document header says «PERFIL DE LA EMPRESA — SECCIONES SELECCIONADAS», and the «Contenido» box lists the included **and the omitted** sections and says it is not the complete profile.
- **Server allowlist** (`CompanyDossier::sectionsFrom`):
  - accepted forms: `secciones=a,b` and `secciones[]=a`;
  - keys: `generales, clientes, camaras, sectores, categorias, sostenibilidad, recursos, experiencia, presencia, gestion`;
  - order is always the report's order;
  - unknown keys are **ignored**; if none is valid, the value is not a string/array, it is longer than 300 characters, or the array has more than 10 items, the result is the **complete** profile.
- **No persisted preferences, schema or report designer.**
- **The listing exporter stays**, labelled «Exportar listado», in the table toolbar for selected rows, visually and textually separate from the row's full-profile button.

## 5. SECURITY (unchanged rules, re-tested)

- **Routes and order:** panel `Authenticate` + `EnsureEmailIsVerified`, then numeric id, then `EmpresaPolicy::export` (verified email, `view_empresa`, `empresa_user` link). **Only then** is `secciones` read, data gathered and the PDF rendered.
- **No admin cross-company exception.**
- **Same 404** «Archivo no disponible» for missing, invalid and unauthorized ids, also with `secciones`: byte-identical body, 0 report-view queries, 0 renders (test).
- **`secciones` cannot switch the company:** `?empresa=<other>` in the query is ignored (test).
- **Guests** go to the login, with nothing generated (test).
- **Headers:** private `no-store`, `nosniff`.
- **XLS** keeps the explicit validated id (`JoinViewExport(int)`).
- **No database access from the view.**

## 6. PDF_VISUAL

- **How:** every generated PDF is read back with `pdftotext` and every page is rendered with `pdftoppm` (poppler-utils, added to the isolated runner image). Then **each page was inspected**.
- **Test PDFs:** 9 PDFs, 38 pages:
  - complete company: 7 pages;
  - three «ignored selection» PDFs: 7 pages each, pixel-identical to each other (md5 per page) and text-identical to the complete one except RIF and time;
  - minimal/new company: 3 pages;
  - No aplica company: 3 pages;
  - two sections: 2 pages;
  - array form (`gestion` only): 1 page;
  - query cannot switch company: 1 page.
- **Browser PDFs:** 5 PDFs, 18 pages. The four full downloads (desktop, company page, mobile, staff) are pixel-identical (4 pages each), plus the 2-section download (2 pages).
- **Results:** consistent headers on every page (logos, title, company name and RIF), page numbers, no clipped tables, no blank pages, headings never alone at the foot of a page. The cost: some pages end early when the next block does not fit.
- **Evidence:** PDFs, extracted text and page PNGs in `audit/runs/complete_company_pdf/pdf/` and `audit/runs/complete_company_pdf/ux/` (synthetic data only).

## 7. TESTS and BROWSER (isolated runner `infra/contabo-test-runner` @ `3e76459`; staging 200 before and after every run)

- **`tests/Feature/CompanyDossierPdfTest.php` (new, 6 tests).** Synthetic sentinels in EVERY module, PDF text extracted from the real file.
  - **Complete profile by default:**
    - every heading, «Página 1 de N» … «Página N de N»;
    - ~60 sentinel values across all modules;
    - nothing from another company, no unconfirmed suggestion, no `null`, `["` or `COVID`;
    - attachment `perfil-<rif>.pdf`;
    - `ExperienceView` diagnosis.
  - **Minimal company:** usable, honest empty labels, no invented «Sí».
  - **No aplica:** exactly 5 «Marcado no aplica por el afiliado» (experience, presence, sustainability, management, machinery), the right empty labels elsewhere, the single contact listed.
  - **Sections:**
    - subset, array form;
    - unknown, malicious and overlong values give the complete profile;
    - `empresa=` in the query is ignored;
    - denied requests with `secciones` give the identical 404 and generate nothing;
    - guest goes to the login.
  - **UI:**
    - the row action exists, is visible, labelled and linked;
    - «Elegir secciones» redirects to the allowlisted URL, and an empty choice is a validation error;
    - «Exportar listado» label;
    - the company page header action;
    - the SPA exception (fails without it).
  - **Manual:** the `#descargar-perfil` section uses the UI labels.
- **`ExportAccessTest`:** the PDF is now `attachment`. Everything else is unchanged.
- **Results at `88713a7`:**
  - **full isolated suite (Unit+Feature): OK (582 tests, 5,203 assertions);**
  - **focused** (`CompanyDossierPdf|ExportAccess|EmailVerificationFlow|UserManual|ManagementLabels|NoAplicaCompletion|EmpresaPgsqlCompatibility|NewAffiliateOnboarding|FirstSave|TaxonomyCategoriesRelationManager|HomeBranding|ProfileHelpWidget`): **OK (109 tests, 2,418 assertions)**.
- **Browser: 16/16 at `88713a7`** (`ux_check_dossier.mjs` + `ux_seed_dossier.php`: synthetic company with data in every module; synthetic staff linked with `view_empresa`).
  - **Desktop light:** visible 182×32 button with tooltip; click → real download `perfil-<rif>.pdf` (`%PDF`).
  - **«Elegir secciones»:** opens with «Todas» selected, shows 10 checked sections; two sections → `perfil-<rif>-parcial.pdf`.
  - **«Exportar listado»:** a toolbar action for selected rows, absent from the rows; its modal says it is a listing, not the full profile.
  - **Company page header:** downloads `perfil-<rif>.pdf`.
  - **Mobile dark:** the button is visible without scrolling the table, there is no page overflow, and it downloads.
  - **Staff:** downloads the linked company.
  - **Screenshots:** `audit/runs/complete_company_pdf/ux/dos-*.png`.
- **Runner changes (`infra/contabo-test-runner`, test-only):** `poppler-utils`; PDF evidence kept and rendered; the dossier check and seed; links of a seeded company to its own `ux-synthetic` category are excluded from the governed-taxonomy leak check, like the seeded categories already were.

## 8. MANUAL

- **`resources/manual/manual_usuario.md`, `#descargar-perfil` (anchor kept):**
  - what the complete profile contains;
  - the three section states and the «not a credit rating» note;
  - exact steps for «Perfil completo (PDF)» and for the company-page button;
  - optional sections («Elegir secciones», «Todas las secciones (perfil completo)», «Solo las secciones que elija», «Descargar PDF», `perfil-<RIF>-parcial.pdf`);
  - **«Exportar listado no es el perfil»**;
  - access and security (kept).
- **Elsewhere:** the summary table and «Errores frecuentes» are updated, with a new row: «El PDF que descargué solo tiene la tabla de empresas».
- **Tests:** the anchors and the manual's own PDF coverage are preserved (`UserManualContentTest`).
- **Operator note:** `docs/deploy/DESCARGAS_PERFIL.md` gains the actions, the default, the `secciones` allowlist and the validation rules.

## 9. PDF-01 follow-up (`6272035810`, review of `034cf11`): repeated header

- **Finding:** the reviewer saw no yellow header on `complete_full_company-page-{1,4,5,7}.png` at `034cf11`, while pages 2, 3 and 6 had it.
- **Reproduction of the published evidence at `034cf11`, before any change** (`audit/runs/complete_company_pdf/pdf01/reproduction_and_checks.txt`):
  - **Bytes:** for all 7 pages, the PNG in the git blob, the working file and `raw.githubusercontent.com/…/034cf11…/` have the same md5. The PDF blob equals the file too. The artifacts were not replaced or mismapped.
  - **The PDF itself, page by page** (poppler: `pdftotext -f N -l N` and `pdfimages -list`): every page of `complete_full_company.pdf` (7), `minimal_new_company.pdf` (3) and `selected_sections.pdf` (2), pages 1, 4, 5 and 7 included, has the title, «· RIF <rif>», «Página N de M» and **2 logo images**.
  - **Pixels:** each of the 30 published page PNGs was decoded by a small script that uses no image library (`png_band_check.js`). The row at 20 pt is 68% band yellow, and the row at 100 pt has 0% yellow on **every** page.
  - **Visually:** pages 1, 4, 5 and 7 show the header.
  - **Conclusion:** the omission described in PDF-01 **does not reproduce** in the delivered PDF or PNGs. I could not determine how it was observed. No code defect is claimed for it.
- **What the new checks did find (a real defect, reproduced):** with the **longest name the column allows (100 characters)**, the header line wrapped. «RIF» was separated from its number, and the yellow band **grew with its content** to about 104 pt. That is 8 pt from the body, which starts at 112.5 pt; the new gap check failed (row 100 pt: 12% white).
  - **Cause:** `.page-header` had a fixed height, but the yellow `.row` inside it did not.
  - **Fix** (`resources/views/pdf.blade.php`, CSS and markup only):
    - the band has a fixed height of 112px (`overflow: hidden`), inside the 150px top margin;
    - logos 52/48px (were 60/55), logo cells 56px;
    - the name line is 10px, with a 12px line height (at most two lines fit);
    - «RIF …» is `nowrap`.
  - **Result:** the 100-character name now fits on one line; the band is identical on every page and document.
- **Second real defect, found in the long partial document:** an experience whose description is taller than a page was pushed whole to the next page by `page-break-inside: avoid`, leaving page 1 nearly blank.
  - **Fix:** descriptions over 1,500 characters may split. The heading still stays with the start of the first experience. Normal cards are unchanged.
- **New check** (`CompanyDossierPdfTest::every_page_carries_the_header_in_complete_partial_minimal_and_long_documents`), on **each page** of five synthetic documents (complete, partial `generales,experiencia`, minimal, complete with a 100-character name and a description longer than a page, and partial `experiencia,gestion` of that company):
  - the page's own text has the title exactly once, the company name, «· RIF <rif>» and «Página N de M»;
  - the page places **2 logo images**;
  - at 72 dpi, the row at 20 pt is more than 60% band yellow, and the row at 100 pt is **100% white** (no missing band, no band overflow, no body under the band);
  - the long description really spans pages, and in the partial it starts on page 1.
  - The old whole-document text check could not catch a missing header; this one fails on the 100-character name without the fix.
- **Pages checked by the test:** complete 7, partial 4, minimal 3, long complete 8, long partial 5 (`header_pages.json`). That is 27 pages, each **inspected visually** as well. The pixel check also ran over all 65 page renders of the run: 65/65 `HEADER_OK`.
- **Evidence (synthetic):** `audit/runs/complete_company_pdf/pdf01/` (PDFs, text, page PNGs, scripts, reproduction log). The evidence in `pdf/` and `ux/` stays as reviewed at `034cf11`.
- **Not changed:** report data, sections, access rules, controller, UI and manual. The user manual describes neither the header nor pagination, so it stays accurate.

## 10. LIMITATIONS

- **Not deployed.** Staging still shows the empty «PDF» column until a deployment is authorized.
- **`ExperienceView` / `PresenceView`:** the views are staging-identical and were not changed (no schema change). The PDF now reads experience and presence from the models.
  - **The Cámara's `JoinViews` page still uses those views**, so UUID-keyed experiences and office-less international experience stay incomplete there (pre-existing; a separate round).
  - **The per-company XLS** (`JoinViewExport`) never had experience or presence columns: it covers general data, clients, finance and management only. Unchanged.
- **`JoinViews` (Cámara) page:** its older issues remain.
- **Layout density:** some pages end early so that a heading stays with its table. A complete synthetic profile is 7 pages.
- **Scope of the evidence:** synthetic data on staging-identical views. No live profile was edited or downloaded.
- **Mail:** still in `log`; a new affiliate cannot verify on their own until mail is activated (separate round).
