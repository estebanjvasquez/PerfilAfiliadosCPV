# CPV-HOME-BRANDING-AND-LAYOUT: «Inicio» with the Perfil de Afiliados logo, and a card layout

- **Instruction:** Issue #2 `6210321522` (owner, 2026-10-16 07:23Z). It authorizes implementation and focused checks. It does **not** authorize deployment or a push to `feature/upgrade-filament-v3`.
- **Branch:** `feature/home-branding-layout`.
- **BASE_SHA:** `6e263021bdeb7af9d93ab8292a1a176127dc05bd`. It equals the deployed runtime `c02d4ec8f578b25f8101ac3109c7931e23298f2b` plus `audit/**` and `docs/**` only (no diff under `app/`, `resources/`, `routes/`, `config/` or `public/`).
  - Verified live before branching, read-only over SSH: VPS checkout at `c02d4ec`, 0 tracked modifications, app container started 2026-10-15T15:40:23Z with image `sha256:97236501…`, which is the same as the last deployment. `/admin/login` returned 200.
  - `origin/feature/upgrade-filament-v3` is also at `c02d4ec`. Nothing unrelated is stacked.
- **TESTED_HEAD:** `17e2b076f221f36037a193b8fcab58a11d243009`.
- **REVIEW_HEAD:** the commit that adds this report (docs/audit only on top of TESTED_HEAD).
- **Deployed:** **NO**.

## 1. Logo

- **Asset:** the existing `public/images/Perfil-Afiliados.png` is used as is: 400×206, truecolor.
  - Measured with GD: 67% of the pixels are fully transparent. The text is near-black and the accent is gold (`#9B5C00`).
  - Nothing was redesigned, cropped, recoloured or copied.
- **Where it goes:** the `<h1 class="fi-header-heading">` that Filament renders for the page. It goes through the supported `getHeading()` override on the page class.
  - No vendor file is touched.
  - No global rule hides any heading.
- **Accessibility:** the `<img>` has `alt="Perfil de Afiliados"`, which is the heading's accessible name. There is one `<h1>` on the page.
- **Size:** `height: 3.5rem` (56 px) below 640 px and `4.5rem` (72 px) above, with `width: auto`.
  - The `width="400" height="206"` attributes reserve the aspect ratio while the image loads.
  - Measured in the browser: rendered at 140×72, ratio 1.944, which is the original ratio.
- **Contrast:** on a transparent background, the near-black text would disappear in dark mode. So in dark mode only (`.dark .cpv-home-logo`), the logo sits on a white rounded surface with padding.
  - Measured surface: `rgb(255,255,255)` in dark mode, and the page's `rgb(250,250,250)` in light mode.
- **No duplicate:** the top bar shows the panel's brand *name* (`config('app.name')`, text, not a logo). It is unchanged, and the logo appears once on the page.

## 2. «Escritorio» → «Inicio»

- **New** `app/Filament/Pages/Dashboard.php` extends `Filament\Pages\Dashboard`, which is the way Filament documents for customizing the dashboard.
  - It is picked up by the panel's existing `discoverPages()`. The explicit `->pages([Filament\Pages\Dashboard::class])` registration is removed, so there is exactly one home page.
  - The URL is unchanged (`/admin`), and so is the route name (`filament.admin.pages.dashboard`).
  - The same widgets, authentication and middleware apply.
- `$title = 'Inicio'`. The sidebar item, the browser title (`Inicio - <app name>`) and the active navigation item all say «Inicio».
  - The base dashboard has no breadcrumbs, so there was none to rename.
- **Not renamed:** the Gerencia dashboard, the taxonomy «Dashboard» page, and the public landing and login branding.

## 3. Card arrangement

Inspected before the layout was assigned: the home has exactly three widgets, the same for every role.
- `AccountWidget` («Bienvenida/o» and «Salir»);
- `ProfileHelpWidget` («Ayuda para completar su perfil»);
- `EmpresaResource\Widgets\StatsOverview`, the two veracity notices. It is lazy, has no role rule, and shows fixed text. Its two existing counts are computed and not displayed; that is unchanged.

There is no admin-only widget on this page. None was added, removed or made visible to another role.

| Width | Layout |
|---|---|
| ≥ 1024 px | 3 columns: account (1) beside help (2). The notices are below at full width, in 2 columns. |
| < 1024 px (tablet, mobile) | stacked in source order: account, help, notices. Below 768 px, the notices also stack. |

- **Spans:** `Dashboard::getColumns()` returns `['default' => 1, 'lg' => 3]`, and `ProfileHelpWidget::$columnSpan` is `['default' => 1, 'lg' => 2]`. The account widget keeps its default 1. The notices keep Filament's default `full`, and `StatsOverview::getColumns()` is now 2, so a third empty column is no longer reserved.
- **Heights:** none are fixed. Each card takes its content height, so nothing is clipped.
- **Readability:** the notices were rendered as statistic *values* (`text-3xl`), which made them look like a poster.
  - Scoped CSS (`.cpv-home-notice .fi-wi-stats-overview-stat-value`) sets 16 px / 1.5, weight 500.
  - The class is added with `Stat::extraAttributes()`. The text of the notices is unchanged.
- **Order:** the DOM order is the visual order, so keyboard and reading order are logical. Measured: Tab reaches «Salir», then «Manual de usuario», then «Mapa de la taxonomía».
- **CSS scope:** `resources/views/filament/pages/home-styles.blade.php` is injected with a `PAGE_START` render hook scoped to `App\Filament\Pages\Dashboard`.
  - It is inline, because the panel compiles no Tailwind theme of its own.
  - Every selector uses the round's own classes. A test confirms that the manual page does not receive it.
