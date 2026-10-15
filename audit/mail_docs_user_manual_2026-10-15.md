# CPV-MAIL-DOCS-AND-USER-MANUAL

- **Instruction:** Issue #2 comment `6189493183`. It records that the owner reported all of TASK-0010C's manual tests as passed (**OWNER_REPORTED_UAT_PASS**). That is the owner's report, not an additional automated run.
- **Branch:** `feature/mail-docs-and-user-manual`, created from `0becd37`. That commit is the C checkpoint: the deployed `a5772345` plus docs/audit only. Verified: it descends from `a5772345`, and there are no changes outside `docs/` and `audit/`.
- **TESTED_HEAD:** `433fbf05d4a82600f75e3bd1c31672112bda61c0` (manual, help and documentation). **REVIEW_HEAD:** the commit that adds this report and the checkpoint (audit/docs only on top of TESTED_HEAD).
- **Deployed:** **YES**, on 2026-10-15 at 11:05Z. Staging runs `d23c0e6` (accepted in `6190018710`, authorized in `6190034071`). The method was **MANUAL_SSH_DEPLOY** (route `6190372221`) with no GitHub Actions run. See §9.
- **Mail:** **PENDING_OWNER_CONFIGURATION**. Staging stays in non-sending mode (`log`). No SMTP, secrets, recipient, real send or retry of #1 was touched.

## 1. PDF baseline

| Candidate | Commit | Content | Verdict |
|---|---|---|---|
| **`public/user.pdf`** | `e398bbc` (2026-06-12, initial import) | "Perfil de Afiliados. Manual de Usuario"; Word metadata "Manual_Afiliados - 5-12-2022", created 2023-02-27; **22 pages**; sha256 `8b663b1a…05ef` | **BASELINE** |
| `app/images/user.pdf` | `e398bbc` | **Not a CPV manual**: the guide for a third-party audio plugin ("Auburn Sounds Lens", 16 pages) | ruled out (see Findings) |

The PDF was **read in full**: text with layout (`pdftotext`) and **all 22 pages rendered as images**, to read the screenshots and their captions. This ran in a throwaway container. The PDF was not modified or overwritten. The `.docx`/`.md` files in `docs/manual_usuario/` are later working drafts, not the PDF baseline.

## 2. Coverage matrix: every PDF page → online section

Sections are anchors in `resources/manual/manual_usuario.md`. **R** = kept, **U** = updated to the current interface, **N** = new from the PDF, **X** = retired, with the reason.

