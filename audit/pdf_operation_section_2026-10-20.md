# CPV-PDF-OPERATION-SECTION: «Operación en Venezuela» as its own section, and every option tested on its own

- **Authorization:** Issue #2 `6286644396` (owner, updated 2026-10-20 08:21Z): owner UAT finding on the deployed `514fa6e`, a bounded repair plus a check of every option. **No** deployment.
- **Branch:** `fix/pdf-operation-section`, from `fix/complete-company-pdf` @ `9253968` (the checkpoint). Before branching, `git diff 514fa6e..9253968 -- app resources routes tests` was empty: the base runtime is exactly the deployed `514fa6e`.
- **BASE_SHA:** `92539686d8da37a55440ca212a4b69db192dc340`.
- **TESTED_HEAD:** `3ffa85d47eff92261819d7331666329472082138` (focused run, §4).
- **REVIEW_HEAD:** the commit that adds this report, the evidence and the checkpoint (documentation and evidence only).
- **Deployed:** YES, to staging, on owner authorization `6288459314` (accepted review `6288445203`): `87dd997`, workflow `39405700502` success (§8).

## 1. Finding and cause

- **Owner observation:** choosing only «Datos generales y contactos» also printed «Operación en Venezuela».
- **Cause:** in `pdf.blade.php` the operation block was nested inside `@if ($want('generales'))`. This was a selection-granularity and label problem. Access control was not involved.
- **Owner's static review (all options):** no other cross-section rendering. Recursos, Presencia and Gestión are legitimate umbrellas, but the selector did not say what they contain. **The executed test confirms that (§3): no additional mismatch.**

## 2. Change (runtime: 3 files)

- **`App\Support\CompanyDossier`:**
  - `SECTIONS` now has 11 keys. `operacion` («Operación en Venezuela») comes right after `generales`.
  - New `SECTION_DESCRIPTIONS`, one line per option. Recursos, Presencia and Gestión name their 4, 2 and 6 blocks.
  - **Bounds:**
    - the array limit stays `count(SECTIONS)`, now 11;
    - the string limit is derived from the keys (3 × the full key list = 330 characters, was a fixed 300);
    - no hardcoded 10 remains.
  - **Unchanged:** fallback rules (unknown keys ignored; nothing valid or oversized → complete), report order and complete detection (`count === count(SECTIONS)`).
  - **Query:** the general-data row is fetched when `generales`, `operacion` or `clientes` is selected. Each section prints only its own fields.
- **`pdf.blade.php`:**
  - the same four fields, in their own section «Operación en Venezuela» (h2, same table style), right after Contactos;
  - «Datos generales» + «Contactos» are unchanged;
  - header, footer, empty and «No aplica» rules, and every other section are untouched.
- **`EmpresaResource::profileSectionsAction()`:**
  - the CheckboxList shows `->descriptions(SECTION_DESCRIPTIONS)`, a supported Filament 3 component;
  - its 2 columns apply from the `lg` breakpoint, so it is one column on a phone (`Forms\Concerns\HasColumns`);
  - defaults stay «Todas las secciones» with all 11 checked; the authorization, route and SPA exception are unchanged.
- **Not changed:** schema, stored settings, taxonomy, permissions, SMTP, «Exportar listado», the controller (authorization still runs before the data is gathered).

## 3. SELECTION_MATRIX (executed, real PDFs, `selection_matrix.json`)

Each single-option PDF was generated through the protected route for the full synthetic company «A» (company «B» exists too).
- **Method:** `pdftotext` reads the real text. The **body** is checked, meaning the text after the «Contenido» box, which names the omitted sections on purpose, minus the header and footer every page repeats.
- **Assertions:**
  - every marker of the selected section is present;
  - none of the other sections' markers, and nothing of company B, is present;
  - the header is on every page (text, 2 logos, band pixels);
  - the filename ends in `-parcial.pdf`, and «Secciones incluidas: <option>.» appears.
