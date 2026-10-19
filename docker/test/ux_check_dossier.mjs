// Browser check of CPV-COMPLETE-COMPANY-PDF (Issue #2 comment 6270558322), on synthetic data:
// - affiliate, desktop light: Empresas shows a VISIBLE «Perfil completo (PDF)» button on its company's row; clicking it
//   downloads a real file named perfil-<rif>.pdf; «Elegir secciones» opens with «Todas las secciones» selected, and
//   choosing two sections downloads perfil-<rif>-parcial.pdf; the listing exporter is labelled «Exportar listado»
//   (bulk action), separate from the row's full-profile button; the company page header offers
//   «Descargar perfil completo (PDF)»;
// - affiliate, mobile dark: the button is visible and downloads; no horizontal page overflow;
// - authorized staff (synthetic admin linked to A, with view_empresa): the same button downloads A's full profile.
// Downloaded files are saved in /out (ux/) so the runner can render their pages.

import { chromium } from 'playwright';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';

const BASE = process.env.BASE_URL;
const AFFILIATE = process.env.UX_EMAIL;
const ADMIN = process.env.UX_ADMIN_EMAIL || 'zzz-ux-admin@example.test';
const PASSWORD = process.env.UX_PASSWORD;
const OUT = '/out';
if (!BASE || !AFFILIATE || !PASSWORD) { console.error('env missing'); process.exit(2); }
mkdirSync(OUT, { recursive: true });
const seed = readFileSync(`${OUT}/ux_seed.log`, 'utf8');
const A = (seed.match(/^A_ID=(\d+)$/m) || [])[1];
const RIF = (seed.match(/^A_RIF=([a-z0-9]+)$/m) || [])[1];

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

async function openList(page) {
  await page.goto(BASE + '/admin/empresas', { waitUntil: 'networkidle' });
  await page.evaluate(() => window.Alpine?.store('sidebar')?.close?.()).catch(() => {});
  const button = page.locator(`a[href$="/admin/descargas/empresas/${A}/pdf"]`, { hasText: 'Perfil completo (PDF)' }).first();
  await button.waitFor({ timeout: 20000 });
  return button;
}

async function download(page, trigger, saveAs) {
  const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 30000 }), trigger()]);
  const name = dl.suggestedFilename();
  await dl.saveAs(`${OUT}/${saveAs}`);
  const head = readFileSync(`${OUT}/${saveAs}`).subarray(0, 4).toString();
  return { name, head };
}

