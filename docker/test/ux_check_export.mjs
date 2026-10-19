// Browser check of CPV-EXPORT-ACCESS-REPAIR-AND-ERROR-PAGES (Issue #2 comment 6264837159), on synthetic data:
// - affiliate: the «PDF» column of Empresas points to the protected route and downloads its own company (PDF,
//   private headers); the XLS of its own company works; another company's and a missing id's URLs show the same
//   Spanish «Archivo no disponible» 404 (desktop/mobile, light/dark), with no other-company data;
// - a general unknown URL shows «Página no encontrada»;
// - guest: an export URL goes to the panel login, and the old /pdf/{id} only redirects;
// - unverified: an export URL goes to «Verifique su correo electrónico»; logout works.

import { chromium } from 'playwright';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';

const BASE = process.env.BASE_URL;
const AFFILIATE = process.env.UX_EMAIL;
const PASSWORD = process.env.UX_PASSWORD;
const UNVERIFIED = 'zzz-ux-unverified@example.test';
const OUT = '/out';
if (!BASE || !AFFILIATE || !PASSWORD) { console.error('env missing'); process.exit(2); }
mkdirSync(OUT, { recursive: true });
const seed = readFileSync(`${OUT}/ux_seed.log`, 'utf8');
const A = (seed.match(/^A_ID=(\d+)$/m) || [])[1];
const B = (seed.match(/^B_ID=(\d+)$/m) || [])[1];
const exportUrl = (id, kind) => `${BASE}/admin/descargas/empresas/${id}/perfil.${kind}`;