| PDF p. | Topic | Online section | Status and reason |
|---|---|---|---|
| 1 | Cover | — | X: cover with no instructions |
| 2 | Purpose of the system; the company creates users and the Chamber authorises them; open www.camarapetrolera.app | `#que-es`, `#ingresar` | **U**: purpose and "one user per person" added. The address is not hard-coded because it differs per environment ("la dirección que le indicó la Cámara") |
| 3 | "Creación de cuenta", "Registrarse", activation email, check spam | `#crear-cuenta` | **U**: the current link is "Abrir una cuenta", plus the anti-bot check. Steps added for when the email does not arrive |
| 4 | First login with no role or company; tell the Chamber; Desktop with name, menu, number of companies and % | `#escritorio` (**N**), `#empresa-no-asociada` | **U/X**: the Desktop and side menu (☰ on mobile) are explained. The "Cantidad de empresas / Porcentaje" counters are retired: the panel no longer shows them; completeness lives in "Completado del perfil" (`#completitud`) |
| 5 | Empresas menu, company list, "Editar" | `#abrir-empresa` | **U**: open by clicking the row; PDF column |
| 6 | General data (RIF, name, year, address, phone, city) and contact data (web and social networks) | `#formulario-por-pasos` 1–2, `#datos-de-la-camara` | **R/U**: RIF and name are managed by the Chamber; phone format |
| 7 | Operations (turnover, employees, status, capital); Clients over 15 years, free-text name, country, "Añadir cliente" | `#formulario-por-pasos` 3–4 | **U**: sectors (maximum 2) added; clients with country required and the bin icon |
| 8 | "Guardar" button and "Guardado" message | `#guardar` | **U**: the current button is "Guardar cambios" |
| 9 | Additional data tabs; Chambers with "Vincular" | `#resto-del-perfil`, `#camaras` | **R** |
| 10 | Link-chamber window (Vincular / …y vincular otro / Cancelar); new contact with name, position, phone, email; Crear / …crear otro / Cancelar | `#botones-ventanas` (**N**), `#contactos` | **U**: the window buttons are explained once for all tabs |
| 11 | Contact list; "Vincular" a contact across companies the user manages | `#contactos` | **X**: that option **was removed** from the interface. The manual now says to create the contact in each company |
| 12 | Sectors and services: link sector, then service | `#sectores-y-servicios` | **X** as editable: the tab is **read-only** and replaced by **Categorías CPV** (`#agregar-categorias`) |
| 13 | Sustainability: Sí–Activo / Sí–Inactivo / No; the 8 approaches with descriptions; "Nuevo Enfoque"; Area / Descripción / Status window | `#sostenibilidad` | **U**: the 8 approaches (confirmed in current code), what each status means, steps, "respond to all eight even if No". Current button: "Crear" |
| 14 | Resources in Venezuela: what they are, order of magnitude, left-menu module listing companies | `#recursos` | **U/X**: they are now **a tab inside the company** with 4 buttons. The left-menu module and the company picker were retired |
| 15 | Create Resource wizard: company, human resources by type and Junior/Medium/Senior, total, "Añadir a recursos humanos" | `#recursos` | **U**: the actual types from the catalogue (`storage/tituloshr.json`). The PDF's "total" is **not documented** because it was not verified in the current interface |
| 16 | Machinery and equipment: categories, quantity, value | `#recursos` | **R**: the 9 categories from the catalogue |
| 17 | Facilities (types, quantity, m², ownership); Inventory (raw material or finished product, unit, value) | `#recursos` | **R**: catalogue types; ranges |
| 18 | "Crear" (first time) vs "Guardar" (editing) at the end of the wizard | `#botones-ventanas`, `#recursos` | **U**: there is no wizard any more; each window is confirmed separately |
| 19 | Management systems: 6 groups, certifications, "Otros", "Crear Sistemas de Gestión" | `#sistemas-de-gestion` | **U**: 6 buttons, example certifications from code labels, "Otros (especifíque)", No Aplica |
| 20 | International presence: create, choose company, offices (country, m², employees, active) | `#presencia-internacional` | **U**: inside the company with "Crear" (the company is not chosen any more); offices kept |
| 21 | Experience abroad over 20 years, consolidated by country, role, amount, employees, clients; it counts even without an office | `#presencia-internacional` | **U**: 20 years, by country, the roles (Subcontratista / Contratista principal / Ambos, confirmed in code), amount in ranges; the tip is kept |
| 22 | Relevant experience over 10 years: date, infrastructure, magnitude, H-H, classification, description, "Añadir experiencia" | `#experiencia` | **U**: 10 years, one experience per project; current button "Agregar experiencia" |

**All 22 pages are accounted for.** Gaps declared on purpose:
- the PDF's address (p. 2);
- the Desktop counters (p. 4), which no longer exist;
- the "total" for human resources (p. 15), not verified;
- contact linking (p. 11) and sector editing (p. 12), retired from the interface.

The online manual already covered, beyond the PDF, the CPV taxonomy, categories, review requests, No Aplica, completeness, the user profile and privacy. Those are kept.

**No screenshots were added.** The manual's test requires it not to depend on images, and the steps are clear without them. No personal or company data was published.

## 3. Manual sections changed, and the change each one corresponds to

| Section | Change |
|---|---|
| `#que-es` | purpose of the system (PDF p. 2), one user per person |
| `#ingresar` | the address the Chamber gives you; "Entre a su cuenta" screen |
| `#crear-cuenta` | verification steps and what to do if the email does not arrive. This **depends on the mail activation** (§4) |
| `#escritorio` (new) | first login, side menu on mobile, with or without an assigned company |
| `#formulario-por-pasos` | clients: free-text name, required country, bin icon |
| `#botones-ventanas` (new) | Crear / …crear otro / Vincular / …vincular otro / Cancelar; confirmation notice; required fields marked in red |
| `#contactos` | contact linking across companies retired |
| `#recursos` | the actual catalogue values; ranges (order of magnitude) |
| `#sistemas-de-gestion` | groups with example certifications; "Otros" |
| `#experiencia` | last 10 years, one per project |
| `#presencia-internacional` | 20 years, by country, roles, amount in ranges |
| `#sostenibilidad` | the 8 approaches, what each status means, steps |
| `#errores-frecuentes` | 4 new rows (verification email, "crear otro", contact from another company, window that does not close) |
| `#contactar-camara` | verification email that did not arrive |
| `#privacidad` | the accuracy notice that the system itself shows |
| Admin help `#correo-del-sistema` (new) | a single global configuration; recipient ≠ transport; effect of simulated mode on verification. **No** variables, servers or keys |