- **77 markers in total:** headings, labels and sentinels unique to one section. A fact a section legitimately repeats is checked in its own context: the sectors table row «ZZZSECTORPRINCIPAL ZZZSERVICIOCENTINELA», not the sector name also printed by an experience.
- **One fixture value changed:** the first foreign project's amount went from 3 to 1, so «1.000.001 - 10.000.000 USD» is only the annual billing (Operación).

| Option | Included blocks | Present / absent markers | Pages | Result |
|---|---|---|---|---|
| Datos generales y contactos | Datos generales, Contactos | 16 / 61 | 1 | PASS: no operation heading or field |
| Operación en Venezuela | facturación, empleados (rango), empleados (total RR. HH.), estatus | 6 / 71 | 1 | PASS: no general or contact body |
| Clientes | nombre y país | 2 / 75 | 1 | PASS: no general fields, though the query joins them |
| Cámaras | nombres | 2 / 75 | 1 | PASS |
| Sectores de actividad económica y servicios | sector y servicios | 2 / 75 | 1 | PASS |
| Categorías CPV | categoría, principal, origen, nota | 4 / 73 | 1 | PASS |
| Enfoque de sostenibilidad | áreas y estado | 4 / 73 | 1 | PASS |
| Recursos en Venezuela | RR. HH., maquinarias y equipos, instalaciones, inventario | 12 / 65 | 1 | PASS: no operation, general, client or management body |
| Experiencia relevante | one card per work | 11 / 66 | 3 | PASS |
| Presencia internacional | oficinas, proyectos en otros países | 10 / 67 | 1 | PASS: no Experiencia relevante block or sentinel |
| Sistemas de gestión | 6 cards with «Otros» | 8 / 69 | 1 | PASS: no resources or general body |
| generales + operacion | both | 22 / 55 | 1 | PASS: «Datos generales», «Contactos», «Operación en Venezuela» and «Facturación anual:» each once |
| all 11 (list) | everything | 77 / 0 | 7 | PASS: title «PERFIL COMPLETO», `perfil-<rif>.pdf`, the 12 headings in report order |
| all 11 (array) and default | everything | = all 11 | 7 | PASS: body byte-identical to «all 11» |

**Malformed or oversized values, under the revised limits (`sectionsFrom`):**
- **11-entry array:** valid. **12-entry array:** complete profile.
- **Padded string:** exactly 330 characters stays `['operacion']`; 331 characters gives the complete profile.
- **Look-alike keys:** `Operación,operacion;generales` gives the complete profile (no valid key).
- **Case and order:** `OPERACION, generales` gives `['generales','operacion']`.
- **Kept from before:** `<script>`, 600-character, `;DROP` and `?empresa=` cases, and denied access with 0 report-view queries and 0 renders.

## 4. TESTS (isolated runner `infra/contabo-test-runner` @ `3e76459`; staging 200 before and after)

- **`3ffa85d` focused** (`CompanyDossierPdf|ExportAccess|UserManual|ManagementLabels`): **OK (35 tests, 2,615 assertions)**, run `20261020T083135Z-3ffa85d47eff`.
  - **New:** `every_section_option_prints_only_its_own_blocks`.
  - **Extended:** the allowlist test (revised limits), the selector test (operacion-only, generales-only and all-11 redirects; 11 options checked by default with their descriptions, on a fresh page) and the manual test (11 options and descriptions).
  - **Kept:** complete/default, minimal, «No aplica», access control and header on every page.
- **`151e116` (first run):** 34 of 35 passed. The one failure was in my test: it reused the Livewire page after an earlier choice, so the list was not the default. The fix checks a fresh page (`3ffa85d`); the code under test did not change.
- **Runner «findings=1»:** the runner's informational migration-order line («0 violation(s)»). It is not a test result.
- **Not re-run:** the full suite and the browser check. Only the section split and the selector descriptions changed, and both are covered by the focused tests.

## 5. PDF_VISUAL

