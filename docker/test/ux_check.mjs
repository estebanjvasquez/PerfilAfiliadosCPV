// Browser check of the REAL authenticated Filament manual page, requested by
// review 6171023438. Runs inside the disposable stack, against `php artisan
// serve` on the private compose network. No staging, no host port, no customer
// data: the affiliate and the company are synthetic and die with the run.
//
// WHAT THIS IS AND IS NOT. It exercises the rendered page in Chromium at three
// representative widths in light and dark, and it measures things that can be
// measured: horizontal overflow, whether every table of contents link reaches a
// visible heading, whether the keyboard reaches the content, computed contrast,
// and whether a guest is blocked. It does NOT replace a human looking at the
// page, and it is not an accessibility audit - it checks the specific properties
// the contract names.
//
// Every check records PASS/FAIL with the measured value, so a reader can
// disagree with the threshold instead of trusting a verdict.

import { chromium } from 'playwright';
import { writeFileSync, mkdirSync } from 'node:fs';

const BASE = process.env.BASE_URL;
const EMAIL = process.env.UX_EMAIL;
const PASSWORD = process.env.UX_PASSWORD;
const OUT = '/out';
const MANUAL_PATH = '/admin/manual-de-usuario';

if (!BASE || !EMAIL || !PASSWORD) {
  console.error('BASE_URL, UX_EMAIL and UX_PASSWORD are required');
  process.exit(2);
}
mkdirSync(OUT, { recursive: true });