Not exposed to the affiliate: transport, simulation, "servidor de correo", retries, paths or infrastructure (checked by test and by grep).

## 4. System mail: inventory by name only (detail in `docs/deploy/CORREO_DEL_SISTEMA.md`)

- **Mapping:** `config/mail.php`:
  - `default` ← `MAIL_MAILER`;
  - `mailers.smtp` ← `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION`, `MAIL_USERNAME`, `MAIL_PASSWORD`, plus the optional `MAIL_EHLO_DOMAIN`;
  - `from` ← `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`.
  - Other mailers are defined but unused (`ses`, `mailgun`, `postmark`, `sendmail`, `log`, `array`, `failover`).
  - Installed: Laravel 12 with `symfony/mailer`. Template: `.env.example`.
- **Persistent source:** the `.env` in each environment's deployment directory on the server. It is outside git (checked: `.gitignore` matches `.env`) and is maintained by the owner or operator.
- **Injection:** `docker-compose.yml` → `env_file: .env` for the `app` service.
- **Generated cache:** `config:cache` (run by the deploy) writes `bootstrap/cache/config.php`, values included. **It is not the store:** it is ignored by git (`bootstrap/cache/.gitignore: *`, checked), it is a host mount, it is never read or copied, and after changing the `.env` the **container must be recreated first and the cache regenerated inside it** (corrected; see §8).
- **Applying a change (corrected, §8):** a future authorized change follows this order:
  1. edit the `.env`;
  2. `docker compose config --quiet`;
  3. **recreate** `app` (`up -d --no-deps --no-build --force-recreate app`), which refreshes the environment;
  4. `config:cache` **in the refreshed container**;
  5. validate.

  Editing the `.env` and running `exec … config:cache` in the existing container regenerates the **old** configuration, and `restart` does not reload `env_file`.
- **Safe check:** `docs/deploy/mail_status_readonly.php`, reporting configured/missing only. **Run on staging on 2026-10-15:**

  ```
  mailer_por_defecto=log
  transporte=log
  envia_fuera_del_servidor=no (simulado)
  configuracion_cacheada=si
  remitente_direccion=configurado
  remitente_nombre=configurado
  ```

  On the server's `.env`, by key names only: `MAIL_MAILER`, `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME` are present. Host, port, encryption, username and password are **absent**.
- **Flows:**
  - registration verification (`Registered` → `SendEmailVerificationNotification`, with the panel requiring `EnsureEmailIsVerified`);
  - category-request notice (`TaxonomyCategoryRequestMail`);
  - password reset: **not enabled**.

  All use the default mailer. **No overrides** (no `Mail::mailer(...)`).
- **Business setting ≠ transport:** the request recipient lives in the database (singleton) and is edited by Super Admin under "Configuración de solicitudes".
- **Rollback (corrected, §8):** restore the `.env` (or set `MAIL_MAILER=log`) and apply **the same full sequence**. Validate that `transporte=log`; until then, assume the system can still send.
- **Activation prerequisites:**
  - provider, sender and recipient;
  - SPF/DKIM/DMARC;
  - secure provisioning;
  - a controlled test to an address of the owner;
  - review the reservation's duplicate risk, simulated notices, #1, and users who could not verify.

**GLOBAL MAIL ACTIVATION PENDING**: waiting for the owner's provider, configuration and credentials, and an explicit authorization for a controlled activation.

