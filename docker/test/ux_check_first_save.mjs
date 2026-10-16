// Browser check of CPV-FIRST-SAVE-POSTGRES-COMPATIBILITY (Issue #2 comment 6212967641): a synthetic affiliate
// whose company has NO module rows (ux_seed_first_save.php asserts it) saves each of the five profile modules
// for the first time through the real forms, then edits them; validation errors are shown; «Otros» and
// «No aplica» work; the synthetic admin sees and edits the same company. Desktop light does the writes;
// desktop dark and mobile light/dark open the same tabs and modals (no overflow, screenshots).

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
  // ------------------------------------------------------------------ affiliate, desktop light: the writes
  {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: 'light' });
    const page = await login(ctx, AFFILIATE);
    await openCompany(page);
    const tag = '[affiliate/light/desktop]';

    await step(`${tag} Gestión`, async () => {
      await openTab(page, /Sistemas de gestión/);
      // Validation: «Otros» on, name empty.
      let modal = await header(page, 'Calidad');
      await modal.getByLabel('ISO 9001', { exact: true }).check();
      await modal.getByRole('switch', { name: /Otros/ }).click();
      await page.waitForTimeout(500);
      await addItem(page, modal);
      await submit(page, modal);
      // The required field is enforced first by the browser (native constraint validation, a bubble),
      // or by the server (Filament message) if the browser lets it through. Either is visible feedback.
      const err = await modal.locator('.fi-fo-field-wrp-error-message').first().innerText().catch(() => '');
      const native = await modal.getByLabel('Calidad: otros').first().evaluate((el) => el.validationMessage).catch(() => '');
      record(`${tag} Gestión: «Otros» without a name is rejected with visible feedback and keeps the modal`, !(await closed(modal)) && (err.length > 0 || native.length > 0), err || `native: ${native}`);
      await page.screenshot({ path: `${OUT}/fs-gestion-validation.png` });
      // Fill the name and save: FIRST save of the module.
      await modal.getByLabel('Calidad: otros').first().fill('ZZZ NORMA PROPIA');
      await submit(page, modal);
      record(`${tag} Gestión: first save (Calidad + Otros) closes the modal`, await closed(modal));
      const summary = await rm(page).innerText();
      record(`${tag} Gestión: summary shows ISO 9001 and the «Otros» name`, /ISO 9001/.test(summary) && /ZZZ NORMA PROPIA/.test(summary), summary.replace(/\s+/g, ' ').slice(0, 160));
      // Update another section; «No aplica» on a third and revert.
      modal = await header(page, 'Seguridad');
      await modal.getByLabel('OVID', { exact: true }).check();
      await submit(page, modal);
      modal = await header(page, 'Calidad');
      record(`${tag} Gestión: re-opened Calidad keeps ISO 9001 after saving Seguridad`, await modal.getByLabel('ISO 9001', { exact: true }).isChecked());
      await page.keyboard.press('Escape'); await page.waitForTimeout(500);
      modal = await header(page, 'Ambiente');
      await modal.getByRole('switch', { name: 'No Aplica' }).click();
      await submit(page, modal);
      modal = await header(page, 'Ambiente');
      record(`${tag} Gestión: «No Aplica» on Ambiente persists`, await modal.getByRole('switch', { name: 'No Aplica' }).getAttribute('aria-checked') === 'true');
      await modal.getByRole('switch', { name: 'No Aplica' }).click();
      await page.waitForTimeout(400);
      await modal.getByLabel(/ISO 14001/).check();
      await submit(page, modal);
      modal = await header(page, 'Ambiente');
      record(`${tag} Gestión: reverting «No Aplica» and ticking ISO 14001 persists`, (await modal.getByRole('switch', { name: 'No Aplica' }).getAttribute('aria-checked')) === 'false' && await modal.getByLabel(/ISO 14001/).isChecked());
      await page.keyboard.press('Escape'); await page.waitForTimeout(500);
    });

    await step(`${tag} Recursos`, async () => {
      await openCompany(page);
      await openTab(page, /Recursos en Venezuela/);
      const modal = await header(page, 'Recursos humanos');
      await addItem(page, modal);
      await modal.locator('select').first().selectOption({ index: 1 });
      await submit(page, modal);
      record(`${tag} Recursos: first save (Recursos humanos) closes the modal`, await closed(modal));
      const m2 = await header(page, 'Inventario');
      await addItem(page, m2);
      await m2.locator('select').nth(0).selectOption({ index: 1 });
      await m2.locator('select').nth(1).selectOption({ index: 1 });
      await m2.getByLabel('Unidad').first().fill('KG');
      await m2.locator('select').nth(2).selectOption({ index: 1 });
      await submit(page, m2);
      record(`${tag} Recursos: second type (Inventario) saves into the same record`, await closed(m2));
      const text = await rm(page).innerText();
      record(`${tag} Recursos: summary shows both types`, /Bachilleres|Técnicos|Ingenieros/.test(text) && /KG/.test(text), text.replace(/\s+/g, ' ').slice(0, 160));
      await page.screenshot({ path: `${OUT}/fs-recursos.png` });
    });

    await step(`${tag} Experiencia`, async () => {
      await openCompany(page);
      await openTab(page, /Experiencia relevante/);
      let modal = await header(page, 'Agregar experiencia');
      await submit(page, modal);
      record(`${tag} Experiencia: missing year shows a validation error`, !(await closed(modal)));
      await modal.getByLabel('Año').fill('2020');
      await modal.locator('textarea').first().fill('ZZZ proyecto uno');
      await submit(page, modal);
      record(`${tag} Experiencia: first add closes the modal`, await closed(modal));
      modal = await header(page, 'Agregar experiencia');
      await modal.getByLabel('Año').fill('2023');
      await modal.locator('textarea').first().fill('ZZZ proyecto dos');
      await submit(page, modal);
      const rows = await rm(page).locator('.fi-ta-row').count();
      record(`${tag} Experiencia: two experiences listed`, rows === 2, `rows=${rows}`);
      await rm(page).locator('.fi-ta-row').filter({ hasText: '2020' }).getByRole('button', { name: 'Editar' }).click();
      modal = page.locator('.fi-modal-window:visible').last();
      await modal.waitFor({ state: 'visible' });
      await modal.getByLabel('Año').fill('2021');
      await submit(page, modal);
      const text = await rm(page).innerText();
      record(`${tag} Experiencia: editing one entry changes only that one`, /2021/.test(text) && /2023/.test(text) && !/2020/.test(text), text.replace(/\s+/g, ' ').slice(0, 160));
      await page.screenshot({ path: `${OUT}/fs-experiencia.png` });
    });

    await step(`${tag} Presencia`, async () => {
      await openCompany(page);
      await openTab(page, /Presencia internacional/);
      const create = rm(page).getByRole('button', { name: /^(Crear|Nuevo)/ }).first();
      await create.click();
      const modal = page.locator('.fi-modal-window:visible').last();
      await modal.waitFor({ state: 'visible' });
      await modal.getByRole('switch', { name: /Tiene oficinas/ }).click();
      await page.waitForTimeout(600);
      await modal.locator('select').first().selectOption({ label: 'ZZZ País sintético' }).catch(() => {});
      await modal.getByRole('button', { name: 'Siguiente' }).click().catch(() => {});
      await page.waitForTimeout(500);
      await page.screenshot({ path: `${OUT}/fs-presencia-wizard.png` });
      await submit(page, modal, /^Crear$/);
      record(`${tag} Presencia: first create closes the modal`, await closed(modal));
      const rows = await rm(page).locator('.fi-ta-row').count();
      const hidden = !(await rm(page).getByRole('button', { name: /^(Crear|Nuevo)/ }).first().isVisible().catch(() => false));
      record(`${tag} Presencia: one record and «Crear» hidden afterwards`, rows === 1 && hidden, `rows=${rows} createHidden=${hidden}`);
      await rm(page).locator('.fi-ta-row').first().getByRole('button', { name: 'Editar' }).click();
      const edit = page.locator('.fi-modal-window:visible').last();
      await edit.waitFor({ state: 'visible' });
      await edit.getByRole('button', { name: 'Siguiente' }).click().catch(() => {});
      await page.waitForTimeout(400);
      await edit.getByRole('switch', { name: /Tiene experiencia/ }).click();
      await page.waitForTimeout(600);
      if (!(await edit.locator('select').filter({ has: page.locator('option', { hasText: 'Subcontratista' }) }).count())) await addItem(page, edit);
      const selects = edit.locator('select');
      await selects.nth(0).selectOption({ label: 'ZZZ País sintético' }).catch(() => {});
      await edit.locator('select').filter({ has: page.locator('option', { hasText: 'Subcontratista' }) }).first().selectOption({ label: 'Ambos' });
      await edit.locator('select').filter({ has: page.locator('option', { hasText: '< 100.000 USD' }) }).first().selectOption({ index: 2 });
      await submit(page, edit, /^(Guardar cambios|Guardar)$/);
      record(`${tag} Presencia: edit (international experience) saves`, await closed(edit));
      await page.screenshot({ path: `${OUT}/fs-presencia.png` });
    });

    await step(`${tag} Sostenibilidad`, async () => {
      await openCompany(page);
      await openTab(page, /Enfoques de sostenibilidad/);
      for (const [i, status] of [[1, 'Sí: Activo'], [2, 'Sí: Inactivo']]) {
        await rm(page).getByRole('button', { name: /^(Crear|Nuevo)/ }).first().click();
        const modal = page.locator('.fi-modal-window:visible').last();
        await modal.waitFor({ state: 'visible' });
        if (i === 1) {
          await submit(page, modal, /^Crear$/);
          record(`${tag} Sostenibilidad: missing status shows a validation error`, !(await closed(modal)));
        }
        await modal.locator('select').nth(0).selectOption({ index: i });
        await page.waitForTimeout(400);
        await modal.locator('select').nth(1).selectOption({ label: status });
        await submit(page, modal, /^Crear$/);
        record(`${tag} Sostenibilidad: create #${i} closes the modal`, await closed(modal));
      }
      const rows = await rm(page).locator('.fi-ta-row').count();
      record(`${tag} Sostenibilidad: two rows (several are legitimate)`, rows === 2, `rows=${rows}`);
      await page.screenshot({ path: `${OUT}/fs-sostenibilidad.png` });
    });
    await ctx.close();
  }

  // ------------------------------------------------------------------ admin: sees and updates the same data
  {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: 'light' });
    const page = await login(ctx, ADMIN);
    editUrl = null;
    await step('[admin/light/desktop] Gestión', async () => {
      await openCompany(page);
      await openTab(page, /Sistemas de gestión/);
      const text = await rm(page).innerText();
      record('[admin/light/desktop] sees the affiliate\'s Gestión data', /ZZZ NORMA PROPIA/.test(text));
      let modal = await header(page, 'Gestión de proyectos');
      await modal.getByLabel(/PMI/).check();
      await submit(page, modal);
      modal = await header(page, 'Gestión de proyectos');
      record('[admin/light/desktop] admin update persists', await modal.getByLabel(/PMI/).isChecked());
      await page.keyboard.press('Escape');
      await page.screenshot({ path: `${OUT}/fs-admin-gestion.png` });
    });
    await ctx.close();
  }

  // ------------------------------------------------------------------ other schemes/widths: read-only pass
  for (const [who, email] of [['affiliate', AFFILIATE], ['admin', ADMIN]]) {
    for (const [label, viewport, scheme] of [['desktop', { width: 1440, height: 900 }, 'dark'], ['mobile', { width: 390, height: 844 }, 'light'], ['mobile', { width: 390, height: 844 }, 'dark']]) {
      const tag = `[${who}/${scheme}/${label}]`;
      const ctx = await browser.newContext({ viewport, colorScheme: scheme });
      const page = await login(ctx, email);
      editUrl = null;
      await step(tag, async () => {
        await openCompany(page);
        await page.evaluate((dark) => document.documentElement.classList.toggle('dark', dark), scheme === 'dark');
        await openTab(page, /Enfoques de sostenibilidad/);
        const rows = await rm(page).locator('.fi-ta-row').count();
        record(`${tag} Sostenibilidad lists the two saved rows`, rows === 2, `rows=${rows}`);
        await rm(page).getByRole('button', { name: /^(Crear|Nuevo)/ }).first().click();
        const modal = page.locator('.fi-modal-window:visible').last();
        await modal.waitFor({ state: 'visible' });
        const ov = await overflow(page);
        record(`${tag} create modal opens without page overflow`, ov <= 1, `excess=${ov}px`);
        await page.screenshot({ path: `${OUT}/fs-${who}-${scheme}-${label}-sostenibilidad.png` });
        await page.keyboard.press('Escape');
        await page.waitForTimeout(400);
        await openTab(page, /Sistemas de gestión/);
        const m = await header(page, 'Calidad');
        record(`${tag} Gestión Calidad shows the saved ISO 9001`, await m.getByLabel('ISO 9001', { exact: true }).isChecked());
        await page.screenshot({ path: `${OUT}/fs-${who}-${scheme}-${label}-gestion.png` });
      });
      await ctx.close();
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
