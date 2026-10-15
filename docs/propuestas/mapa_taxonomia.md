# Proposal: taxonomy map for affiliates (CPV-TAXONOMY-MAP)

- **Status:** PROPOSAL for orchestrator review. **No code has been written.** Nothing is deployed.
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

1. **A header** with one paragraph. It explains:
   - what the map is: «toda la Taxonomía CPV publicada, para ubicarse»;
   - that categories are **added from the company tab** «Categorías CPV (taxonomía nueva)», with a link to the manual section;
   - the totals: 48 groups, 333 families, 2,418 categories.
2. **Level 1: the 48 groups** as a grid of cards. Each card shows:
   - the group name;
   - «N familias · M categorías»;
   - a small goods/services split (illustrative format: «X bienes · Y servicios»).
   - The grid has 3–4 columns on desktop and 1 on mobile.
3. **Opening a group** shows its families, each with «N categorías». A family with 0 categories shows **«sin categorías todavía»** (D1). A group with no categories, CPV-48, shows the same label on its card.
4. **Opening a family** lists its categories alphabetically. Each one has a **Bien / Servicio** badge.
5. **A filter box** («Filtrar el mapa», with a minimum of 3 characters, ignoring accents and case):
   - it keeps only the groups, families and categories whose name contains the text;
   - it opens the matching branches automatically and highlights the match;
   - it shows «N coincidencias».
   - It **orients the user only**: it does not replace the ranked search in «Buscar y agregar categoría», which also uses synonyms and English. The page says so.
6. **No internal codes:** `CPV-05.01…` is not shown, consistent with the affiliate's add flow, which shows only names. No ids, scores, `branch` labels or relevance fields either.

### 3.2 Deliberate non-goals (v1)

- **No adding categories from the map.** Linking stays in the company tab, with its limits, Family-vs-Category validation and confirmation. The map has no write path at all.
- **No thematic clustering** of groups (D2).
- **No "already in your company" markers.** Proposed for a later version. That needs per-company context and a company selector for multi-company users.
- **No graphical tree or mind-map diagram.** About 2,800 nodes would be unreadable, especially on mobile.
- **No descriptions** (none exist, see §2), and no change to the taxonomy, search, ranking or synonyms.

### 3.3 Access

- **Who can open it:** any authenticated user, the same as the manual (`canAccess() => auth()->check()`). Guests are redirected to login by the panel middleware.
- **Navigation:**
  - (a) a new manual section «Mapa de la taxonomía» under «Categorías CPV (Taxonomía)», which explains the map and links to it;
  - (b) a link from the «Categorías CPV (taxonomía nueva)» tab header, next to «Buscar y agregar categoría»;
  - (c) **no new sidebar item**, so the affiliate menu stays short (Escritorio, Empresas, Manual de usuario).
  - (b) and (c) are open decisions (§6).

### 3.4 Data and performance

- **One query** loads every **active** node at the three levels, with its Spanish name and `tipo_oferta`.
  - The tree is built in PHP by `parent_id`.
  - Inactive nodes are excluded, and so is an active category under an inactive family.
  - The counts are those of the visible tree.
- **Cache:** the built tree is stored in the application cache. The key comes from a cheap fingerprint of the published taxonomy (`count(*)` and `max(updated_at)` over `taxonomy_categories` plus the Spanish translations).
  - A publish or edit changes the key, so the cache never serves stale data and needs no invalidation hook.
  - A TTL (e.g. 24 h) bounds storage.
  - There is no write to any taxonomy table.
- **Rendering:**
  - The server renders the whole tree once. Collapsible levels use native `<details>`/`<summary>`, so the page works without JavaScript, and the filter is a small Alpine component.
  - **Estimated HTML is ~400–500 KB uncompressed and ~60 KB gzipped**, which is acceptable.
  - If measurement shows the page is too heavy on mobile, the fallback is to lazy-load each group's families with Livewire when the group is opened. This is to be decided by measurement, not up front.

### 3.5 Manual and help (standing documentation rule)

- `resources/manual/manual_usuario.md`:
  - a new subsection «Mapa de la taxonomía» (`#mapa-taxonomia`) under `#taxonomia-cpv`;
  - one row in «Qué puede hacer en el sistema»;
  - one row in «Errores y dudas frecuentes»: «Veo una Familia "sin categorías todavía"» → it cannot be used yet; choose a related category or request a review.
  - The manual's index contract (18 sections) does not change, because the new section is level 3.
- The admin help is unchanged.

## 4. Tests and checks (when implemented)

**PHPUnit**, run in the isolated harness only, never against shared Supabase:
- Access: an affiliate (no roles) and an admin get 200, and a guest is redirected to login.
- The tree contains **only active** nodes. Inactive categories, and categories under an inactive family, are absent.
- The counts per group and per family match the fixture.
- An active family with no active category renders «sin categorías todavía» (D1), and so does a group whose families are all empty.
- No codes, ids, `branch`, `subbranch` or relevance fields appear in the HTML.
- No query writes. The page runs inside a transaction that is checked for zero modified rows, and the taxonomy tables keep identical row counts and fingerprints.
- The cache key changes when a fixture node's name or `is_active` changes.
- The manual: the new anchor exists, links to the page, and the index contract still holds.

**Browser check** (harness `--ux-script`): 3 widths × light/dark.
- no horizontal overflow, contrast, and keyboard access to the `<summary>` elements;
- the filter opens the matching branch;
- the empty label is visible;
- a guest is blocked.

**Staging smoke after an authorized deploy:** in-memory users, READ ONLY + ROLLBACK.
- the page returns 200 for the affiliate and the admin;
- the counts on the page equal live aggregate counts;
- request #1's fingerprint is unchanged and no mail is sent.

## 5. Risk, effort, deployment

- **Risk: low.** Read-only, with no schema change, no migration, and no change to search, ranking, taxonomy data or company data. The main residual risk is page weight on slow mobile connections, covered by the measurable fallback in §3.4.
- **Effort:** one normal round: a page, a view, a small tree builder, tests, the manual, and the browser check.
- **Deployment:** the change touches `app/**` and `tests/**`, so a fast-forward of the deploy branch triggers the normal workflow. No SSH route is needed. Staging deployment needs explicit authorization.

## 6. Open decisions for the orchestrator/owner

1. **Entry points:** keep the manual link (required) and add (b) the link from the «Categorías CPV» tab? **Recommended: yes.** Add (c) a sidebar item? **Recommended: no.**
2. **Order inside a level:** alphabetical, which is recommended and the easiest to scan, or code order, which follows the Chamber's numbering but is invisible to users because codes are hidden.
3. **Goods/services split on the cards:** show it (recommended, cheap and informative) or show only the totals.
4. **Descriptions:** none exist (§2). Should the Chamber be asked to supply short descriptions at least for the 48 groups? This is a separate content task, but it would make the map far more useful.
