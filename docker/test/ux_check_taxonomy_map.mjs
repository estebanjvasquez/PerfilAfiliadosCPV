// Browser check of CPV-TAXONOMY-MAP (Issue #2 comments 6191513374 and 6191485610), in the
// disposable stack only, against a SYNTHETIC production-size taxonomy seeded by
// ux_seed_taxonomy_map.php (48 groups, 333 families, 2,418 active categories).
//
// It measures, each with its value:
//   - access: guest sent to login; affiliate and admin can open the map; SPA navigation from the
//     manual's button reaches a working map (the filter component loads under wire:navigate);
//   - payload and cost at real size: HTML bytes, a gzip size COMPUTED from those bytes (staging's
//     transport compression is not measured here), DOM element count, navigation timings, time
//     until the filter is ready, and the filter's own cost per keystroke, also with 4x CPU
//     throttling at 390px (Chromium emulation, not a real phone);
//   - filter semantics: < 3 letters, group / family / category matches with their context,
//     highlighting, no results, Esc and the clear button;
//   - safety: a name with HTML characters stays text; no codes, inactive names or internal markers;
//   - layout: 3 widths x light/dark, no page-level horizontal overflow with a group open, text
//     contrast; keyboard opening of a group; the map without JavaScript;
//   - the link from the company's "Categorías CPV" tab (new tab, right URL).
//
// It is not an accessibility audit and does not replace a human looking.

