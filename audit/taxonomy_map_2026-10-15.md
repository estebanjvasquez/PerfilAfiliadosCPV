# CPV-TAXONOMY-MAP: «Mapa de la taxonomía» (v1)

- **Instruction:** Issue #2 `6191513374` (owner authorization to develop; deployment NOT authorized), with every correction A–F and design decision in `6191485610`.
  - Proposal, updated in this round to reflect the corrections: `docs/propuestas/mapa_taxonomia.md`.
- **Branch:** `feature/taxonomy-map`.
- **BASE_SHA:** `f4eb859ab02ecaf10963da0ce8052e22e96dee0f`. It equals the deployed runtime `37e3b7186bd7babef07392c09f80d134db4833f8` plus `docs/**` and `audit/**` only.
  - Verified before branching: the deploy branch and the VPS checkout were both at `37e3b71`, with 0 tracked modifications; the local worktree was clean; no other delivery was pending on this baseline.
- **TESTED_HEAD:** `0fb38e2050ce24792dde36132c90f666c71157cf` (code).
- **REVIEW_HEAD:** the commit that adds this report. On top of TESTED_HEAD it changes only `docs/**` and `audit/**`.
- **Deployed:** **YES**, on 2026-10-15 at 14:43Z, at REVIEW_HEAD `9e7e379` through the normal workflow (§9).
  - Accepted in `6194751263`; the deployment was authorized by the owner in chat.
- **Mail:** PENDING_OWNER_CONFIGURATION, unchanged and untouched.

## 1. What was built

| File | What |
|---|---|
| `app/Support/Taxonomy/TaxonomyMapBuilder.php` | Builds the published tree: one SELECT, publication and ancestor rules, Spanish ordering, counts. Returns names, offer type and counts only. |
| `app/Filament/Pages/TaxonomyMapPage.php` | The panel page `/admin/mapa-de-la-taxonomia`. Access delegated to `UserManual::canAccess()`. No sidebar item. «Volver al manual». |
| `resources/views/filament/pages/taxonomy-map.blade.php` | Intro, filter, group cards and native `<details>` navigation. Compact markup for the repeated nodes. |
| `resources/js/components/taxonomy-map.js` | Alpine filter, loaded on demand by Filament (`AlpineComponent` + `x-load`), so it is SPA-safe. Writes text nodes only, and writes to the DOM only on state changes. |
| `app/Providers/Filament/AdminPanelProvider.php` | Registers the component. The app asset version also follows the JS file's mtime, so editing it changes the URL. |
| `app/Filament/Pages/UserManual.php` | «Ver mapa de la taxonomía» header button. |
| `app/Filament/Resources/EmpresaResource/RelationManagers/TaxonomyCategoriesRelationManager.php` | «Ver mapa de la taxonomía» link in the tab header. It opens in a new tab, navigates only, and is visible only when the user can open the map. |
| `resources/manual/manual_usuario.md` | New `#mapa-taxonomia` subsection (level 3), one row in «Qué puede hacer en el sistema», two FAQ rows. **Additions only** (+29, −0): the 2022 PDF coverage (§2 of `audit/mail_docs_user_manual_2026-10-15.md`) is unchanged. |
| `tests/Feature/Filament/TaxonomyMapPageTest.php` | 11 tests (§4). |
| `tests/Feature/Filament/UserManualContentTest.php` | The new anchor added to the stable anchors. The 18-section index contract is unchanged. |

No migration, and no change to the taxonomy, search, ranking, company data, mail, `EmpresaPgsql` or any secret.

## 2. Implementation decisions (mapped to `6191485610`)

- **Decision 1, entry points:** the manual button plus the company-tab link (new tab), no sidebar item, and «Volver al manual» on the map.
- **Decision 2, ordering:**
  - It uses `Collator('es')` at PRIMARY strength, so it ignores case and accents and sorts «ñ» after «n».
  - Ties are broken by the exact text, then the internal id, which is never output.
  - There is a fallback without ext-intl; the container has intl.
- **Decision 3, counts:**
  - Families, categories and goods/services per card come from the same visible tree.
  - Any other or missing offer type is counted as «sin tipo» (`otro`). It is never folded into goods or services.
- **Decision 4, descriptions:** none are invented. They are recorded as a future Chamber content request (short descriptions for the 48 groups). Nothing was sent to anyone.
- **D1:** published empty families and groups show «sin categorías todavía».
  - An empty family is rendered as a non-openable row.
  - The intro and the manual say that there is nothing to choose there, and that companies are never linked to a whole Group or Family.