## 5. Standing rule added (governance)
- `docs/orquestador/ACCESS_BOOTSTRAP.md` §8 (end-of-round checklist): the **documentation rule**. Every round that changes behaviour evaluates its impact and updates the manual or help in the same delivery, reports the sections and their correspondence to the feature, or records why there is no effect. A visible change is not complete while its instructions are stale. There is a single online source; the PDF is not regenerated.
- `docs/orquestador/PROJECT_KNOWLEDGE.md` §8: the same rule, plus "una sola configuración de correo global". §9 marks the old read order as history.
- `docs/orquestador/access_map.json`: new `system_mail` capability (`pending_owner_configuration`). The read order is updated and the previous one kept.

## 6. Checks (content only; no application code changed)

Isolated run `20261015T101317Z-433fbf05d4a8` at TESTED_HEAD, harness `infra/contabo-test-runner @ 246b0d0`:
- **Focused PHPUnit** (`UserManual|TaxonomyCategoryRequestOperations|TaxonomyCategoryRequestTest`): **`OK (45 tests, 510 assertions)`**. It covers:
  - manual content, labels and forbidden terms;
  - safe rendering;
  - the manual page;
  - the admin help without secrets;
  - the affiliate manual without internals.
- **Browser on the manual** (`ux_check.mjs`): **40/40**. 3 widths × light/dark, index links reaching their headings, no horizontal overflow, contrast, keyboard, guest blocked. Screenshots: `audit/mail_docs_user_manual/run_433fbf0/ux/`, reviewed by eye. They show the new text, and a user with no company sees the menu without "Empresas", as `#escritorio` now describes.
- Isolation 19/19, schema 14/14, fixtures 8/8, taxonomy unchanged, no leftover containers.
- grep for internal or transport terms in the manual and help: no hits.
- **Not run:** the full suite. No application code changed (only `resources/manual/*.md` and `docs/`), and the instruction does not require it.

## 7. Findings (not fixed: outside this round)
1. **`app/images/user.pdf` is a third-party PDF** (the "Auburn Sounds Lens" audio-plugin guide) that came in with the initial import. It has nothing to do with CPV. Removing it is an owner decision.
2. **Management systems label:** the `ovid` checkbox is labelled **"COVID."** in `ManagementRelationManager`, while the PDF shows "OVID". This looks like a typo in the UI. The ISO 9001 and ISO 17025 checkboxes have no explicit label (the default label from the field name would be shown). The manual avoids both. They need a code round.
3. **Registration verification under non-sending mode:** a new user does not get the email, the panel requires a verified email, and there is no action for the Chamber to verify them by hand. It is recorded as a dependency of the mail activation.
4. Earlier findings still open: `EmpresaPgsql` (a dormant fatal); the notice reservation does not guarantee a single send; simulated notices cannot be retried.

## 8. Correction of review `6189828037`: refreshing the environment and the cache

**What was wrong:**
- `CORREO_DEL_SISTEMA.md` §4 and §6, and this report's §4, said to edit the `.env`, run `config:cache` with `docker compose exec`, and that there was "no need to restart".
- With `env_file: .env`, the environment is injected **when the container is created**:
  - editing the file does not change the running container;
  - `restart` does not reload it;
  - `config:cache` in that container rewrites the **old** configuration.
- The rollback had the same flaw: it promised to stop sending without actually doing so.

**What it says now** (`CORREO_DEL_SISTEMA.md` §3, §4.1–§4.3, §6.6):
- **Separation:**
  - the container environment, refreshed only by **creating** a container;
  - Laravel's cache in the shared `bootstrap/cache` mount, regenerated **in a container that already has the new environment**.
- **Sequence for this application** (a future change, not executed):
  1. edit the `.env`;
  2. `docker compose config --quiet` (without `--quiet` it prints values: not to be used);
  3. `docker compose up -d --no-deps --no-build --force-recreate app`;
  4. `docker compose exec -T -u www-data app php artisan config:cache`;
  5. validate with `mail_status_readonly.php`, the container start time, HTTP 200 and the logs.
- **Prerequisites:** authorization; checkout and image equal to the deployed SHA; a `.env` backup on the server; and a window of a few seconds during which nginx may answer 502.
- **Consistency window:** until the cache is regenerated, the app keeps using the old cache, so the old behaviour holds consistently.
- **The deploy workflow** regenerates in a fresh container (`docker compose run --rm`) and then runs `docker compose up -d`. It also resets the branch, rebuilds and migrates, so it is not the route for a config-only change, and even when used the recreation must be confirmed.
- **Rollback** applies the same full sequence, and is not considered done until the validation shows `log`.
- The unconditional "no need to restart" is removed. **Restarting** (the same container) and **recreating** (a new container) are kept distinct.