try {
  record('seed gave the synthetic company id and RIF', !!A && !!RIF, `A=${A} rif=${RIF}`);
  let affiliateState;

  // ---------------------------------------------------------------- affiliate, desktop light
  {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: 'light', acceptDownloads: true });
    const page = await login(ctx, AFFILIATE);
    current = page;
    await step('[affiliate/light/desktop] full profile', async () => {
      const button = await openList(page);
      const box = await button.boundingBox();
      record('[affiliate] «Perfil completo (PDF)» is a visible button on its row', await button.isVisible() && box && box.width > 40 && box.height > 15, box && `${Math.round(box.width)}x${Math.round(box.height)}`);
      await button.hover();
      await page.waitForTimeout(400);
      await page.screenshot({ path: `${OUT}/dos-list-desktop-light.png` });
      const tip = await page.locator('[data-tippy-root], .tippy-box').allInnerTexts().catch(() => []);
      record('[affiliate] the button has a tooltip that says it is the full profile', tip.some((t) => /perfil completo/i.test(t)), tip.join(' | ').slice(0, 120));
      const { name, head } = await download(page, () => button.click(), `browser-full-desktop.pdf`);
      record('[affiliate] clicking it downloads perfil-<rif>.pdf (a real PDF)', name === `perfil-${RIF}.pdf` && head === '%PDF', `${name} ${head}`);
    });

    await step('[affiliate/light/desktop] choose sections', async () => {
      await openList(page);
      await page.getByRole('button', { name: 'Elegir secciones' }).first().click();
      const modal = page.locator('.fi-modal-window').filter({ hasText: 'Descargar el perfil en PDF' }).first();
      await modal.waitFor({ timeout: 10000 });
      const all = modal.getByLabel('Todas las secciones (perfil completo)');
      record('[affiliate] the modal opens with «Todas las secciones (perfil completo)» selected', await all.isChecked());
      await modal.getByLabel('Solo las secciones que elija').check();
      await modal.getByText('Sistemas de gestión', { exact: true }).waitFor({ timeout: 10000 });
      const boxes = modal.locator('input[type="checkbox"]');
      const total = await boxes.count();
      let checked = 0;
      for (let i = 0; i < total; i++) if (await boxes.nth(i).isChecked()) checked++;
      record('[affiliate] choosing «Solo las secciones que elija» shows the 10 sections, all checked by default', total >= 10 && checked >= 10, `${checked}/${total}`);
      for (const label of ['Clientes', 'Cámaras', 'Sectores de actividad económica y servicios', 'Categorías CPV', 'Enfoque de sostenibilidad', 'Recursos en Venezuela', 'Experiencia relevante', 'Presencia internacional']) {
        await modal.getByLabel(label, { exact: true }).uncheck();
      }
      await page.screenshot({ path: `${OUT}/dos-sections-modal.png` });
      const { name, head } = await download(page, () => modal.getByRole('button', { name: 'Descargar PDF' }).click(), 'browser-sections.pdf');
      record('[affiliate] «Descargar PDF» with two sections downloads perfil-<rif>-parcial.pdf', name === `perfil-${RIF}-parcial.pdf` && head === '%PDF', `${name} ${head}`);
    });

    await step('[affiliate/light/desktop] listing export is separate', async () => {
      await openList(page);
      await page.locator('tbody input[type="checkbox"]').first().check();
      await page.waitForTimeout(600);
      const item = page.getByRole('button', { name: 'Exportar listado' }).first();
      const inRow = await page.locator('tbody').getByText('Exportar listado').count();
      record('[affiliate] the listing exporter is the toolbar action «Exportar listado» for selected rows, not on the row', await item.isVisible() && inRow === 0, `in rows: ${inRow}`);
      await page.screenshot({ path: `${OUT}/dos-bulk-export-listado.png` });
      await item.click();
      await page.waitForTimeout(1200);
      const modalText = (await page.locator('.fi-modal-window').last().innerText().catch(() => '')).replace(/\s+/g, ' ');
      record('[affiliate] its modal says it is a listing and not the full profile', /Exportar listado de empresas/.test(modalText) && /No es el perfil completo/.test(modalText), modalText.slice(0, 160));
      await page.screenshot({ path: `${OUT}/dos-export-listado-modal.png` });
    });

    await step('[affiliate/light/desktop] company page header', async () => {
      await page.goto(`${BASE}/admin/empresas/${A}/edit`, { waitUntil: 'networkidle' });
      const header = page.locator(`a[href$="/admin/descargas/empresas/${A}/pdf"]`, { hasText: 'Descargar perfil completo (PDF)' }).first();
      await header.waitFor({ timeout: 20000 });
      record('[affiliate] the company page header offers «Descargar perfil completo (PDF)»', await header.isVisible());
      await page.screenshot({ path: `${OUT}/dos-edit-header.png` });
      const { name, head } = await download(page, () => header.click(), 'browser-full-from-edit.pdf');
      record('[affiliate] the header button downloads perfil-<rif>.pdf', name === `perfil-${RIF}.pdf` && head === '%PDF', `${name} ${head}`);
    });
    affiliateState = await ctx.storageState();
    await ctx.close();
  }

  // ---------------------------------------------------------------- affiliate, mobile dark
  {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, colorScheme: 'dark', storageState: affiliateState, acceptDownloads: true });
    const page = await ctx.newPage();
    current = page;
    await step('[affiliate/dark/mobile] full profile', async () => {
      const button = await openList(page);
      const mb = await button.boundingBox();
      record('[affiliate/mobile] «Perfil completo (PDF)» is visible without scrolling the table', await button.isVisible() && mb && mb.x >= 0 && mb.x + mb.width <= 390, mb && `x=${Math.round(mb.x)} w=${Math.round(mb.width)}`);
      const ov = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      record('[affiliate/mobile] no horizontal page overflow', ov <= 1, `excess=${ov}px`);
      await page.screenshot({ path: `${OUT}/dos-list-mobile-dark.png`, fullPage: true });
      const { name, head } = await download(page, () => button.click(), 'browser-full-mobile.pdf');
      record('[affiliate/mobile] it downloads perfil-<rif>.pdf', name === `perfil-${RIF}.pdf` && head === '%PDF', `${name} ${head}`);
    });
    await ctx.close();
  }

  // ---------------------------------------------------------------- authorized staff (linked, with view_empresa)
  {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: 'light', acceptDownloads: true });
    const page = await login(ctx, ADMIN);
    current = page;
    await step('[staff/light/desktop] full profile of a linked company', async () => {
      const button = await openList(page);
      record('[staff] sees «Perfil completo (PDF)» for the linked company', await button.isVisible());
      const { name, head } = await download(page, () => button.click(), 'browser-full-staff.pdf');
      record('[staff] it downloads perfil-<rif>.pdf', name === `perfil-${RIF}.pdf` && head === '%PDF', `${name} ${head}`);
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
