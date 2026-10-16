// Browser check of CPV-MODEL-COMPATIBILITY-AND-LABELS (Issue #2 comment 6211997650): the «Sistemas de
// gestión» tab of a company shows OVID (not «COVID.») and explicit ISO 9001 / ISO 17025 labels, and saving
// still writes the same fields. Disposable stack only, synthetic users and company (ux_seed_management.php).
//
// For the synthetic affiliate and Super Admin, desktop 1440 and mobile 390, light and dark: open the
// company, the tab, the «Seguridad» and «Calidad» modals; read the labels; no «COVID»; no page overflow.
// Desktop light also saves: affiliate ticks OVID, admin ticks ISO 9001; the modal re-opens checked.

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

async function openManagementTab(page) {
  await page.goto(BASE + '/admin/empresas', { waitUntil: 'networkidle' });
  const edit = page.locator('a[href*="/admin/empresas/"][href$="/edit"]').first();
  await edit.waitFor({ timeout: 20000 }).catch(() => {});
  const href = await edit.getAttribute('href').catch(() => null);
  if (!href) return false;
  await page.goto(new URL(href, BASE).href, { waitUntil: 'networkidle' });
  // Below lg the sidebar is an overlay that would intercept clicks.
  if (page.viewportSize().width < 1024) await page.evaluate(() => window.Alpine?.store('sidebar')?.close?.());
  await page.waitForTimeout(800);
  const tab = page.getByRole('tab', { name: /Sistemas de gestión/ }).first();
  for (let i = 0; i < 6 && !(await tab.isVisible().catch(() => false)); i++) {
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(700);
  }
  if (!(await tab.isVisible().catch(() => false))) return false;
  await tab.click();
  await page.waitForTimeout(1500);
  // The relation manager and its table load lazily, when scrolled into view.
  const button = page.getByRole('button', { name: 'Seguridad', exact: true }).first();
  for (let i = 0; i < 8 && !(await button.isVisible().catch(() => false)); i++) {
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(800);
  }
  await button.scrollIntoViewIfNeeded().catch(() => {});
  return await button.isVisible().catch(() => false);
}

async function openModal(page, name) {
  await page.getByRole('button', { name, exact: true }).first().click();
  const modal = page.locator('.fi-modal-window').filter({ hasText: new RegExp(`Agregar / editar: ${name.toLowerCase()}`) }).first();
  await modal.waitFor({ state: 'visible', timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(500);
  return modal;
}

async function submitModal(page, modal) {
  await modal.locator('.fi-modal-footer button[type="submit"], .fi-modal-footer-actions button').first().click();
  await modal.waitFor({ state: 'hidden', timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(1500);
}

try {
  for (const [who, email] of [['affiliate', AFFILIATE], ['admin', ADMIN]]) {
    for (const [label, viewport] of [['desktop', { width: 1440, height: 900 }], ['mobile', { width: 390, height: 844 }]]) {
      for (const scheme of ['light', 'dark']) {
        const tag = `[${who}/${scheme}/${label}]`;
        const ctx = await browser.newContext({ viewport, colorScheme: scheme });
        try {
        const page = await login(ctx, email);
        await page.evaluate((dark) => document.documentElement.classList.toggle('dark', dark), scheme === 'dark');
        const ready = await openManagementTab(page);
        record(`${tag} the «Sistemas de gestión» tab and its actions are reachable`, ready);
        if (!ready) { await page.screenshot({ path: `${OUT}/gestion-${who}-${scheme}-${label}-debug.png`, fullPage: true }); await ctx.close(); continue; }
        if (label !== 'desktop') await page.evaluate(() => window.Alpine?.store('sidebar')?.close?.());
        await page.evaluate((dark) => document.documentElement.classList.toggle('dark', dark), scheme === 'dark');

        const tableText = await page.locator('.fi-resource-relation-manager').filter({ hasText: 'Seguridad' }).first().innerText().catch(() => '');
        record(`${tag} summary table shows OVID and no COVID`, /OVID/.test(tableText) && !/COVID/.test(tableText), tableText.replace(/\s+/g, ' ').slice(0, 120));

        let modal = await openModal(page, 'Seguridad');
        const ovid = modal.getByLabel('OVID', { exact: true });
        const secText = await modal.innerText().catch(() => '');
        record(`${tag} «Seguridad»: checkbox labelled «OVID», no «COVID»`, (await ovid.count()) === 1 && !/COVID/.test(secText), secText.replace(/\s+/g, ' ').slice(0, 140));
        let overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        record(`${tag} no page-level horizontal overflow with the modal open`, overflow <= 1, `excess=${overflow}px`);
        await page.screenshot({ path: `${OUT}/gestion-${who}-${scheme}-${label}-seguridad.png` });

        if (scheme === 'light' && label === 'desktop' && who === 'affiliate') {
          if (!(await ovid.isChecked())) await ovid.check();
          await submitModal(page, modal);
          modal = await openModal(page, 'Seguridad');
          record(`${tag} saving OVID persists (re-opened modal shows it checked)`, await modal.getByLabel('OVID', { exact: true }).isChecked().catch(() => false));
        }
        await page.keyboard.press('Escape');
        await page.waitForTimeout(600);

        modal = await openModal(page, 'Calidad');
        const iso9001 = modal.getByLabel('ISO 9001', { exact: true });
        const iso17025 = modal.getByLabel('ISO 17025', { exact: true });
        const calText = await modal.innerText().catch(() => '');
        record(`${tag} «Calidad»: checkboxes labelled «ISO 9001» and «ISO 17025»`, (await iso9001.count()) === 1 && (await iso17025.count()) === 1 && !/Iso9001|Iso17025/.test(calText), calText.replace(/\s+/g, ' ').slice(0, 140));
        overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        record(`${tag} no page-level horizontal overflow (Calidad)`, overflow <= 1, `excess=${overflow}px`);
        await page.screenshot({ path: `${OUT}/gestion-${who}-${scheme}-${label}-calidad.png` });

        if (scheme === 'light' && label === 'desktop' && who === 'admin') {
          if (!(await iso9001.isChecked())) await iso9001.check();
          await submitModal(page, modal);
          modal = await openModal(page, 'Calidad');
          record(`${tag} saving ISO 9001 persists (re-opened modal shows it checked)`, await modal.getByLabel('ISO 9001', { exact: true }).isChecked().catch(() => false));
          // The affiliate's OVID is still there: both users edit the same company row.
          await page.keyboard.press('Escape');
          await page.waitForTimeout(600);
          modal = await openModal(page, 'Seguridad');
          record(`${tag} the affiliate's OVID save is visible to the admin`, await modal.getByLabel('OVID', { exact: true }).isChecked().catch(() => false));
        }
        } catch (e) {
          record(`${tag} crashed`, false, String(e && e.message || e).split(/\r?\n/)[0]);
        }
        await ctx.close().catch(() => {});
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