- **Not added:** filler, statistics, new queries, charts or business functionality.

## 4. Manual

`resources/manual/manual_usuario.md`:
- **Index row:** «**Inicio** y menú lateral → [Inicio y el menú](#escritorio)».
- **Section title:** «## Inicio y el menú {#escritorio}». The `#escritorio` anchor is kept on purpose, because the index, `UserManualContentTest` and any saved link point to it.
- **Body:** the page is called **Inicio** and is headed by the Perfil de Afiliados logo. The cards appear in order: «Bienvenida/o» with «Salir», then «Ayuda para completar su perfil», which on the computer is to the right of «Bienvenida/o», and below them, at full width, the veracity notices. On the phone they stack in the same order. The sidebar item **Inicio** returns to this page.
- **Leftovers:** there is no «Escritorio» left anywhere in the manual (test).
- **PDF coverage:** `UserManualContentTest` is unchanged and green, so the 2022 PDF coverage is preserved.

## 5. Checks

All ran on the isolated Contabo runner (`infra/contabo-test-runner` @ `ac4fc4f`), against a disposable stack with synthetic users and a synthetic fixture. Staging returned 200 before and after each run, and no `cpvtest-*` container was left behind.

- **PHPUnit at TESTED_HEAD** (run `20261016T074315Z-17e2b076f221`): `--filter "HomeBrandingLayout|ProfileHelpWidget|UserManual|TaxonomyMap"`, **OK (40 tests, 346 assertions)**. Evidence: `audit/runs/home_branding/phpunit.txt` and `summary.txt`.
  - **New** `tests/Feature/Filament/HomeBrandingLayoutTest.php` (8 tests):
    - there is one home page, with the same URL and route name, labelled «Inicio»;
    - the `<title>` is «Inicio», and the `<h1>` is the logo with its alt, `src` and 400×206;
    - there is one logo, no «Escritorio» on the page, and a sidebar «Inicio» link to the same URL;
    - the asset exists at 400×206;
    - the styles are injected on the home page only;
    - the widget order (account, help, notices) and the spans;
    - the affiliate **and** the Super Admin get account, help and notices in that order, with the logout URL;
    - the notices widget renders both texts unchanged in 2 columns;
    - the manual section says «Inicio», mentions the logo and the order, keeps `#escritorio`, and contains no «Escritorio».
  - The tests for the accepted help card, manual and map are unchanged and green.
  - The full suite was **not** repeated, as the instruction asks for a cosmetic round.
- **Browser** (run `20261016T074624Z-17e2b076f221`, same TESTED_HEAD, `--ux-script ux_check_home_branding.mjs`): **135/135**. Evidence: `audit/runs/home_branding/ux/ux_check.log` and `ux_results.json`.
  - The guest is redirected to login.
  - It checks the synthetic affiliate and the synthetic Super Admin, at 1440, 820 and 390 px, in light and dark:
    - the `<h1>` is the logo, loaded from `/images/Perfil-Afiliados.png` at its natural 400×206, rendered with ratio 1.944 at 56–72 px, on a light surface;
    - no visible «Escritorio», the title says «Inicio», and the active sidebar item is «Inicio»;
    - card geometry: side by side on desktop, stacked in order below that, notices below and full width;
    - the notice text is 16 px;
    - no horizontal overflow.
  - On desktop, in light mode only:
    - keyboard order: Salir, Manual, Map;
    - both help links open their pages;
    - the sidebar «Inicio» returns to `/admin`;
    - «Salir» logs out.
- The first two PHPUnit runs at earlier heads were red on the new test only, for test-authoring reasons. Those were a guest-resolved panel URL, and the lazy notices widget not being in the initial HTML. The page did not change between those runs and TESTED_HEAD.

## 6. Screenshots (synthetic users and data only)

`audit/runs/home_branding/ux/home-{affiliate,admin}-{light,dark}-{desktop,tablet,mobile}.png`: 12 full-page captures. The sidebar overlay is closed below 1024 px.

## 7. Boundaries respected

- **Not changed:** schema, taxonomy, mail, credentials, account logic, external services, or the public landing and login.
- **Not touched:** vendor files.
- **Not done:** no deployment, and no push to `feature/upgrade-filament-v3`.
- **Pushed:** only `feature/home-branding-layout` and the runner branch `infra/contabo-test-runner`, for the browser check.
- **No** subagents, AI reviews, polling or background work.
- **Left alone:** `audit/orchestrator_handoff.json`, because the executor is suspended and that pointer is not a delivery channel.

## 8. Limitations

- **Not verified on staging**, because it is not deployed. All the evidence comes from the disposable stack.
- **Mobile «Salir»:** below 640 px, Filament's account card shows «Salir» as an icon button with an accessible label but no visible text. This is pre-existing Filament behaviour and was not changed (no account-card customization was authorized).
- **Pre-existing, not fixed (out of scope):** `StatsOverview::getCards()` still computes two unused counts and would divide by zero if the `empresas` table were empty. That never happens on staging.
- **Top-bar brand:** the top bar still shows `config('app.name')` as text. The disposable stack shows «Laravel»; staging shows whatever its `.env` sets. It was left unchanged, because the instruction is limited to the page heading.