- **D2:** no clustering.
- **A, cache:** no cache in v1. The tree is built per request and measured at **15–28 ms** at production size in the isolated stack (seed log `build_ms`). The `count + max(updated_at)` idea was withdrawn, and no revision mechanism was added.
- **B, filter:**
  - Match versus context, as specified: a matching Group keeps all its families; a matching Family keeps all its categories; a matching Category keeps its path, and its family's non-matching categories are hidden.
  - Fewer than 3 characters: guidance, and the map stays complete. No results: guidance. Clear: «Limpiar filtro» or Esc.
  - The page says it is name filtering, not synonym or semantic search.
  - Escaping: Blade escapes the names, and the JS rewrites only text nodes.
- **C, access:**
  - Identical to the manual, through the same panel middleware including email verification.
  - The tree is not in Livewire state and there is no endpoint.
  - The tab link does not broaden company permissions.
- **D, performance:** measured at real size, with only the simplest justified changes (§5). Group lazy-loading was not needed.
- **E, data rules:**
  - Published means `is_active` (the Chamber's «Activa» switch).
  - Each node needs a visible, well-formed ancestor chain. Inactive or hidden ancestors, level/parent mismatches and orphans are excluded and never promoted.
- **F, nothing unrelated:**
  - The map was built without `Empresa`/`EmpresaPgsql` and reads no company data.
  - Two pre-existing defects met during the browser check were worked around **in the synthetic test seed only**, and are reported in §7 rather than fixed.

## 3. Live data under the map's rules (staging, read-only)

The map's rules were run as aggregate SQL (`docs/deploy/taxonomy_map_rules_readonly.php`), inside a READ ONLY transaction (`transaction_read_only=on`) followed by ROLLBACK. Counts only: no names, no codes, no company data. Result: `audit/runs/taxonomy_map/live_staging_map_rules_aggregate.json`.

| | Value |
|---|---|
| Visible groups / families / categories | **48 / 333 / 2,418** |
| Goods / services / «sin tipo» | 1,572 / 846 / 0 |
| Empty families / empty groups (shown with the D1 label) | 55 / 1 |
| Active nodes excluded by the ancestor or shape rules | 0 (none under an inactive parent, no malformed or orphan rows) |
| Active nodes without a Spanish name / without any name | 0 / 0 |
| Categories the current search can reach but the map would not show | 0 |

## 4. Tests

**Focused PHPUnit** (isolated runs during development; filter `TaxonomyMap|UserManual|TaxonomyCategoryRequestTest`), with **40 tests**:
- run `20261015T121011Z` at `87a4a0b`: OK, 455 assertions;
- run `20261015T133014Z` at `6081a1e`: OK, 456 assertions.

The map's 11 tests:
1. affiliate and admin 200, guest redirected to login;
2. an unverified user gets the manual's exact response (status and Location), and `canAccess()` equals the manual's;
3. no sidebar item; the manual's button and the map's return link;
4. the company tab: `verMapaTaxonomia` exists and is visible, has the label, the URL and opens in a new tab; the existing actions are intact; the company links are unchanged;
5. only published nodes with a visible, well-formed ancestor chain; malformed rows are not promoted;
6. counts and the goods/services/«otro» split; totals are the exact sum of the tree;
7. D1 labels for an empty family and an empty group; an empty family is not openable;
8. Spanish ordering at every level (árbol, Avión, bomba, nube, Ñandú, zeta), with a deterministic tie-break, and a stable rebuild;
9. no codes, `branch`/`subbranch`, relevance basis, inactive or malformed names in the HTML; no tree in the Livewire snapshot;
10. an `<img onerror>` name is escaped;
11. while the page renders, every SQL statement on every configured connection is observed: none writes, no taxonomy cache key is written, and the taxonomy and company-link tables keep their row counts.

**Full isolated regression at TESTED_HEAD `0fb38e2`:** run `20261015T133707Z-0fb38e2050ce` (harness `67e1f6b`).
- **OK (492 tests, 2,979 assertions)**.
- Isolation 19/19, schema 14/14, fixtures 8/8, governed taxonomy tables unchanged by the suite.
- Staging 200 before and after; no leftover containers.
- The only "finding" is informational ("0 migration ordering violations"), the same as the accepted runs.
- Evidence: `audit/runs/taxonomy_map/full_0fb38e2/`.

## 5. Browser check and measurements

**Final run:** `20261015T140345Z-0fb38e2050ce` at TESTED_HEAD (harness `3dff19a`, `--skip-suite`, since the code is the one fully tested above). Result: **39/39**. Evidence, including screenshots: `audit/runs/taxonomy_map/ux_0fb38e2/`.

- **Fixture size:** a synthetic tree seeded by `docker/test/ux_seed_taxonomy_map.php`, sized from the live aggregate shape:
  - 48 groups, 333 families (3–17 per group), 2,418 active categories (at most 44 per family), 698 inactive, 55 empty families, 1 empty group, about 65/35 goods/services, 3 without type;
  - plus the harness fixture's 2 groups, 4 families and 20 categories, which have no names and appear as «(sin nombre)».
  - The page therefore rendered **50 / 337 / 2,438**.
  - The names are generated, not real.
  - The seed refuses to run outside the runner's `testdb`/`cpv_test`. The harness counts its 3,497 rows apart and does not treat them as escaped writes.

**What passed:**
- guest blocked; affiliate and admin can open the map;
- no sidebar item; the manual's button, and **SPA navigation** from it lands on a working filter; the return link;
- intro totals equal the rendered tree; 56 empty labels; no codes, inactive names or internal markers in the text;
- the filter:
  - fewer than 3 letters: guidance;
  - «zigurat»: one category, highlighted, with its group and family open;
  - «quetzal»: the family open with all its categories;
  - «xilofono», typed without the accent: the group open with all 7 families;
  - an HTML-character name stays text;
  - no results;
  - Esc; «Limpiar filtro», which returns focus;
- keyboard: Tab reaches a group and Enter opens it;
- 3 widths × light/dark with a group open: 0 px overflow, contrast 14.89 (light) and 13.96 (dark);
- 390 px with 4x CPU: ready, and no overflow when filtered;
- no JavaScript: 50 groups render and open natively, the filter is not offered, and the `<noscript>` notice is in the page;
- the company tab link: `…/admin/mapa-de-la-taxonomia`, `target=_blank`.

**Measurements** (all measured in the isolated stack unless labelled):

| | First build `87a4a0b` | Final `0fb38e2` |
|---|---|---|
| HTML (measured bytes) | 1,484,685 | **591,529** |
| gzip -6 of that HTML (**computed** with zlib; staging's transport compression **not measured**) | 60,031 | **41,445** |
| DOM elements | 9,683 | 9,683 |
| Desktop: TTFB / DOMContentLoaded / load / filter ready (ms) | 185 / 314 / 319 / 429 | 130 / 229 / 233 / 336 |
| Desktop: filter cost per application, apply() + forced layout (ms) | 12–63 | **4–27** |
| 390 px, CPU 4x (**Chromium emulation**, not a device): DOMContentLoaded / filter ready (ms) | 998 / 1,808 | 1,054 / 1,679 |
| 390 px, CPU 4x: filter cost (ms) | 89–304 | **30–158** (final run) |

Across the five browser runs at the final SHA, the throttled values varied: «valv» 148–214 ms, «de alta» 47–224 ms, «zigurat» 17–38 ms.

The first build exceeded the proposal's estimate ("~400–500 KB"), which was wrong. Three changes followed:
- compact markup for the repeated nodes, with no template indentation;
- DOM writes only on state change, skipping hidden subtrees;
- `content-visibility: auto` on groups.

Lazy-loading per group was not needed.

## 6. Reproduction

```bash
# isolated runner (see docs/orquestador/PROJECT_KNOWLEDGE.md for the host; the key is executor-specific)
cd /root/cpv-runner && git fetch -q origin infra/contabo-test-runner && git checkout -q FETCH_HEAD
export CPV_TEST_ROOT=<CPV_TEST_ROOT>
./docker/test/run_tests.sh 0fb38e2050ce24792dde36132c90f666c71157cf                       # full regression
./docker/test/run_tests.sh 0fb38e2050ce24792dde36132c90f666c71157cf --skip-suite \
    --ux-script ux_check_taxonomy_map.mjs --ux-seed ux_seed_taxonomy_map.php              # browser + measurements
./docker/test/run_tests.sh <sha> --filter 'TaxonomyMap|UserManual|TaxonomyCategoryRequestTest'  # focused
# live aggregate check (read-only, from the deploy directory on the VPS)
docker compose exec -T -u www-data app php < docs/deploy/taxonomy_map_rules_readonly.php
```

**Harness changes** (`infra/contabo-test-runner`, `246b0d0..3dff19a`):
- a new `--ux-seed <file.php>` option;
- the governed-tables check leaves out `ux-synthetic-*` seed rows and counts them separately;
- new `ux_seed_taxonomy_map.php` and `ux_check_taxonomy_map.mjs`.

No existing check was weakened. The leak check still compares every non-seed row.

## 7. Findings (not fixed; outside this round)

1. **`Empresa::users()` dereferences `Auth::User()->id`** (`app/Models/Empresa.php:162`). Any use of the relation without a logged-in user fails, including in jobs and scripts. The harness already works around a related hook.
2. **`EditEmpresa::getSteps()` uses the last row of a loop over `cities` as `$value`** (`EditEmpresa.php:104-160`). With an empty `cities` table the company page throws "Undefined variable $value". Production has cities; the synthetic stack needed one.
3. **The add-categories search filters `is_active` and the level, but not the ancestors' state.** A category under an inactive family would be searchable but absent from the map. Latent only: **0 such categories on staging today** (§3).
4. **In the harness, Laravel's handler renders an uncaught error in a `php` stdin script and exits 0.** Found with the seed, which now fails explicitly.
5. Earlier findings remain:
   - `EmpresaPgsql` (dormant fatal);
   - the "COVID." label and unlabelled ISO checkboxes;
   - the third-party `app/images/user.pdf`;
   - the claim duplicate risk;
   - simulated notices.

## 8. Limitations

- **Mobile layout:** at 390 px the intro and the filter take the first screen, so filter results start below the fold. The status line («1 coincidencia…») is visible right under the field. A future option is to collapse the intro on small screens. Not done in v1.
- **Performance:** measured in Chromium with CPU throttling, not on a real phone, and not over staging's network or Cloudflare compression. The gzip figure is computed from the HTML.
- **No-JS evidence:** Playwright disables script execution but parses with scripting on, so the `<noscript>` notice could be checked for presence only, not for visibility.
- **«(sin nombre)» fallback:** exercised only by the harness fixture. Staging has no active node without a name (§3).
- **Not covered:** a screen-reader audit, and real users.

## 9. Staging deployment (2026-10-15)

- **Acceptance:** `6194751263` (PASS; deployment reserved for the owner).
- **Authorization:** the owner, **in chat** in the Claude Code session ("Está autorizado el despliegue"). It is recorded in Issue #2 with that provenance.
- **Preconditions (all passed):**
  - The deploy branch and the runtime were both at `37e3b71`, with 0 tracked modifications.
  - `9e7e379` is a fast-forward of it. The runtime diff is only the accepted files: `app/**`, `resources/**`, `tests/**` and the manual.
  - 0 migrations in the diff and 0 pending on staging; no concurrent deploy.
  - Recovery image: `perfilafiliados-app:pre-taxmap-37e3b71`.
- **Route:** fast-forward `37e3b71..9e7e379` (no force), then workflow run [`38753813135`](https://github.com/estebanjvasquez/PerfilAfiliadosCPV/actions/runs/38753813135), **success**.
- **Post-state:**
  - the checkout is at `9e7e379` with 0 tracked modifications;
  - `app` was recreated at 14:43:16Z;
  - maintenance is off; `/` and `/admin/login` return 200;
  - 0 pending migrations, 0 new `ERROR` entries in the log, and the `.env` hash is unchanged.
- **Smoke** (`docs/deploy/smoke_taxonomy_map_readonly.php`, in-memory users, READ ONLY + ROLLBACK): **20/20**.
  - Affiliate and admin both get 200.
  - The intro totals equal the pre-deploy aggregate: **48 Grupos, 333 Familias, 2.418 Categorías**.
  - 57 «sin categorías todavía» labels (55 families + 1 group + the intro mention).
  - No codes or internal fields; the return link is present; the filter is on the page.
  - The manual offers «Ver mapa de la taxonomía» and has `#mapa-taxonomia`.
  - The filter component is published (`/js/app/components/taxonomy-map.js`, 8,236 B) and **served by nginx: 200, application/javascript**.
  - No mail was sent.
- Guests are redirected (`302 → /admin/login`) on the map and on the manual.
- **Preserved:**
  - mail is `log` and simulated (unchanged);
  - the 0010C smoke is **18/18** before and after, with request #1's fingerprint `2e64f23555b01ad30f845bdd53cedc15`;
  - the published taxonomy aggregate is **identical before and after**.
- **Not checked on staging, left to the owner's UAT:**
  - **The company-tab link** needs a real company page, and the smoke does not read real companies. It was verified in the isolated browser check and in PHPUnit.
  - **The filter's in-browser behaviour**: there is no live browser run. The asset is served, and its behaviour was verified 39/39 in isolation.
- Evidence: `audit/runs/taxonomy_map_deploy_2026-10-15/`.