import { chromium } from 'playwright';
import { writeFileSync, mkdirSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

const BASE = process.env.BASE_URL;
const AFFILIATE = process.env.UX_EMAIL;
const ADMIN = process.env.UX_ADMIN_EMAIL;
const PASSWORD = process.env.UX_PASSWORD;
const OUT = '/out';

if (!BASE || !AFFILIATE || !ADMIN || !PASSWORD) {
  console.error('BASE_URL, UX_EMAIL, UX_ADMIN_EMAIL and UX_PASSWORD are required');
  process.exit(2);
}
mkdirSync(OUT, { recursive: true });

const MAP = '/admin/mapa-de-la-taxonomia';
const MANUAL = '/admin/manual-de-usuario';
const FORBIDDEN = ['CPV-5', 'ux-synthetic', 'Inactiva ', 'ZZTEST', 'chamber_relevance', 'Stack trace', 'SQLSTATE'];

const results = [];
const metrics = {};
const record = (name, pass, detail) => {
  results.push({ name, pass: !!pass, detail });
  console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail !== undefined ? '  :: ' + detail : ''}`);
};

const browser = await chromium.launch({ args: ['--no-sandbox'] });

async function login(ctx, email) {
  const page = await ctx.newPage();
  await page.goto(BASE + '/admin/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[type="email"], input[id$="email"]', email);
  await page.fill('input[type="password"], input[id$="password"]', PASSWORD);
  await Promise.all([page.waitForLoadState('networkidle').catch(() => {}), page.click('button[type="submit"]')]);
  await page.waitForTimeout(1200);
  return page;
}

async function ready(page, timeout = 20000) {
  return page.waitForFunction(() => {
    const el = document.querySelector('.cpv-map');
    return !!(el && window.Alpine && window.Alpine.$data(el) && window.Alpine.$data(el).ready === true);
  }, null, { timeout }).then(() => true).catch(() => false);
}

// Visible counts after a filter (hidden attribute on the node itself or on an ancestor).
const state = (page) => page.evaluate(() => {
  const vis = (el) => !el.closest('[hidden]');
  const groups = [...document.querySelectorAll('.cpv-map__group')];
  return {
    groups: groups.length,
    visibleGroups: groups.filter(vis).length,
    openGroups: groups.filter((g) => vis(g) && g.open).length,
    visibleFamilies: [...document.querySelectorAll('.cpv-map__fam')].filter(vis).length,
    visibleCategories: [...document.querySelectorAll('.cpv-map__cat')].filter(vis).length,
    marks: [...document.querySelectorAll('.cpv-map mark')].map((m) => m.textContent),
    status: document.querySelector('#cpv-map-status')?.textContent.trim() || '',
    noResults: (() => { const el = [...document.querySelectorAll('.cpv-map__hint')].find((h) => h.textContent.includes('Ningún nombre del mapa')); return !!el && getComputedStyle(el).display !== 'none'; })(),
  };
});

async function type(page, text) {
  await page.fill('#cpv-map-filter', text);
  await page.waitForTimeout(400); // debounce is 150 ms
  return state(page);
}

function luminance([r, g, b]) {
  const f = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; };
  return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
}

async function contrast(page, selector) {
  const pair = await page.evaluate((sel) => {
    const el = document.querySelector(sel);
    if (!el) return null;
    const parse = (c) => (c.match(/[\d.]+/g) || []).map(Number);
    let bg = null;
    for (let n = el; n; n = n.parentElement) {
      const c = parse(getComputedStyle(n).backgroundColor);
      if (c.length >= 3 && (c.length === 3 || c[3] > 0.5)) { bg = c.slice(0, 3); break; }
    }
    return { fg: parse(getComputedStyle(el).color).slice(0, 3), bg: bg || [255, 255, 255] };
  }, selector);
  if (!pair) return null;
  const [a, b] = [luminance(pair.fg), luminance(pair.bg)].sort((x, y) => y - x);
  return Math.round(((a + 0.05) / (b + 0.05)) * 100) / 100;
}

try {
  // 1. GUEST
  {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();
    await page.goto(BASE + MAP, { waitUntil: 'domcontentloaded' });
    record('guest is sent to login from the map', /\/admin\/login/.test(page.url()), page.url().replace(BASE, ''));
    await ctx.close();
  }

  // 2. AFFILIATE, DESKTOP LIGHT: SPA entry, measurements, filter semantics, keyboard
  let storage = null;
  {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: 'light' });
    const page = await login(ctx, AFFILIATE);
    record('synthetic affiliate can log in', !/\/admin\/login/.test(page.url()));
    storage = await ctx.storageState();

    const sidebar = await page.evaluate(() => document.querySelector('.fi-sidebar')?.textContent || '');
    record('the map has no sidebar item', sidebar.includes('Manual de usuario') && !sidebar.includes('Mapa de la taxonomía'), `sidebar chars=${sidebar.length}`);

    await page.goto(BASE + MANUAL, { waitUntil: 'networkidle' });
    const button = page.getByRole('link', { name: 'Ver mapa de la taxonomía' }).first();
    record('the manual shows the "Ver mapa de la taxonomía" button', await button.isVisible().catch(() => false));
    await button.click();
    await page.waitForURL('**' + MAP, { timeout: 15000 }).catch(() => {});
    const spaReady = await ready(page);
    record('SPA navigation from the manual lands on a working map (filter loaded)', page.url().endsWith(MAP) && spaReady, page.url().replace(BASE, ''));
    const back = page.getByRole('link', { name: 'Volver al manual' }).first();
    record('the map has a "Volver al manual" link', (await back.getAttribute('href').catch(() => '') || '').includes(MANUAL));

    // Full load, measured.
    const t0 = Date.now();
    await page.goto(BASE + MAP, { waitUntil: 'load' });
    const tLoad = Date.now() - t0;
    const okReady = await ready(page);
    const tReady = Date.now() - t0;
    record('full load: the filter becomes ready', okReady, `wall ms to ready=${tReady}`);
    const nav = await page.evaluate(() => {
      const e = performance.getEntriesByType('navigation')[0];
      return {
        ttfb_ms: Math.round(e.responseStart - e.requestStart),
        response_end_ms: Math.round(e.responseEnd),
        dom_content_loaded_ms: Math.round(e.domContentLoadedEventEnd),
        load_ms: Math.round(e.loadEventEnd),
        decoded_body_bytes: e.decodedBodySize,
        transfer_bytes: e.transferSize,
        dom_elements: document.getElementsByTagName('*').length,
        groups: document.querySelectorAll('.cpv-map__group').length,
        families: document.querySelectorAll('.cpv-map__fam').length,
        categories: document.querySelectorAll('.cpv-map__cat').length,
        empty_labels: document.querySelectorAll('.cpv-map__empty').length,
        intro: document.querySelector('.cpv-map__intro p')?.textContent.replace(/\s+/g, ' ').trim(),
      };
    });
    const html = await (await page.request.get(BASE + MAP)).body();
    metrics.desktop = { ...nav, wall_ms_to_load_event: tLoad, wall_ms_to_filter_ready: tReady, html_bytes: html.length, html_gzip6_bytes_computed: gzipSync(html, { level: 6 }).length };
    record('payload measured', html.length > 0, `html=${html.length} B, gzip-6 computed=${metrics.desktop.html_gzip6_bytes_computed} B, DOM elements=${nav.dom_elements}`);
    record('production-size synthetic data is on the page', nav.groups >= 48 && nav.families >= 333 && nav.categories >= 2418,
      `groups=${nav.groups} families=${nav.families} categories=${nav.categories}`);
    // Digits only: the page writes 2.438 (thousands dot) and Node's 'es' locale writes 2438.
    const introDigits = nav.intro.replace(/(\d)\.(\d)/g, '$1$2');
    record('intro totals equal the rendered tree', introDigits.includes(`${nav.groups} Grupos`) && introDigits.includes(`${nav.families} Familias`) && introDigits.includes(`${nav.categories} Categorías`), nav.intro);
    record('empty groups/families keep their label', nav.empty_labels >= 56, `labels=${nav.empty_labels}`);
    const text = await page.evaluate(() => document.body.innerText);
    const leaked = FORBIDDEN.filter((f) => text.includes(f));
    record('no codes, inactive names or internal markers on the page', leaked.length === 0, leaked.join(', ') || 'none');
    await page.screenshot({ path: `${OUT}/map-01-desktop-light-initial.png` });

    // Filter semantics.
    let s = await type(page, 'zi');
    record('< 3 letters: guidance, nothing filtered', s.status.includes('al menos 3') && s.visibleGroups === s.groups, s.status);

    s = await type(page, 'zigurat');
    const famOfMatch = await page.evaluate(() => {
      const c = [...document.querySelectorAll('.cpv-map__cat')].find((n) => !n.closest('[hidden]'));
      const fam = c?.closest('.cpv-map__fam');
      return { open: !!fam?.querySelector('details')?.open, size: fam ? fam.querySelectorAll('.cpv-map__cat').length : 0 };
    });
    record('category match: one group, its family open, only the matching category, highlighted',
      s.visibleGroups === 1 && s.openGroups === 1 && s.visibleCategories === 1 && famOfMatch.open && s.marks.join('|').toLowerCase() === 'zigurat',
      `${s.status} | family size=${famOfMatch.size} | marks=${s.marks.join('|')}`);
    await page.screenshot({ path: `${OUT}/map-02-desktop-filter-category.png` });

    s = await type(page, 'quetzal');
    const quetzal = await page.evaluate(() => {
      const f = [...document.querySelectorAll('.cpv-map__fam')].find((n) => n.textContent.includes('Quetzal'));
      const cats = [...f.querySelectorAll('.cpv-map__cat')];
      return { open: !!f.querySelector('details')?.open, total: cats.length, visible: cats.filter((c) => !c.closest('[hidden]')).length };
    });
    record('family match: the family open with ALL its categories, group as context',
      s.visibleGroups === 1 && quetzal.open && quetzal.total > 0 && quetzal.visible === quetzal.total,
      `${s.status} | categories ${quetzal.visible}/${quetzal.total}`);

    s = await type(page, 'xilofono');
    const xil = await page.evaluate(() => {
      const g = [...document.querySelectorAll('.cpv-map__group')].find((n) => !n.hidden);
      const fams = [...g.querySelectorAll('.cpv-map__fam')];
      return { open: g.open, total: fams.length, visible: fams.filter((f) => !f.hidden).length };
    });
    record('group match (typed without accent): group open with ALL its families, name highlighted',
      s.visibleGroups === 1 && xil.open && xil.visible === xil.total && s.marks.includes('Xilófono'),
      `${s.status} | families ${xil.visible}/${xil.total} | marks=${s.marks.join('|')}`);
    await page.screenshot({ path: `${OUT}/map-03-desktop-filter-group.png` });

    s = await type(page, 'negrita');
    const esc = await page.evaluate(() => ({
      injected: document.querySelectorAll('.cpv-map b').length,
      shown: [...document.querySelectorAll('.cpv-map__cat')].filter((c) => !c.closest('[hidden]')).map((c) => c.querySelector('.cpv-map__n').textContent),
    }));
    record('a name with HTML characters stays text, also when highlighted', esc.injected === 0 && esc.shown.some((t) => t.includes('<b>negrita</b> & "comillas"')), JSON.stringify(esc.shown));

    s = await type(page, 'qqqzzzx');
    record('no results: message shown, nothing visible', s.noResults && s.visibleGroups === 0, s.status);

    await page.press('#cpv-map-filter', 'Escape');
    await page.waitForTimeout(300);
    s = await state(page);
    record('Esc clears: everything visible again, closed, no highlight', s.visibleGroups === s.groups && s.openGroups === 0 && s.marks.length === 0 && s.status === '', s.status);

    s = await type(page, 'valv');
    await page.click('text=Limpiar filtro');
    await page.waitForTimeout(300);
    s = await state(page);
    const focused = await page.evaluate(() => document.activeElement?.id);
    record('"Limpiar filtro" resets and returns focus to the field', s.visibleGroups === s.groups && s.marks.length === 0 && focused === 'cpv-map-filter', `focus=${focused}`);

    // Filter cost per keystroke (apply() plus forced layout), desktop, no throttling.
    metrics.desktop.filter_ms = await page.evaluate(() => {
      const c = window.Alpine.$data(document.querySelector('.cpv-map'));
      const out = {};
      for (const q of ['valv', 'sistemas', 'de alta', 'zigurat', 'qqqzzzx', '']) {
        c.query = q;
        const t = performance.now();
        c.apply();
        void document.body.offsetHeight;
        out[q || '(clear)'] = Math.round((performance.now() - t) * 10) / 10;
      }
      return out;
    });
    record('filter cost measured (desktop)', true, JSON.stringify(metrics.desktop.filter_ms));

    // Keyboard: from the field, Tab reaches a group summary; Enter opens it.
    await page.focus('#cpv-map-filter');
    let reached = false;
    for (let i = 0; i < 8 && !reached; i++) {
      await page.keyboard.press('Tab');
      reached = await page.evaluate(() => document.activeElement?.tagName === 'SUMMARY');
    }
    if (reached) await page.keyboard.press('Enter');
    const opened = await page.evaluate(() => document.activeElement?.parentElement?.open === true);
    record('keyboard: Tab reaches a group and Enter opens it', reached && opened);

    await ctx.close();
  }

  // 3. LAYOUT: 3 widths x light/dark, a group and a family open
  for (const [label, viewport] of [['desktop', { width: 1440, height: 900 }], ['tablet', { width: 820, height: 1180 }], ['mobile', { width: 390, height: 844 }]]) {
    for (const scheme of ['light', 'dark']) {
      const ctx = await browser.newContext({ viewport, colorScheme: scheme, storageState: storage });
      const page = await ctx.newPage();
      await page.goto(BASE + MAP, { waitUntil: 'networkidle' });
      await page.evaluate(() => window.Alpine?.store('sidebar')?.close?.());
      await page.evaluate((dark) => document.documentElement.classList.toggle('dark', dark), scheme === 'dark');
      const ok = await ready(page);
      await page.evaluate(() => {
        const g = document.querySelector('.cpv-map__group');
        g.open = true;
        const f = g.querySelector('.cpv-map__fam details');
        if (f) f.open = true;
      });
      await page.waitForTimeout(200);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      const ratio = await contrast(page, '.cpv-map__cat .cpv-map__n');
      record(`[${scheme}/${label}] ready, no horizontal overflow with a group open`, ok && overflow <= 1, `excess=${overflow}px`);
      record(`[${scheme}/${label}] category text contrast >= 4.5:1`, ratio !== null && ratio >= 4.5, `ratio=${ratio}`);
      await page.screenshot({ path: `${OUT}/map-${scheme}-${label}-${viewport.width}x${viewport.height}.png` });
      await ctx.close();
    }
  }

  // 4. MOBILE WITH 4x CPU THROTTLING (Chromium emulation, not a real device)
  {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, storageState: storage, isMobile: true, hasTouch: true });
    const page = await ctx.newPage();
    const cdp = await ctx.newCDPSession(page);
    await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
    const t0 = Date.now();
    await page.goto(BASE + MAP, { waitUntil: 'load' });
    const ok = await ready(page, 40000);
    const tReady = Date.now() - t0;
    const nav = await page.evaluate(() => {
      const e = performance.getEntriesByType('navigation')[0];
      return { dom_content_loaded_ms: Math.round(e.domContentLoadedEventEnd), load_ms: Math.round(e.loadEventEnd) };
    });
    const cost = await page.evaluate(() => {
      const c = window.Alpine.$data(document.querySelector('.cpv-map'));
      const out = {};
      for (const q of ['valv', 'sistemas', 'de alta', 'zigurat', '']) {
        c.query = q;
        const t = performance.now();
        c.apply();
        void document.body.offsetHeight;
        out[q || '(clear)'] = Math.round((performance.now() - t) * 10) / 10;
      }
      return out;
    });
    metrics.mobile_cpu4x = { ...nav, wall_ms_to_filter_ready: tReady, filter_ms: cost };
    record('mobile 390px, CPU 4x: map ready and filter cost measured', ok, JSON.stringify(metrics.mobile_cpu4x));
    await page.fill('#cpv-map-filter', 'zigurat');
    await page.waitForTimeout(800);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    record('mobile: filtered view has no horizontal overflow', overflow <= 1, `excess=${overflow}px`);
    await page.screenshot({ path: `${OUT}/map-04-mobile-filter.png` });
    await ctx.close();
  }

  // 5. WITHOUT JAVASCRIPT: native navigation still useful, the filter is not offered
  {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, storageState: storage, javaScriptEnabled: false });
    const page = await ctx.newPage();
    await page.goto(BASE + MAP, { waitUntil: 'load' });
    const groups = await page.locator('.cpv-map__group').count();
    const filterShown = await page.locator('#cpv-map-filter').isVisible().catch(() => false);
    await page.locator('.cpv-map__group > summary').first().click();
    const opened = await page.evaluate(() => document.querySelector('.cpv-map__group').open);
    // Playwright disables script EXECUTION but the HTML parser still runs with scripting on, so
    // <noscript> content is not rendered here even though a real no-JS browser shows it. What can
    // be checked is that the notice is in the page.
    const noscript = (await page.content()).includes('El filtro necesita JavaScript');
    record('without JavaScript: groups render and open natively; the filter is not offered', groups >= 48 && opened && !filterShown && noscript,
      `groups=${groups} opened=${opened} filterShown=${filterShown} noscript=${noscript}`);
    await page.screenshot({ path: `${OUT}/map-05-mobile-no-js.png` });
    await ctx.close();
  }

  // 6. THE COMPANY TAB LINK. As the synthetic Super Admin: the synthetic affiliate has no role, so
  // it cannot list companies in this stack (real affiliates get theirs through their role).
  {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await login(ctx, ADMIN);
    await page.goto(BASE + '/admin/empresas', { waitUntil: 'networkidle' });
    await page.locator('a[href*="/admin/empresas/"][href$="/edit"]').first().waitFor({ timeout: 20000 }).catch(() => {});
    const editHref = await page.locator('a[href*="/admin/empresas/"][href$="/edit"]').first().getAttribute('href').catch(() => null);
    if (editHref) {
      await page.goto(new URL(editHref, BASE).href, { waitUntil: 'networkidle' });
      await page.waitForTimeout(1000);
      const tab = page.getByRole('tab', { name: /Categorías CPV/ }).first();
      if (await tab.isVisible().catch(() => false)) {
        await tab.click();
        await page.waitForTimeout(2500);
      }
      const link = page.getByRole('link', { name: 'Ver mapa de la taxonomía' }).first();
      const href = await link.getAttribute('href').catch(() => null);
      const target = await link.getAttribute('target').catch(() => null);
      record('the "Categorías CPV" tab links to the map in a new tab', !!href && href.endsWith(MAP) && target === '_blank', `href=${href} target=${target}`);
      await page.screenshot({ path: `${OUT}/map-06-company-tab-link.png` });
    } else {
      const hrefs = await page.evaluate(() => [...document.querySelectorAll('a[href]')].map((a) => a.getAttribute('href')).filter((h) => h.includes('empresa')).slice(0, 12));
      const rows = await page.evaluate(() => document.querySelectorAll('.fi-ta-row').length);
      await page.screenshot({ path: `${OUT}/map-06-company-list-debug.png` });
      record('the "Categorías CPV" tab links to the map in a new tab', false, `no company edit link found for the synthetic admin; rows=${rows} hrefs=${JSON.stringify(hrefs)}`);
    }
    await ctx.close();
  }

  // 7. ADMIN
  {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await login(ctx, ADMIN);
    await page.goto(BASE + MAP, { waitUntil: 'networkidle' });
    record('the admin can open the map too', page.url().endsWith(MAP) && await ready(page));
    await ctx.close();
  }
} catch (e) {
  record('browser check crashed', false, String(e && e.stack || e));
} finally {
  await browser.close();
}

writeFileSync(`${OUT}/ux_results.json`, JSON.stringify({ results, metrics }, null, 2));
const failed = results.filter((r) => !r.pass).length;
console.log(`\n${results.length - failed}/${results.length} checks passed`);
console.log('METRICS ' + JSON.stringify(metrics));
process.exit(failed === 0 ? 0 : 1);
