# CPV-MAIL-DOCS-AND-USER-MANUAL

- **Instruction:** Issue #2 comment `6189493183`. It records that the owner reported all of TASK-0010C's manual tests as passed (**OWNER_REPORTED_UAT_PASS**). That is the owner's report, not an additional automated run.
- **Branch:** `feature/mail-docs-and-user-manual`, created from `0becd37`. That commit is the C checkpoint: the deployed `a5772345` plus docs/audit only. Verified: it descends from `a5772345`, and there are no changes outside `docs/` and `audit/`.
- **TESTED_HEAD:** `433fbf05d4a82600f75e3bd1c31672112bda61c0` (manual, help and documentation). **REVIEW_HEAD:** the commit that adds this report and the checkpoint (audit/docs only on top of TESTED_HEAD).
- **Deployed:** **NO**. Staging is still on `a5772345`. The manual and the help live in `resources/manual/`, which is deployed content, so this branch is the candidate for a later, bounded deployment.
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
- **Generated cache:** `config:cache` (run by the deploy) writes `bootstrap/cache/config.php`, values included. **It is not the store:** it is ignored by git (`bootstrap/cache/.gitignore: *`, checked), it is a host mount, it is never read or copied, and it must be regenerated after changing the `.env`.
- **Applying a change:** an authorized operator edits the `.env`, then runs `config:cache` in the container as `www-data` (or redeploys) and validates. No restart is needed.
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
- **Rollback:** `MAIL_MAILER=log`, then `config:cache`.
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
