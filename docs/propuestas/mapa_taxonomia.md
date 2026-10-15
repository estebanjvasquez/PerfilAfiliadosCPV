# Proposal: taxonomy map for affiliates (CPV-TAXONOMY-MAP)

- **Status:** **IMPLEMENTED (v1)** on `feature/taxonomy-map`, **not deployed**.
  - The design direction was accepted with corrections A–F (`6191485610`), and implementation was authorized in `6191513374`.
  - This document reflects those corrections. Corrected sections are marked **[A]**–**[F]**.
  - Implementation evidence and measurements: `audit/taxonomy_map_2026-10-15.md`.
- **Origin:** the owner, in chat on 2026-10-15, after reviewing the reorganized manual (`37e3b71`). The taxonomy is very large and can confuse users, so the request is to assess a taxonomy map that is reachable from the user manual, as its own page showing the map rather than the manual.
- **Owner decisions already taken (chat, 2026-10-15):**
  - **D1:** families and groups with no categories are **shown** with the label «sin categorías todavía», not hidden.
  - **D2:** the 48 groups are **not** clustered into thematic areas for now.

## 1. Problem

The affiliate meets the taxonomy only through two tools:
- **free search**, which returns isolated routes;
- **browse one Family**, which first requires knowing which Family to look in.

Neither tool shows **what exists as a whole**, so users cannot answer "does the taxonomy cover what we do, and roughly where?". The manual explains the *structure* (Grupo > Familia > Categoría), but it cannot show the *content*.

## 2. Measured shape of the published taxonomy (staging, 2026-10-15)

These are aggregate counts only, read in a READ ONLY transaction with ROLLBACK. No company data was read.

| Level | Active | Notes |
|---|---|---|
| Group (level 0) | 48 | 3–17 families each, average 6.9 |
| Family (level 1) | 333 | 0–44 categories each, median 5, average 7.3 |
| Category (level 2) | 2,418 active (+698 inactive) | 1,572 goods (`bien`), 846 services (`servicio`); all are `chamber_relevance = belongs` |

- **Spanish names:** 100% at all three levels, so the map can be entirely in Spanish.
- **Descriptions:** **0 of 2,799** active nodes have a Spanish description. The map can show names, but it cannot explain what a category covers. That is a separate content task for the Chamber, outside this proposal.
- **Empty nodes:**
  - **55 families** have no active category;
  - **1 group** has none at all (CPV-48 «Textil, Cuero y Confección», 4 families, 0 categories);
  - CPV-45 «Servicios Generales» has 7 families but only 2 categories.
  - Per D1 these are shown as «sin categorías todavía».
- **Text volume:** about 77,000 characters of Spanish names in total.
- `branch` and `subbranch` (240 and 86 distinct labels) are flat labels repeated across families. They are not useful as a navigation level, and the map does not use them.

## 3. Proposed solution

A new **read-only page in the panel**, «Mapa de la taxonomía» (slug `mapa-de-la-taxonomia`). It is separate from the manual and reachable from it.

### 3.1 What the user sees

1. **A header.** It explains:
   - what the map is: «toda la Taxonomía CPV publicada, para ubicarse»;
   - that categories are **added from the company tab** «Categorías CPV (taxonomía nueva)», with a link to the manual section;
   - the totals: 48 groups, 333 families, 2,418 categories.
2. **Level 1: the 48 groups** as a grid of cards. Each card shows:
   - the group name;
   - «N Familias · M Categorías»;
   - the goods/services split, «X bienes · Y servicios» (decision 3).
   - **[3]** Any category whose offer type is neither `bien` nor `servicio`, or is missing, is counted honestly as «sin tipo». It is never folded into goods or services.
   - All counts come from the same visible published tree.
   - The grid has several columns on desktop and 1 on mobile, and an opened group spans the full row.
3. **Opening a group** shows its families, each with «N Categorías».
   - A family with 0 published categories shows **«sin categorías todavía»** (D1) and is not rendered as something that opens. The same label appears on the card of a group with no categories (CPV-48 today).
   - **[E]** The page explains that such a node has nothing to choose, and that a company is never linked to a whole Group or Family.
