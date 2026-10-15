// Browser check of CPV-HOME-USEFUL-HELP (Issue #2 comment 6195707471): the home dashboard no
// longer shows Filament's promotional card and offers «Ayuda para completar su perfil» with links to
// the user manual and the taxonomy map. Disposable stack only, synthetic users.
//
// Measures: guest sent to login; for the synthetic affiliate and Super Admin, at 1440 and 390 px in
// light and dark: the card is visible, both links work (real navigation), no Filament promotion,
// the account card and its «Salir» are still there, no page-level horizontal overflow, text
// contrast; keyboard: the links are reachable with Tab and open with Enter.

import { chromium } from 'playwright';
import { writeFileSync, mkdirSync } from 'node:fs';

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

const HOME = '/admin';
const results = [];
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
  {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();
    await page.goto(BASE + HOME, { waitUntil: 'domcontentloaded' });
    record('guest is sent to login from the dashboard', /\/admin\/login/.test(page.url()), page.url().replace(BASE, ''));
    await ctx.close();
  }

  for (const [who, email] of [['affiliate', AFFILIATE], ['admin', ADMIN]]) {
    for (const [label, viewport] of [['desktop', { width: 1440, height: 900 }], ['mobile', { width: 390, height: 844 }]]) {
      for (const scheme of ['light', 'dark']) {
        const tag = `[${who}/${scheme}/${label}]`;
        const ctx = await browser.newContext({ viewport, colorScheme: scheme });
        const page = await login(ctx, email);
        await page.goto(BASE + HOME, { waitUntil: 'networkidle' });
        await page.evaluate(() => window.Alpine?.store('sidebar')?.close?.());
        await page.evaluate((dark) => document.documentElement.classList.toggle('dark', dark), scheme === 'dark');
        await page.waitForTimeout(400);

        const card = page.locator('.cpv-profile-help-widget');
        const visible = await card.isVisible().catch(() => false);
        const text = await card.innerText().catch(() => '');
        const promo = await page.evaluate(() => !!document.querySelector('.fi-filament-info-widget') || /filamentphp\.com/.test(document.body.innerHTML));
        const account = await page.locator('.fi-account-widget').isVisible().catch(() => false);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        const ratio = await contrast(page, '.cpv-profile-help-widget p');
        record(`${tag} help card visible with both links`, visible && text.includes('Manual de usuario') && text.includes('Mapa de la taxonomía'), text.replace(/\s+/g, ' ').slice(0, 90));
        record(`${tag} no Filament promotional card`, !promo);
        record(`${tag} account card (Salir) still shown`, account);
        record(`${tag} no page-level horizontal overflow`, overflow <= 1, `excess=${overflow}px`);
        record(`${tag} card text contrast >= 4.5:1`, ratio !== null && ratio >= 4.5, `ratio=${ratio}`);
        await page.screenshot({ path: `${OUT}/home-${who}-${scheme}-${label}.png` });

        if (scheme === 'light' && label === 'desktop') {
          await card.getByRole('link', { name: 'Manual de usuario' }).click();
          await page.waitForURL('**/admin/manual-de-usuario', { timeout: 15000 }).catch(() => {});
          record(`${tag} "Manual de usuario" opens the manual`, page.url().endsWith('/admin/manual-de-usuario'), page.url().replace(BASE, ''));
          await page.goto(BASE + HOME, { waitUntil: 'networkidle' });
          await page.locator('.cpv-profile-help-widget').getByRole('link', { name: 'Mapa de la taxonomía' }).click();
          await page.waitForURL('**/admin/mapa-de-la-taxonomia', { timeout: 15000 }).catch(() => {});
          record(`${tag} "Mapa de la taxonomía" opens the map`, page.url().endsWith('/admin/mapa-de-la-taxonomia'), page.url().replace(BASE, ''));

          // Keyboard: Tab until a card link has the focus, Enter follows it.
          await page.goto(BASE + HOME, { waitUntil: 'networkidle' });
          let reached = false;
          for (let i = 0; i < 40 && !reached; i++) {
            await page.keyboard.press('Tab');
            reached = await page.evaluate(() => !!document.activeElement?.closest('.cpv-profile-help-widget') && document.activeElement.tagName === 'A');
          }
          if (reached) {
            await page.keyboard.press('Enter');
            await page.waitForTimeout(1500);
          }
          record(`${tag} keyboard: a card link is reachable with Tab and opens with Enter`, reached && /\/admin\/(manual-de-usuario|mapa-de-la-taxonomia)$/.test(page.url()), page.url().replace(BASE, ''));
        }
        await ctx.close();
      }
    }
  }
} catch (e) {
  record('browser check crashed', false, String(e && e.stack || e));
} finally {
  await browser.close();
}

writeFileSync(`${OUT}/ux_results.json`, JSON.stringify({ results }, null, 2));
const failed = results.filter((r) => !r.pass).length;
console.log(`\n${results.length - failed}/${results.length} checks passed`);
process.exit(failed === 0 ? 0 : 1);
