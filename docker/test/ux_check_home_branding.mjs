// Browser check of CPV-HOME-BRANDING-AND-LAYOUT (Issue #2 comment 6210321522): the home page is
// called «Inicio», its visible heading is the existing Perfil de Afiliados logo, and the cards are
// arranged account -> help -> notices. Disposable stack only, synthetic users.
//
// Measures, for the synthetic affiliate and Super Admin, at 1440, 820 and 390 px in light and dark:
// the <h1> holds the logo (loaded from images/Perfil-Afiliados.png at 400x206, rendered with the same
// aspect ratio and a restrained height), it sits on a light surface in both schemes, no visible
// «Escritorio», the browser title and the sidebar item say «Inicio», card geometry (side by side on
// desktop, stacked in order below 1024 px, notices full width below), notice text at reading size,
// no page-level horizontal overflow. Desktop light also follows both help links, walks the keyboard
// order and logs out with «Salir». Screenshots are full page.

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

const box = (page, sel) => page.evaluate((s) => {
  const el = document.querySelector(s);
  if (!el) return null;
  const r = el.getBoundingClientRect();
  return { x: Math.round(r.left), y: Math.round(r.top + window.scrollY), w: Math.round(r.width), h: Math.round(r.height) };
}, sel);

try {
  {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();
    await page.goto(BASE + HOME, { waitUntil: 'domcontentloaded' });
    record('guest is sent to login from the home URL', /\/admin\/login/.test(page.url()), page.url().replace(BASE, ''));
    await ctx.close();
  }

  for (const [who, email] of [['affiliate', AFFILIATE], ['admin', ADMIN]]) {
    for (const [label, viewport] of [['desktop', { width: 1440, height: 900 }], ['tablet', { width: 820, height: 1180 }], ['mobile', { width: 390, height: 844 }]]) {
      for (const scheme of ['light', 'dark']) {
        const tag = `[${who}/${scheme}/${label}]`;
        const ctx = await browser.newContext({ viewport, colorScheme: scheme });
        const page = await login(ctx, email);
        await page.goto(BASE + HOME, { waitUntil: 'networkidle' });
        await page.evaluate((dark) => document.documentElement.classList.toggle('dark', dark), scheme === 'dark');
        // Below lg the sidebar is an overlay; close it so the screenshot shows the page.
        if (label !== 'desktop') await page.evaluate(() => window.Alpine?.store('sidebar')?.close?.());
        await page.waitForTimeout(400);

        // Logo as the page heading.
        const logo = await page.evaluate(() => {
          const h1 = document.querySelector('h1.fi-header-heading');
          const img = h1?.querySelector('img');
          if (!img) return null;
          const r = img.getBoundingClientRect();
          let bg = null;
          for (let n = img; n; n = n.parentElement) {
            const c = (getComputedStyle(n).backgroundColor.match(/[\d.]+/g) || []).map(Number);
            if (c.length >= 3 && (c.length === 3 || c[3] > 0.5)) { bg = c.slice(0, 3); break; }
          }
          return {
            src: new URL(img.src).pathname, alt: img.alt, complete: img.complete,
            natural: [img.naturalWidth, img.naturalHeight], rendered: [Math.round(r.width), Math.round(r.height)],
            bg, h1Name: h1.textContent.trim() === '' ? img.alt : h1.textContent.trim(),
            h1Count: document.querySelectorAll('h1').length,
          };
        });
        const ratio = logo ? logo.rendered[0] / logo.rendered[1] : 0;
        const surface = logo?.bg ? (0.2126 * logo.bg[0] + 0.7152 * logo.bg[1] + 0.0722 * logo.bg[2]) / 255 : 0;
        record(`${tag} h1 is the logo with alt «Perfil de Afiliados»`, logo && logo.alt === 'Perfil de Afiliados' && logo.h1Count === 1, logo && `${logo.src} alt=${logo.alt} h1s=${logo.h1Count}`);
        record(`${tag} logo loads at 400x206 from the existing asset`, logo && logo.complete && logo.natural.join('x') === '400x206' && logo.src.endsWith('/images/Perfil-Afiliados.png'), logo && logo.natural.join('x'));
        record(`${tag} logo keeps its aspect ratio at a restrained size`, Math.abs(ratio - 400 / 206) < 0.03 && logo.rendered[1] >= 48 && logo.rendered[1] <= 80, logo && `${logo.rendered.join('x')} ratio=${ratio.toFixed(3)}`);
        record(`${tag} logo sits on a light surface`, surface > 0.9, logo && `bg=${JSON.stringify(logo.bg)}`);

        // Naming.
        const bodyText = await page.evaluate(() => document.body.innerText);
        const title = await page.title();
        record(`${tag} no visible «Escritorio»`, !/Escritorio/.test(bodyText));
        record(`${tag} browser title says Inicio`, /^Inicio\b/.test(title), title);
        if (label === 'desktop') {
          const nav = await page.locator('.fi-sidebar-item-active').innerText().catch(() => '');
          record(`${tag} active sidebar item is «Inicio»`, nav.trim() === 'Inicio', nav.trim());
        }

        // Card geometry.
        const acc = await box(page, '.fi-account-widget');
        const help = await box(page, '.cpv-profile-help-widget');
        const notices = await box(page, '.fi-wi-stats-overview');
        const n1 = await box(page, '.cpv-home-notice');
        const ok = acc && help && notices && n1;
        if (label === 'desktop') {
          record(`${tag} account and help side by side, account first`, ok && Math.abs(acc.y - help.y) <= 2 && acc.x < help.x && help.w > acc.w * 1.5, ok && `account ${acc.w}px @${acc.x}, help ${help.w}px @${help.x}`);
        } else {
          record(`${tag} account, help, notices stacked in order`, ok && acc.y + acc.h <= help.y && help.y + help.h <= notices.y && Math.abs(acc.w - help.w) <= 2, ok && `y ${acc.y} < ${help.y} < ${notices.y}`);
        }
        record(`${tag} notices below both cards, full width`, ok && notices.y >= Math.max(acc.y + acc.h, help.y + help.h) && notices.w >= (label === 'desktop' ? acc.w + help.w : help.w), ok && `notices ${notices.w}px`);
        const fs = await page.evaluate(() => parseFloat(getComputedStyle(document.querySelector('.cpv-home-notice .fi-wi-stats-overview-stat-value')).fontSize));
        record(`${tag} notice text at reading size`, fs >= 14 && fs <= 18, `${fs}px`);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        record(`${tag} no page-level horizontal overflow`, overflow <= 1, `excess=${overflow}px`);
        await page.screenshot({ path: `${OUT}/home-${who}-${scheme}-${label}.png`, fullPage: true });

        if (scheme === 'light' && label === 'desktop') {
          // Keyboard order: Salir (account) comes before the help links.
          await page.goto(BASE + HOME, { waitUntil: 'networkidle' });
          const order = [];
          for (let i = 0; i < 60 && order.length < 3; i++) {
            await page.keyboard.press('Tab');
            const hit = await page.evaluate(() => {
              const a = document.activeElement;
              if (a?.closest('.fi-account-widget')) return 'Salir';
              if (a?.closest('.cpv-profile-help-widget')) return a.textContent.trim();
              return null;
            });
            if (hit && !order.includes(hit)) order.push(hit);
          }
          record(`${tag} keyboard order: Salir, Manual de usuario, Mapa de la taxonomía`, order.join(' | ') === 'Salir | Manual de usuario | Mapa de la taxonomía', order.join(' | '));

          await page.locator('.cpv-profile-help-widget').getByRole('link', { name: 'Manual de usuario' }).click();
          await page.waitForURL('**/admin/manual-de-usuario', { timeout: 15000 }).catch(() => {});
          record(`${tag} "Manual de usuario" opens the manual`, page.url().endsWith('/admin/manual-de-usuario'), page.url().replace(BASE, ''));
          await page.goto(BASE + HOME, { waitUntil: 'networkidle' });
          await page.locator('.cpv-profile-help-widget').getByRole('link', { name: 'Mapa de la taxonomía' }).click();
          await page.waitForURL('**/admin/mapa-de-la-taxonomia', { timeout: 15000 }).catch(() => {});
          record(`${tag} "Mapa de la taxonomía" opens the map`, page.url().endsWith('/admin/mapa-de-la-taxonomia'), page.url().replace(BASE, ''));

          // The sidebar «Inicio» item brings the user back home (same URL as before).
          await page.locator('.fi-sidebar-item a', { hasText: 'Inicio' }).first().click();
          await page.waitForURL((u) => new URL(u).pathname === HOME, { timeout: 15000 }).catch(() => {});
          record(`${tag} sidebar «Inicio» returns to ${HOME}`, new URL(page.url()).pathname === HOME, page.url().replace(BASE, ''));

          await page.locator('.fi-account-widget').getByRole('button', { name: 'Salir' }).click();
          await page.waitForURL('**/admin/login', { timeout: 15000 }).catch(() => {});
          record(`${tag} «Salir» logs out`, /\/admin\/login/.test(page.url()), page.url().replace(BASE, ''));
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