4. **Opening a family** lists its categories, each with a **Bien / Servicio** badge («Sin tipo» per decision 3).
5. **[Decision 2] Ordering:** Spanish alphabetical order at all three levels, ignoring case and accents («ñ» sorts after «n»). Equal labels are broken deterministically: exact text, then the internal id, which is never shown. The source taxonomy is not reordered or modified.
6. **[B] A name filter** («Filtrar el mapa por nombre»):
   - **Match:** a node matches when its own name contains the typed text, with at least 3 characters, ignoring case and accents. Only matching nodes are highlighted.
   - **Context:** the rest of what stays visible is context.
     - A matching **Group** stays visible and open with **all** its families (and, when opened, all their categories).
     - A matching **Family** stays visible and open with **all** its categories, inside its Group.
     - A matching **Category** stays visible with its Family and Group, so its full path is shown. Its Family is opened, and that Family's non-matching categories are hidden.
   - **Fewer than 3 characters:** the map stays complete, unfiltered, with the guidance «Escriba al menos 3 letras para filtrar.»
   - **No results:** «Ninguna coincidencia.» plus guidance to use «Buscar y agregar categoría» or request a review.
   - **Clear:** a «Limpiar filtro» button and the Esc key restore the complete map, collapsed and without highlights.
   - **What it is not:** the page states that this is name filtering, not synonym or semantic search.
   - **Escaping:** names are escaped by Blade and re-written by the filter only as text nodes (`textContent` / `createTextNode`). Unescaped taxonomy text is never injected.
   - **Without JavaScript:** the filter is not offered (it is hidden until the component starts, and a `<noscript>` notice explains it). Native `<details>` navigation keeps working. The JS filter is not promised to work without JavaScript.
   - With no filter, published empty nodes stay visible (D1).
7. **No internal codes:** `CPV-05.01…` is not shown, consistent with the affiliate's add flow, which shows only names. No ids, scores, `branch` labels or relevance fields either.

### 3.2 Deliberate non-goals (v1)

- **No adding categories from the map.** Linking stays in the company tab, with its limits, Family-vs-Category validation and confirmation. The map has no write path at all.
- **No thematic clustering** of groups (D2).
- **No "already in your company" markers.** Proposed for a later version. That needs per-company context and a company selector for multi-company users.
- **No graphical tree or mind-map diagram.** About 2,800 nodes would be unreadable, especially on mobile.
- **No descriptions** (none exist, see §2), and no change to the taxonomy, search, ranking or synonyms.

### 3.3 Access

- **[C] Who can open it:** exactly who can open the user manual.
  - `TaxonomyMapPage::canAccess()` delegates to `UserManual::canAccess()`.
  - The page lives in the same panel, behind the same `authMiddleware` (`Authenticate` and `EnsureEmailIsVerified`). So an unverified user is treated exactly like on the manual, and if the manual's access changes, the map's changes with it. `auth()->check()` alone is not the guard.
  - The published taxonomy is global and has no tenant or company scope. A user without a company may browse it, because the manual allows that user too.
- **[C] Nothing private reaches the browser:**
  - no company data is read;
  - the tree is passed through `getViewData()`, not as a Livewire public property, so it is not in the component state (snapshot);
  - there is no endpoint;
  - no codes, ids, inactive nodes or hidden fields appear in the HTML or in Alpine state.
- **[Decision 1] Navigation:**
  - (a) the manual has a «Ver mapa de la taxonomía» header button, and its new section `#mapa-taxonomia` explains the map;
  - (b) the «Categorías CPV (taxonomía nueva)» tab has a «Ver mapa de la taxonomía» link that opens in a new tab;
  - (c) **no sidebar item**;
  - the map has a clear «Volver al manual» link.
  - **[C]** The tab link only navigates. It is visible only when the user can already use that tab and can open the map. It does not change or broaden any company-edit permission.

### 3.4 Data and performance

- **[E] Publication rule, verified against the model:**
  - «Published» is `is_active = true`. That is the Chamber's «Activa» switch: on import only «BELONGS» is loaded active (see `ImportTaxonomy`), and it is the same filter the affiliate's add flow uses.
  - In addition, a node needs a visible, well-formed ancestor chain:
    - **Group:** level 0 with no parent;
    - **Family:** level 1 whose parent is a visible Group;
    - **Category:** level 2 whose parent is a visible Family.
  - Inactive nodes, descendants of inactive or hidden ancestors, level/parent mismatches and orphans are **excluded and never promoted to roots**.
  - The counts are those of the visible tree, and the totals are their exact sum.
- **One query** (SELECT only) loads the active nodes with their Spanish name (English fallback, never the code) and `tipo_oferta`. The tree is built in PHP.
- **[A] No cache in v1.** The tree is queried and built directly on each request, and was measured: 15–28 ms to build at production size in the isolated stack.
  - The earlier idea of a key from `count(*)` + `max(updated_at)` is **withdrawn**. It is not a collision-free change fingerprint: editing a non-maximum timestamp, second-precision writes, a same-count replacement or a write that does not touch timestamps can all preserve it.
  - If a cache is ever needed, it must have a documented freshness bound or reliable invalidation, without a new taxonomy revision mechanism just for this page.
  - «Zero writes» applies to business and taxonomy data. The v1 writes nothing to the application cache for this page either, and tests observe that.