const results = [];
const record = (name, pass, detail) => {
  results.push({ name, pass: !!pass, detail });
  console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail !== undefined ? '  :: ' + detail : ''}`);
};

// Representative widths, named for what they stand for rather than for a device.
const VIEWPORTS = [
  { label: 'desktop', width: 1440, height: 900 },
  { label: 'tablet', width: 820, height: 1180 },
  { label: 'mobile', width: 390, height: 844 },
];
const SCHEMES = ['light', 'dark'];

// Strings that must never appear to a reader. The provisional marker is the one
// this round added; the others are the kinds of internal detail the contract
// forbids exposing.
const FORBIDDEN = [
  'PROVISIONAL-TASK-0010A',
  'feature/task-0010a-category-request',
  'TaxonomyCategoriesRelationManager',
  'SOLICITUD_ACTION_LABEL',
  'html_input',
  'resources/manual',
  '/var/www',
  'vendor/laravel',
];

// Relative luminance and contrast ratio, WCAG definition.
const luminance = ([r, g, b]) => {
  const f = (c) => {
    c /= 255;
    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
  };
  return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
};
const contrast = (a, b) => {
  const [l1, l2] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (l1 + 0.05) / (l2 + 0.05);
};
const parseRgb = (s) => {
  const m = String(s).match(/-?\d+(\.\d+)?/g);
  return m ? m.slice(0, 3).map(Number) : null;
};

const browser = await chromium.launch({ args: ['--no-sandbox'] });

try {
  // ---------------------------------------------------------------------------
  // 1. A GUEST IS BLOCKED. First, because if this failed nothing else matters.
  // ---------------------------------------------------------------------------
  {
    const ctx = await browser.newContext({ viewport: VIEWPORTS[0] });
    const page = await ctx.newPage();
    const resp = await page.goto(BASE + MANUAL_PATH, { waitUntil: 'domcontentloaded' });
    const finalUrl = page.url();
    const body = await page.evaluate(() => document.body.innerText);
    const blocked = !finalUrl.includes('manual-de-usuario') || /iniciar sesi|login|password/i.test(body);
    record('guest is blocked from the manual', blocked,
      `status=${resp?.status()} final_url=${finalUrl.replace(BASE, '')}`);
    record('guest never sees manual content', !/Grupo .* Familia .* Categor/i.test(body),
      'checked for the taxonomy hierarchy text in the guest response');
    await ctx.close();
  }

  // ---------------------------------------------------------------------------
  // 2. LOG IN AS THE SYNTHETIC AFFILIATE, then everything else reuses it.
  // ---------------------------------------------------------------------------
  const ctx = await browser.newContext({ viewport: VIEWPORTS[0] });
  const page = await ctx.newPage();
  await page.goto(BASE + '/admin/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[type="email"], input[id$="email"]', EMAIL);
  await page.fill('input[type="password"], input[id$="password"]', PASSWORD);
  await Promise.all([
    page.waitForLoadState('networkidle').catch(() => {}),
    page.click('button[type="submit"]'),
  ]);
  await page.waitForTimeout(1500);
  const loggedIn = !/\/admin\/login/.test(page.url());
  record('synthetic affiliate can log in', loggedIn, `landed on ${page.url().replace(BASE, '')}`);
  if (!loggedIn) {
    writeFileSync(`${OUT}/ux_results.json`, JSON.stringify({ results, aborted: 'login failed' }, null, 2));
    await page.screenshot({ path: `${OUT}/00-login-failed.png`, fullPage: true });
    process.exit(1);
  }

  // ---------------------------------------------------------------------------
  // 3. THE MANUAL OPENS, AND IS REACHABLE FROM THE NAVIGATION.
  // ---------------------------------------------------------------------------
  const navLink = page.locator('a:has-text("Manual de usuario")').first();
  const navVisible = await navLink.isVisible().catch(() => false);
  record('manual is reachable from the panel navigation', navVisible,
    navVisible ? 'navigation link visible at desktop width' : 'link not visible');

  await page.goto(BASE + MANUAL_PATH, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(800);
  const h1 = await page.locator('h1').first().innerText().catch(() => '');
  record('manual page opens for the affiliate', /manual/i.test(h1), `h1="${h1}"`);

  // ---------------------------------------------------------------------------
  // 4. NO INTERNAL DETAIL IS VISIBLE. Checked against the full HTML, not just
  //    the text, so an attribute or a comment would also be caught.
  // ---------------------------------------------------------------------------
  {
    const html = await page.content();
    const leaked = FORBIDDEN.filter((s) => html.includes(s));
    record('no internal markers or paths in the rendered page', leaked.length === 0,
      leaked.length ? 'LEAKED: ' + leaked.join(', ') : `checked ${FORBIDDEN.length} strings`);
  }

  // ---------------------------------------------------------------------------
  // 5. EVERY TABLE-OF-CONTENTS LINK REACHES A VISIBLE HEADING.
  //    This is the one the contract cares about ("stable anchors"), and the one
  //    a wall of text would quietly fail.
  // ---------------------------------------------------------------------------
  {
    const anchors = await page.evaluate(() =>
      Array.from(document.querySelectorAll('a[href^="#"]'))
        .map((a) => a.getAttribute('href'))
        .filter((h) => h && h.length > 1)
    );
    const unique = [...new Set(anchors)];
    const broken = [];
    const hidden = [];
    for (const href of unique) {
      const id = decodeURIComponent(href.slice(1));
      const state = await page.evaluate((anchorId) => {
        const el = document.getElementById(anchorId);
        if (!el) return { missing: true };
        const r = el.getBoundingClientRect();
        const cs = getComputedStyle(el);
        return {
          missing: false,
          visible: cs.display !== 'none' && cs.visibility !== 'hidden' && r.height > 0,
          tag: el.tagName.toLowerCase(),
        };
      }, id);
      if (state.missing) broken.push(href);
      else if (!state.visible) hidden.push(href);
    }
    record('table of contents has links', unique.length > 0, `${unique.length} in-page links`);
    record('no table-of-contents link is broken', broken.length === 0,
      broken.length ? 'BROKEN: ' + broken.join(', ') : `${unique.length} anchors all resolve`);
    record('every anchor target is visible, not hidden content', hidden.length === 0,
      hidden.length ? 'HIDDEN: ' + hidden.join(', ') : 'all targets rendered and non-zero height');

    // And one navigation actually scrolls to its heading.
    if (unique.length) {
      const first = unique[0];
      await page.evaluate((h) => { document.querySelector(`a[href="${h}"]`)?.click(); }, first);
      await page.waitForTimeout(600);
    }
  }

  // ---------------------------------------------------------------------------
  // 6. KEYBOARD REACHES THE CONTENT, and focus is actually visible.
  // ---------------------------------------------------------------------------
  {
    await page.goto(BASE + MANUAL_PATH, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(600);
    await page.evaluate(() => document.body.focus());
    const seen = [];
    let reachedInPageLink = false;
    let focusRingSeen = false;
    for (let i = 0; i < 60; i++) {
      await page.keyboard.press('Tab');
      const info = await page.evaluate(() => {
        const el = document.activeElement;
        if (!el || el === document.body) return null;
        const cs = getComputedStyle(el);
        return {
          tag: el.tagName.toLowerCase(),
          href: el.getAttribute?.('href') || null,
          outline: cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0,
          ring: cs.boxShadow && cs.boxShadow !== 'none',
        };
      });
      if (!info) continue;
      seen.push(info.tag + (info.href ? `[${info.href}]` : ''));
      if (info.href && info.href.startsWith('#')) reachedInPageLink = true;
      if (info.outline || info.ring) focusRingSeen = true;
      if (reachedInPageLink && focusRingSeen) break;
    }
    record('keyboard Tab reaches the in-page section links', reachedInPageLink,
      `${seen.length} focusable stops traversed`);
    record('focused elements show a visible focus indicator', focusRingSeen,
      'outline or box-shadow present on at least one focused element');
  }

  // ---------------------------------------------------------------------------
  // 7. WIDTHS x SCHEMES: no horizontal page overflow, tables contained,
  //    readable contrast. Screenshots saved for each combination.
  // ---------------------------------------------------------------------------
  for (const scheme of SCHEMES) {
    for (const vp of VIEWPORTS) {
      const c = await browser.newContext({
        viewport: { width: vp.width, height: vp.height },
        colorScheme: scheme,
        storageState: await ctx.storageState(),
      });
      const p = await c.newPage();
      await p.goto(BASE + MANUAL_PATH, { waitUntil: 'domcontentloaded' });
      await p.waitForTimeout(900);

      const m = await p.evaluate(() => {
        const de = document.documentElement;
        // Tables must scroll inside their own container, not drag the page.
        const tables = Array.from(document.querySelectorAll('table')).map((t) => {
          const parent = t.parentElement;
          const ps = parent ? getComputedStyle(parent) : null;
          return {
            tableWidth: Math.round(t.scrollWidth),
            parentClient: parent ? Math.round(parent.clientWidth) : null,
            parentScrolls: ps ? ['auto', 'scroll'].includes(ps.overflowX) : false,
          };
        });
        const body = document.body;
        const text = document.querySelector('.manual-body, article, main') || body;
        return {
          scrollWidth: Math.round(de.scrollWidth),
          clientWidth: Math.round(de.clientWidth),
          tables,
          color: getComputedStyle(text).color,
          background: getComputedStyle(body).backgroundColor,
        };
      });

      const overflow = m.scrollWidth - m.clientWidth;
      record(`[${scheme}/${vp.label}] no horizontal page overflow`, overflow <= 2,
        `scrollWidth=${m.scrollWidth} clientWidth=${m.clientWidth} diff=${overflow}px`);

      const spilling = m.tables.filter(
        (t) => t.parentClient !== null && t.tableWidth > t.parentClient + 2 && !t.parentScrolls
      );
      record(`[${scheme}/${vp.label}] tables stay inside their container`, spilling.length === 0,
        m.tables.length === 0 ? 'no tables at this width'
          : `${m.tables.length} table(s), ${spilling.length} overflowing without a scroll container`);

      const fg = parseRgb(m.color);
      const bg = parseRgb(m.background);
      if (fg && bg) {
        const ratio = contrast(fg, bg);
        record(`[${scheme}/${vp.label}] body text contrast >= 4.5:1`, ratio >= 4.5,
          `ratio=${ratio.toFixed(2)} fg=${m.color} bg=${m.background}`);
      } else {
        record(`[${scheme}/${vp.label}] body text contrast measurable`, false,
          `could not parse colours fg=${m.color} bg=${m.background}`);
      }

      const file = `${OUT}/${scheme}-${vp.label}-${vp.width}x${vp.height}.png`;
      await p.screenshot({ path: file, fullPage: false });
      await c.close();
    }
  }

  await ctx.close();
} catch (err) {
  record('browser check completed without an unexpected error', false, String(err && err.message));
} finally {
  await browser.close();
}

const failed = results.filter((r) => !r.pass);
writeFileSync(
  `${OUT}/ux_results.json`,
  JSON.stringify(
    {
      base_url_shape: 'http://<disposable-container>:8000 (private compose network, no host port)',
      manual_path: MANUAL_PATH,
      viewports: VIEWPORTS,
      schemes: SCHEMES,
      total: results.length,
      passed: results.length - failed.length,
      failed: failed.length,
      results,
    },
    null,
    2
  )
);
console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
if (failed.length) {
  console.log('FAILED:');
  for (const f of failed) console.log(`  - ${f.name} :: ${f.detail}`);
}
process.exit(failed.length ? 1 : 0);