**What was checked:**

*Against the source:*
- `docker-compose.yml`: `app.env_file: .env`, and the mounts for `bootstrap/cache`, `storage` and `public`;
- `.github/workflows/deploy-contabo.yml`: `build`, then `run --rm app sh -c '... config:cache ...'`, then `up -d`;
- `Dockerfile`: no opcache extension or ini, so `config.php` is reread on every request (not measured live).

*Disposable demonstration:* a separate Compose project on the VPS (Compose 5.5.0), `alpine:3.20` image, a single non-secret marker variable, removed afterwards. It did not touch the staging app, its `.env` or its cache.

| Step | Value the container sees |
|---|---|
| initial | old |
| `.env` edited + `exec` | **old** |
| `restart` | **old** |
| `run --rm` | new |
| `up -d` (detected the change and recreated) | new |
| `up -d --no-deps --no-build --force-recreate` with a third value | third |

- **Not touched:** the live `.env`, `MAIL_MAILER`, the recipient, the cache and the staging containers.
- **Not rerun:** the suite and the browser checks. There are no code or manual changes; only these two documents.
- The manual content and the PDF matrix (§1–§3) are **unchanged**.

## 9. Staging deployment (MANUAL_SSH_DEPLOY, 2026-10-15)

- **Why manual:**
  - The push did not trigger the workflow. All changed paths match `paths-ignore`, including `resources/manual/*.md`.
  - `workflow_dispatch` returned 403 (`actions=write`), and `main` has no workflow file.
  - Blocker report: `6190243300`. Route authorized: `6190372221` option (c).
- **Preconditions (all passed):**
  - The deployment branch and the candidate were both at `d23c0e6`; the runtime was `a5772345`.
  - There were 0 tracked modifications. The untracked paths are only the published `public/css|js` assets.
  - The recovery image `perfilafiliados-app:pre-manual-a577234` equals the image that was in use.
  - 0 pending migrations, and no concurrent deploy.
  - The runtime diff was only `resources/manual/manual_usuario.md` and `ayuda_solicitudes_admin.md`.
- **Execution:** the literal `script:` block of the workflow at `d23c0e6`, with only the YAML indentation removed (sha256 `49ec7d84…`), sent over SSH as `bash -s`.
  - Steps that ran: reset to `d23c0e6`, `chown`, build (new image `b66d8024…`), then `run --rm` with `down`, `package:discover`, `db:skip-mysql-only-view-migrations`, `migrate --force` ("Nothing to migrate"), `filament:assets`, `config:cache`, `view:cache`.
  - **Defect in this route:** `docker compose run` read the rest of the script from stdin, so `up -d`, the nginx reload and `artisan up` **did not run**. The exit code was still 0, which hid it. The site was left in maintenance (503) with the old container.
  - **Recovery:** those three literal lines were run once more, with stdin closed. `app` was recreated, nginx reloaded and the site was live at 11:05:38Z.
  - Maintenance window: about 1 minute.
  - For the future: do not send this script over stdin. Pass it as a file, or close stdin on `run`. The workflow (`appleboy/ssh-action`) does not have this problem.
- **Post-state:**
  - The checkout is at `d23c0e6` with 0 tracked modifications.
  - `app` was recreated at 11:05:37Z with image `b66d8024…`.
  - The `.env` sha256 is unchanged.
  - Maintenance is off, `/` and `/admin/login` return 200, there are 0 pending migrations and 0 new `ERROR` entries in the log.
- **Manual smoke** (`docs/deploy/smoke_manual_readonly.php`, in-memory users, READ ONLY + ROLLBACK): **14/14**.
  - The deployed source equals the candidate (sha256 `738ef1bf…`).
  - Affiliate and admin both get 200; the 15 new or expanded anchors are present; the index links the 11 level-2 sections (subsections hang under them by design); the mobile viewport is declared.
  - The panel links to the manual. The admin help returns 200 with `#correo-del-sistema` for admins and 403 for affiliates.
  - No mail was sent.
  - Guests, checked with real HTTP: `302 → /admin/login` on the manual and on the help.
  - The first run of this smoke failed 4 checks, all problems in the test, not the site: it expected `###` headings in the index, and the in-process guest request without a session returned 500, while real HTTP returns 302. Both were fixed and the smoke was rerun.
