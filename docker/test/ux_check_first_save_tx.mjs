// Short browser confirmation for Issue #2 comment 6214463281: after Presencia and Sostenibilidad moved into
// Filament's action transaction, their first create still works through the real forms and stays committed
// after a reload. Same seed as ux_check_first_save.mjs (ux_seed_first_save.php: the company starts with 0 rows).
import { chromium } from 'playwright';
import { writeFileSync, mkdirSync } from 'node:fs';

const BASE = process.env.BASE_URL;
const AFFILIATE = process.env.UX_EMAIL;
const ADMIN = process.env.UX_ADMIN_EMAIL;
const PASSWORD = process.env.UX_PASSWORD;
const OUT = '/out';
if (!BASE || !AFFILIATE || !ADMIN || !PASSWORD) { console.error('env missing'); process.exit(2); }
mkdirSync(OUT, { recursive: true });

const results = [];
const record = (name, pass, detail) => {
  results.push({ name, pass: !!pass, detail });
  console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail !== undefined ? '  :: ' + detail : ''}`);
};
const step = async (name, fn) => { try { await fn(); } catch (e) { record(`${name} (crashed)`, false, String(e && e.message || e).split(/\r?\n/)[0]); } };

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

let editUrl = null;
async function openCompany(page) {
  if (!editUrl) {
    await page.goto(BASE + '/admin/empresas', { waitUntil: 'networkidle' });
    const edit = page.locator('a[href*="/admin/empresas/"][href$="/edit"]').first();
    await edit.waitFor({ timeout: 20000 });
    editUrl = new URL(await edit.getAttribute('href'), BASE).href;
  }
  await page.goto(editUrl, { waitUntil: 'networkidle' });
  if (page.viewportSize().width < 1024) await page.evaluate(() => window.Alpine?.store('sidebar')?.close?.());
  await page.waitForTimeout(600);
}

const rm = (page) => page.locator('.fi-resource-relation-manager').first();

async function openTab(page, name) {
  const tab = page.getByRole('tab', { name }).first();
  for (let i = 0; i < 6 && !(await tab.isVisible().catch(() => false)); i++) {
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(600);
  }
  await tab.click();
  await page.waitForTimeout(1500);
  for (let i = 0; i < 6; i++) {
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(500);
  }
}

async function header(page, name) {
  const button = rm(page).getByRole('button', { name, exact: true }).first();
  await button.scrollIntoViewIfNeeded();
  await button.click();
  const modal = page.locator('.fi-modal-window:visible').last();
  await modal.waitFor({ state: 'visible', timeout: 15000 });
  await page.waitForTimeout(500);
  return modal;
}

async function submit(page, modal, label = /^(Enviar|Crear|Guardar cambios|Guardar)$/) {
  await modal.getByRole('button', { name: label }).first().click();
  await page.waitForTimeout(2000);
}

// Empty repeaters start with no items: add one before filling its fields.
const addItem = async (page, modal) => { await modal.getByRole('button', { name: /^(Agregar|Añadir)/ }).last().click(); await page.waitForTimeout(700); };

const closed = async (modal) => !(await modal.isVisible().catch(() => false));
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);

try {
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: 'light' });
  const page = await login(ctx, AFFILIATE);
  const tag = '[affiliate/light/desktop]';

  await step(`${tag} Presencia`, async () => {
    await openCompany(page);
    await openTab(page, /Presencia internacional/);
    await rm(page).getByRole('button', { name: /^(Crear|Nuevo)/ }).first().click();
    const modal = page.locator('.fi-modal-window:visible').last();
    await modal.waitFor({ state: 'visible' });
    await modal.getByRole('switch', { name: /Tiene oficinas/ }).click();
    await page.waitForTimeout(600);
    await modal.locator('select').first().selectOption({ label: 'ZZZ País sintético' }).catch(() => {});
    await modal.getByRole('button', { name: 'Siguiente' }).click().catch(() => {});
    await page.waitForTimeout(500);
    await submit(page, modal, /^Crear$/);
    record(`${tag} Presencia: first create (now in the action transaction) closes the modal`, await closed(modal));
    const note = await page.locator('.fi-no-notification').first().innerText().catch(() => '');
    record(`${tag} Presencia: success notification`, /Creado/.test(note), note.replace(/\s+/g, ' ').slice(0, 80));
    const rows = await rm(page).locator('.fi-ta-row').count();
    const hidden = !(await rm(page).getByRole('button', { name: /^(Crear|Nuevo)/ }).first().isVisible().catch(() => false));
    record(`${tag} Presencia: one record and «Crear» hidden afterwards`, rows === 1 && hidden, `rows=${rows} createHidden=${hidden}`);
    await openCompany(page);
    await openTab(page, /Presencia internacional/);
    const rows2 = await rm(page).locator('.fi-ta-row').count();
    record(`${tag} Presencia: the record is still there after a reload (committed)`, rows2 === 1, `rows=${rows2}`);
    await page.screenshot({ path: `${OUT}/fstx-presencia.png` });
  });

  await step(`${tag} Sostenibilidad`, async () => {
    await openCompany(page);
    await openTab(page, /Enfoques de sostenibilidad/);
    await rm(page).getByRole('button', { name: /^(Crear|Nuevo)/ }).first().click();
    const modal = page.locator('.fi-modal-window:visible').last();
    await modal.waitFor({ state: 'visible' });
    await modal.locator('select').nth(0).selectOption({ index: 1 });
    await page.waitForTimeout(400);
    await modal.locator('select').nth(1).selectOption({ label: 'Sí: Activo' });
    await submit(page, modal, /^Crear$/);
    record(`${tag} Sostenibilidad: first create (now in the action transaction) closes the modal`, await closed(modal));
    const note = await page.locator('.fi-no-notification').first().innerText().catch(() => '');
    record(`${tag} Sostenibilidad: success notification`, /Creado/.test(note), note.replace(/\s+/g, ' ').slice(0, 80));
    await openCompany(page);
    await openTab(page, /Enfoques de sostenibilidad/);
    const rows = await rm(page).locator('.fi-ta-row').count();
    record(`${tag} Sostenibilidad: one row after a reload (committed)`, rows === 1, `rows=${rows}`);
    await page.screenshot({ path: `${OUT}/fstx-sostenibilidad.png` });
  });
  await ctx.close();
} catch (e) {
  record('browser check crashed', false, String(e && e.stack || e));
} finally {
  await browser.close();
}

writeFileSync(`${OUT}/ux_results.json`, JSON.stringify({ results }, null, 2));
const failed = results.filter((r) => !r.pass).length;
console.log(`\n${results.length - failed}/${results.length} checks passed`);
process.exit(failed === 0 ? 0 : 1);