- **[D] Rendering, measured** (details and numbers in the report):
  - The server renders the whole tree once, with native `<details>`/`<summary>`. The filter is a small Alpine component loaded on demand by Filament (`AlpineComponent`, `x-load`), so it also works with the panel's SPA navigation.
  - The first measurement, at real size (synthetic 48/333/2,438 tree), justified three simple changes:
    - compact markup for the repeated nodes;
    - a filter that writes to the DOM only where a node's state changes, skipping hidden subtrees;
    - `content-visibility: auto` on groups.
  - Results:
    - HTML went from 1.48 MB to 0.59 MB (gzip-6 **computed** from the measured HTML: 60 KB to 41 KB);
    - filter cost at 390px with 4x CPU throttling went from ~250–300 ms to ~30–160 ms per keystroke;
    - desktop filter cost is 4–27 ms.
  - The earlier "~400–500 KB" figure was an estimate and was wrong. It is superseded by these measurements.
  - Group lazy-loading was **not** needed and was not implemented.

### 3.5 Manual and help (standing documentation rule)

- `resources/manual/manual_usuario.md`:
  - a new subsection «Mapa de la taxonomía» (`#mapa-taxonomia`) under `#taxonomia-cpv`;
  - one row in «Qué puede hacer en el sistema»;
  - one row in «Errores y dudas frecuentes»: «Veo una Familia "sin categorías todavía"» → it cannot be used yet; choose a related category or request a review.
  - The manual's index contract (18 sections) does not change, because the new section is level 3.
- The admin help is unchanged.

## 4. Tests and checks (implemented; results in the report)

**PHPUnit** (`tests/Feature/Filament/TaxonomyMapPageTest.php`, 11 tests), run in the isolated harness only, never against shared Supabase:
- **Access:**
  - affiliate and admin get 200, and a guest is redirected to login;
  - an unverified user gets exactly the manual's response;
  - `canAccess()` equals the manual's.
- **Navigation:**
  - there is no sidebar item;
  - the manual's button and the map's return link are present;
  - the company tab's link has the right URL and opens in a new tab, and the company's links are unchanged.
- **[E] Tree rules:** inactive nodes, descendants of inactive ancestors and malformed rows (a category directly under a group, a family without parent, a group with a parent) are all absent and never promoted.
- **Counts:** the goods/services/«otro» split, and totals equal to the exact sum of the visible tree.
- **D1:** the empty label on families and groups, and an empty family is not rendered as openable.
- **Ordering:** Spanish alphabetical order with «ñ» after «n», plus a deterministic tie-break.
- **[C] No leaks:** no codes, `branch`/`subbranch`, relevance basis or inactive names in the HTML, and no tree in the Livewire snapshot.
- **[B] Escaping:** an `<img onerror>` name is rendered escaped.
- **[A] No writes:**
  - every executed SQL statement is observed on every configured connection while the page renders, and none writes;
  - no taxonomy cache key is written;
  - the taxonomy and company-link tables have identical row counts.

**Browser check** (harness `--ux-script ux_check_taxonomy_map.mjs --ux-seed ux_seed_taxonomy_map.php`, against a **synthetic production-size** tree):
- payload, DOM size, timings and filter cost, desktop and 390px with 4x CPU throttling;
- the filter's match / context / no-results / Esc / clear behaviour, highlighting, and HTML characters staying text;
- 3 widths × light/dark, with no overflow and contrast ≥ 4.5:1;
- keyboard;
- no-JS;
- SPA entry from the manual;
- the company tab link;
- guest blocked.

**Staging smoke after an authorized deploy:** in-memory users, READ ONLY + ROLLBACK.
- the page returns 200 for the affiliate and the admin;
- the counts on the page equal live aggregate counts;
- request #1's fingerprint is unchanged and no mail is sent.

## 5. Risk, effort, deployment

- **Risk: low.** Read-only, with no schema change, no migration, and no change to search, ranking, taxonomy data or company data. The main residual risk is page weight on slow mobile connections, covered by the measurable fallback in §3.4.
- **Effort:** one normal round: a page, a view, a small tree builder, tests, the manual, and the browser check.
- **Deployment:** the change touches `app/**` and `tests/**`, so a fast-forward of the deploy branch triggers the normal workflow. No SSH route is needed. Staging deployment needs explicit authorization.

## 6. Decisions (resolved in `6191485610`)

1. **Entry points:** the manual link AND the link from the company «Categorías CPV» tab; no sidebar item; a clear return link. **Implemented.**
2. **Ordering:** Spanish alphabetical order at all levels, case- and accent-insensitive and deterministic for equal labels; no change to the source. **Implemented.**
3. **Group cards:** family and category counts plus the goods/services split, from the same visible tree, with unknown or missing offer types shown honestly as «sin tipo». **Implemented.**
4. **Descriptions:** not a v1 dependency. Only real names are shown, and no definitions are invented or AI-generated. **Recorded as a future Chamber content request:** short descriptions for the 48 groups. No message to the Chamber is sent from here.

D1 (empty nodes shown with «sin categorías todavía») and D2 (no thematic clustering) are preserved.

**[F] Out of scope, and not done:**
- category writes from the map;
- migrations, taxonomy publication, ranking or search changes;
- company markers and descriptions;
- the `EmpresaPgsql` repair;
- mail activation.

The manual's PDF coverage is preserved: the manual only gains the map subsection and two FAQ rows.