- **0010C smoke (existing): 18/18.** Request #1 keeps fingerprint `2e64f23555b01ad30f845bdd53cedc15`, there are no new attempts and no mail was sent.
- **Mobile:** layout covered by the accepted isolated check (`run_433fbf0`, 40/40, 3 widths; the content is identical). No new browser run.
- **Mail:** PENDING_OWNER_CONFIGURATION, unchanged: `log`, simulated, cached, sender configured.
- **Evidence:** `audit/runs/manual_deploy_2026-10-15/` (`pre_state.txt`, `deploy_run.log` sanitized, `post_state.txt`, `smoke_manual.json`, `smoke_0010c.json`).
- **Maintenance pending, not fixed in this delivery:** the workflow filters ignore Markdown that is served from `resources/manual/`. A manual-only change never deploys automatically.

## 10. Owner feedback after the deployment: the manual is organized by system module

- **Owner (chat, 2026-10-15, after the deployment):** "the manual looks exactly the same, and the index focuses on the taxonomy, grouping everything else under 'Resto del perfil'". The manual must cover the whole system: what the 2022 manual had, plus the taxonomy.
- **Cause, confirmed:**
  - The level-2 index was **identical** at `a5772345` and at `d23c0e6`: 11 entries, **5 of them taxonomy**.
  - The 64 new lines went into level-3 subsections under "Ingreso y cuenta" and "Resto del perfil", and the index only shows level 2 (`UserManualRenderer`).
  - So the content was there, but it could not be seen from the index.
- **Change** (`4b7cab66e4f580da5854a1f97afde3cac30822aa`, `resources/manual/manual_usuario.md` only):
  - The index now has 18 entries, one per system module, in the **real order of the company tabs**:
    1. Qué es;
    2. Ingreso y cuenta;
    3. El Escritorio y el menú;
    4. Datos generales;
    5. Las pestañas de su empresa;
    6. Cámaras;
    7. Contactos;
    8. Usuarios;
    9. Sectores y servicios;
    10. **Categorías CPV (Taxonomía)**;
    11. Recursos en Venezuela;
    12. Sistemas de gestión;
    13. Experiencia relevante;
    14. Presencia internacional;
    15. Enfoque de sostenibilidad;
    16. Completitud y «No Aplica»;
    17. Errores frecuentes;
    18. Privacidad.
  - **The taxonomy is one entry**, and its former 5 sections are now its subsections.
  - New: the table "Qué puede hacer en el sistema" (`#mapa-del-sistema`) maps each task to its screen and section.
  - **Text:** a diff of the non-heading lines shows that only the opening paragraph and the "pestañas" paragraph were rewritten; the system map, the taxonomy intro and the "Completitud y «No Aplica»" intro are new lines. Every existing anchor is kept, so internal links still work.
- **Test contract** (`tests/Feature/Filament/UserManualContentTest.php`):
  - The index list was updated to the 18 sections.
  - New assertion: the order of `EmpresaResource::getRelations()`. If a tab is added or moved, the test fails and asks for the manual to be updated.
  - `section()` now finds sections at any heading level.
- **Checks:** isolated run `20261015T111556Z-4b7cab66e4f5` (harness `246b0d0`):
  - PHPUnit `UserManual|TaxonomyCategoryRequestOperations|TaxonomyCategoryRequestTest`: **OK (45 tests, 532 assertions)**;
  - browser check of the manual **40/40** (3 widths × light/dark, every index link, no overflow);
  - isolation 19/19, schema 14/14, fixtures 8/8;
  - the only "finding" is informational ("0 migration ordering violations"), the same as the accepted run;
  - staging 200 before and after.
  - Evidence: `audit/runs/mail_docs_user_manual/run_4b7cab6/`.
- **Not deployed.** Staging is still on `d23c0e6`. Because this commit touches `tests/**`, the push to the deployment branch **does** trigger the normal workflow; no SSH route is needed. Deploying needs authorization.