- **Rendered:** pdftoppm, 100 page images from 29 PDFs.
- **Inspected one by one:** the 11 single-option PDFs (every page of experiencia, 3 pages), generales + operacion and page 1 of the complete profile.
  - **Datos generales and Contactos:** without the operation block.
  - **Operación en Venezuela:** alone, with its four values.
  - **Both:** in that order, once each.
  - **Complete:** Datos generales → Contactos → Operación en Venezuela → Clientes …, otherwise the accepted layout.
  - **Every page:** band, logos, title, name and RIF, «Página N de M»; no overlap, no clipped table.
- **Evidence (synthetic):** `audit/runs/pdf_operation_section/pdf/` (PDFs, text, page PNGs, `selection_matrix.json`, `header_pages.json`), with `summary.txt` and `phpunit.txt`.

## 6. MANUAL and operator note

- **`resources/manual/manual_usuario.md` `#descargar-perfil`:**
  - the 11 sections as a table, option → contents, using the selector's own descriptions;
  - a line saying that Datos generales and Operación are separate options;
  - the selector step (the 11 options are all checked, each with its description);
  - «las 11 marcadas = perfil completo».
- **`docs/deploy/DESCARGAS_PERFIL.md`:** the key → option → PDF blocks mapping, `operacion` as its own key, and the derived limits.
- **`docs/deploy/smoke_complete_profile_readonly.php`:** the check is now «11 sections, `operacion` second, one description each». It is for the next authorized deployment and was not run in this round.

## 8. Deployment to staging (`6288459314`, 2026-10-20)

- **Preflight (read-only):**
  - the deploy branch and the staging checkout were at `514fa6e` (0 tracked modifications), with no workflow in progress;
  - `514fa6e` is an ancestor of `87dd997` (fast-forward);
  - the runtime diff is exactly the 5 reviewed files, with an empty `database/`;
  - app and nginx up, no pending migrations, `app.debug=false`, `mail.default=log`, maintenance off, login 200.
- **Rollback point:** the running image `7a557f27edff` was tagged `perfilafiliados-app:pre-opsection-514fa6e` before the push.
- **Promotion:** normal push `514fa6e..87dd997` to `feature/upgrade-filament-v3`, not blocked. «Deploy a Contabo» run `39405700502`: **success** (10:07:41Z → 10:08:22Z).
- **Post-deploy (read-only):**
  - checkout `87dd997`, 0 modifications; `app` on image `f91e97322189`; nginx up;
  - no pending migrations, `app.debug=false`, `mail.default=log`, maintenance off.
- **Smokes:**
  - `smoke_complete_profile_readonly.php`: **13/13**, including «11 secciones (operacion propia)»;
  - `smoke_export_access_readonly.php`: **25/25**;
  - `smoke_task_0010c_readonly.php`: **18/18**;
  - `selector_manual_check_readonly.php` (new, no database access): 11 sections, one description each, the selector uses them, the manual has all 11 names and descriptions, generales-only = `[generales]`, operacion-only = `[operacion]`.
- **Public HTTP:** `/admin/login` 200; `/no-existe` 404; `/pdf/999999999` 302 to the protected route, then 302 to the login.
- **Rollback (not needed):**
  1. `docker tag perfilafiliados-app:pre-opsection-514fa6e perfilafiliados-app:latest`;
  2. `git reset --hard 514fa6e` in the staging checkout;
  3. `docker compose up -d app`;
  4. verify login 200 and the smokes.
  No database operation is involved.
- **Scope of the verification:** source and configuration smokes on the running app. The per-option PDF content is proven by the isolated run at `3ffa85d` (§3–5). **Not done by the developer:** an authenticated browser download of a real company (owner UAT).
- **Evidence:** `audit/runs/pdf_operation_section/deploy/`.

## 9. LIMITATIONS

- **Owner UAT pending:** the authenticated partial and full download of a real company was not exercised by the developer (§8).
- **Selector on a phone:** single column by Filament's breakpoints (verified in the vendor code). Not viewed in a browser in this round.
- **Umbrella sections:** Recursos, Presencia and Gestión stay one option each, as instructed. No per-block controls.
- **Scope of the evidence:** synthetic data only, on staging-identical views. No live profile was downloaded and nothing was written to staging.
- **Pre-existing, unchanged:** the `JoinViews` (Cámara) defects; mail still in `log`.