const results = [];
const record = (name, pass, detail) => {
  results.push({ name, pass: !!pass, detail });
  console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail !== undefined ? '  :: ' + detail : ''}`);
};
let current = null;
const step = async (name, fn) => {
  try { await fn(); } catch (e) {
    record(`${name} (crashed)`, false, String(e && e.message || e).split(/\r?\n/)[0]);
    if (current) await current.screenshot({ path: `${OUT}/crash-${name.replace(/[^a-z0-9]+/gi, '-')}.png` }).catch(() => {});
  }
};
const path = (page) => new URL(page.url()).pathname;
const browser = await chromium.launch({ args: ['--no-sandbox'] });

async function login(ctx, email) {
  const page = await ctx.newPage();
  await page.goto(BASE + '/admin/login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[type="email"], input[id$="email"]', email);
  await page.fill('input[type="password"], input[id$="password"]', PASSWORD);
  await Promise.all([page.waitForLoadState('networkidle').catch(() => {}), page.click('button[type="submit"]')]);
  await page.waitForTimeout(1500);
  return page;
}

try {
  record('seed gave two synthetic company ids', !!A && !!B, `A=${A} B=${B}`);

  // ---------------------------------------------------------------- affiliate, desktop light: downloads
  {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: 'light' });
    const page = await login(ctx, AFFILIATE);
    current = page;
    await step('[affiliate/light/desktop] own downloads', async () => {
      await page.goto(BASE + '/admin/empresas', { waitUntil: 'networkidle' });
      const link = page.locator(`a[href*="/admin/descargas/empresas/${A}/perfil.pdf"]`).first();
      await link.waitFor({ timeout: 20000 });
      record('[affiliate] the «PDF» column links to the protected route of its own company', await link.count() === 1);
      const pdf = await page.request.get(await link.getAttribute('href'));
      const h = pdf.headers();
      const body = await pdf.body();
      record('[affiliate] own PDF downloads (200, application/pdf, %PDF)', pdf.status() === 200 && /application\/pdf/.test(h['content-type'] || '') && body.slice(0, 4).toString() === '%PDF', `${pdf.status()} ${h['content-type']}`);
      record('[affiliate] own PDF headers: private, no-store, nosniff, named file', /private/.test(h['cache-control']) && /no-store/.test(h['cache-control']) && h['x-content-type-options'] === 'nosniff' && /perfil-[a-z0-9-]+\.pdf/.test(h['content-disposition'] || ''), `${h['cache-control']} | ${h['content-disposition']}`);
      const xls = await page.request.get(exportUrl(A, 'xlsx'));
      const hx = xls.headers();
      record('[affiliate] own XLS downloads (200, attachment, private)', xls.status() === 200 && /attachment/.test(hx['content-disposition'] || '') && /no-store/.test(hx['cache-control'] || ''), `${xls.status()} ${hx['content-disposition']}`);
      await page.screenshot({ path: `${OUT}/exp-empresas-pdf-column.png` });
    });
    await ctx.close();
  }

  // ---------------------------------------------------------------- affiliate: the 404s, 4 schemes/widths
  for (const [label, viewport, scheme] of [['desktop', { width: 1440, height: 900 }, 'light'], ['desktop', { width: 1440, height: 900 }, 'dark'], ['mobile', { width: 390, height: 844 }, 'light'], ['mobile', { width: 390, height: 844 }, 'dark']]) {
    const tag = `[affiliate/${scheme}/${label}]`;
    const ctx = await browser.newContext({ viewport, colorScheme: scheme });
    const page = await login(ctx, AFFILIATE);
    current = page;
    await step(`${tag} 404`, async () => {
      const texts = [];
      for (const [what, url] of [['other company PDF', exportUrl(B, 'pdf')], ['other company XLS', exportUrl(B, 'xlsx')], ['missing id PDF', exportUrl('999999999', 'pdf')], ['invalid id', exportUrl('abc', 'pdf')]]) {
        const resp = await page.goto(url, { waitUntil: 'domcontentloaded' });
        const text = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
        texts.push(text);
        record(`${tag} ${what}: 404 «Archivo no disponible», no other-company data`, resp.status() === 404 && /Archivo no disponible/.test(text) && !/AJENA|J000000017/i.test(text), `${resp.status()}`);
      }
      record(`${tag} the four denials show the same text`, texts.every((t) => t === texts[0]));
      const links = await page.locator('nav a').allInnerTexts();
      record(`${tag} useful links (Empresas, Inicio)`, links.includes('Ir a Empresas') && links.includes('Ir al Inicio'), links.join(', '));
      const ov = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      record(`${tag} no horizontal overflow`, ov <= 1, `excess=${ov}px`);
      await page.goto(exportUrl(B, 'pdf'), { waitUntil: 'domcontentloaded' });
      await page.screenshot({ path: `${OUT}/exp-404-${scheme}-${label}.png`, fullPage: true });
      await page.getByRole('link', { name: 'Ir a Empresas' }).click();
      await page.waitForLoadState('networkidle').catch(() => {});
      record(`${tag} «Ir a Empresas» opens the list`, path(page) === '/admin/empresas', path(page));
    });
    await ctx.close();
  }

  // ---------------------------------------------------------------- general 404, guest
  {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: 'light' });
    const page = await ctx.newPage();
    current = page;
    await step('[guest] general 404 and exports', async () => {
      const resp = await page.goto(BASE + '/esta-pagina-no-existe-zzz', { waitUntil: 'domcontentloaded' });
      const text = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
      record('[guest] unknown URL: 404 «Página no encontrada», no URL echoed', resp.status() === 404 && /Página no encontrada/.test(text) && !/esta-pagina-no-existe/.test(text));
      await page.screenshot({ path: `${OUT}/exp-404-general.png` });
      await page.goto(exportUrl(A, 'pdf'), { waitUntil: 'domcontentloaded' });
      record('[guest] export URL → panel login', path(page) === '/admin/login', path(page));
      const old = await page.request.get(`${BASE}/pdf/${A}`, { maxRedirects: 0 });
      record('[guest] old /pdf/{id} only redirects to the protected route', old.status() === 302 && (old.headers().location || '').endsWith(`/admin/descargas/empresas/${A}/perfil.pdf`), `${old.status()} ${old.headers().location}`);
    });
    await ctx.close();
  }

  // ---------------------------------------------------------------- unverified
  {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, colorScheme: 'dark' });
    const page = await login(ctx, UNVERIFIED);
    current = page;
    await step('[unverified/dark/mobile] export', async () => {
      const resp = await page.goto(exportUrl(A, 'pdf'), { waitUntil: 'networkidle' });
      const text = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
      record('[unverified] own company export URL → «Verifique su correo electrónico» (no 500)', resp.status() === 200 && path(page) === '/admin/email-verification/prompt' && /todavía no envía correos/.test(text), `${resp.status()} ${path(page)}`);
      await page.screenshot({ path: `${OUT}/exp-unverified-prompt.png`, fullPage: true });
      await page.getByRole('button', { name: 'Cerrar sesión' }).click();
      await page.waitForLoadState('networkidle').catch(() => {});
      record('[unverified] «Cerrar sesión» goes to the login', path(page) === '/admin/login', path(page));
    });
    await ctx.close();
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
