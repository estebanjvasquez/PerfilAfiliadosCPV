// Browser check of TASK-0010C (Issue #2 comment 6187768951): the new admin
// screens for category requests, in the disposable stack only. Synthetic users,
// synthetic company and one synthetic request seeded by run_tests.sh; the test
// app's mail transport is `array`, so the retry below cannot send anything out.
//
// What it measures, each with its value:
//   - a guest and the synthetic affiliate cannot reach the three admin screens;
//   - the synthetic Super Admin sees the list, the detail, the settings and the
//     help, on desktop and mobile, with no page-level horizontal overflow and
//     with the main content actually visible (opacity checked, not assumed:
//     isVisible() alone passed on a blank page in an earlier staging capture);
//   - the missing-recipient guidance and the disabled retry;
//   - after configuring a synthetic recipient, the retry confirmation names the
//     recipient and the simulated transport, and the result is shown as
//     simulated, never as sent, with a second entry in the attempt history.
//
// It is not an accessibility audit and does not replace a human looking.

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

const LIST = '/admin/solicitudes-de-categoria';
const SETTINGS = '/admin/configuracion-de-solicitudes';
const HELP = '/admin/ayuda-solicitudes-de-categoria';
const LIMITS = '/admin/taxonomy-selection-settings-page';
const SYNTH_NEED = 'ZZZ inspeccion sintetica de recipientes a presion';
const SYNTH_RECIPIENT = 'camara-ux@example.test';
const FORBIDDEN = ['/var/www', 'vendor/laravel', 'Stack trace', 'SQLSTATE', 'MAIL_', 'password'];

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

async function open(page, path) {
  const resp = await page.goto(BASE + path, { waitUntil: 'networkidle' }).catch(() => null);
  await page.waitForTimeout(800);
  return resp;
}

// Content really shown: main container opaque, given element has a box, page does not overflow.
async function shown(page, label, locator) {
  const opacity = await page.evaluate(() => {
    const el = document.querySelector('.fi-main-ctn') || document.querySelector('main');
    return el ? getComputedStyle(el).opacity : 'missing';
  });
  const visible = await locator.first().isVisible().catch(() => false);
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  record(`${label}: content visible`, visible && opacity === '1', `opacity=${opacity} visible=${visible}`);
  record(`${label}: no page-level horizontal overflow`, overflow <= 1, `excess=${overflow}px`);
  const text = await page.evaluate(() => document.body.innerText);
  const leaked = FORBIDDEN.filter((f) => text.includes(f));
  record(`${label}: no internal strings`, leaked.length === 0, leaked.join(', ') || 'none');
}

try {
  // 1. GUEST
  {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();
    for (const path of [LIST, SETTINGS, HELP]) {
      await page.goto(BASE + path, { waitUntil: 'domcontentloaded' });
      record(`guest is sent to login from ${path}`, /\/admin\/login/.test(page.url()), page.url().replace(BASE, ''));
    }
    await ctx.close();
  }

  // 2. AFFILIATE: forbidden, and not in the navigation
  {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await login(ctx, AFFILIATE);
    record('synthetic affiliate can log in', !/\/admin\/login/.test(page.url()), page.url().replace(BASE, ''));
    const nav = await page.evaluate(() => document.querySelector('.fi-sidebar')?.textContent || '');
    record('affiliate sidebar was actually read', nav.includes('Manual de usuario'), `chars=${nav.length}`);
    record('affiliate navigation has no "Solicitudes de categoría"', !nav.includes('Solicitudes de categoría'));
    for (const path of [LIST, SETTINGS, HELP]) {
      const resp = await open(page, path);
      const body = await page.evaluate(() => document.body.innerText);
      record(`affiliate gets 403 on ${path}`, resp?.status() === 403, `status=${resp?.status()}`);
      record(`affiliate sees no request content on ${path}`, !body.includes(SYNTH_NEED));
    }
    await ctx.close();
  }

  // 3. SUPER ADMIN, MOBILE FIRST (state as seeded: no recipient, failed request)
  let requestPath = null;
  {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const page = await login(ctx, ADMIN);
    record('mobile: synthetic super admin can log in', !/\/admin\/login/.test(page.url()));
    await page.evaluate(() => window.Alpine?.store('sidebar')?.close?.());

    await open(page, LIST);
    await shown(page, 'mobile list', page.getByText(SYNTH_NEED));
    record('mobile list: missing-recipient warning shown',
      await page.getByText('No hay correo responsable configurado', { exact: false }).first().isVisible());
    await page.screenshot({ path: `${OUT}/0010c-01-list-mobile.png`, fullPage: false });

    const href = await page.locator(`a[href*="${LIST}/"]`).first().getAttribute('href').catch(() => null);
    requestPath = href ? new URL(href, BASE).pathname : null;
    record('a request detail link exists in the list', !!requestPath, requestPath || 'none');

    if (requestPath) {
      await open(page, requestPath);
      await shown(page, 'mobile detail', page.getByText(SYNTH_NEED));
      const retry = page.getByRole('button', { name: 'Reintentar aviso' }).first();
      record('mobile detail: retry is visible and disabled without recipient',
        (await retry.isVisible()) && (await retry.isDisabled()), `disabled=${await retry.isDisabled().catch(() => '?')}`);
      await page.screenshot({ path: `${OUT}/0010c-02-detail-top-mobile.png`, fullPage: false });
      await page.getByText('Aviso a la Cámara').first().scrollIntoViewIfNeeded();
      await page.waitForTimeout(300);
      await page.screenshot({ path: `${OUT}/0010c-03-detail-notice-mobile.png`, fullPage: false });
    }

    await open(page, SETTINGS);
    await shown(page, 'mobile settings', page.getByText('Correo responsable de solicitudes de categorías'));
    await page.screenshot({ path: `${OUT}/0010c-04-settings-mobile.png`, fullPage: false });

    await open(page, HELP);
    await shown(page, 'mobile help', page.getByText('Dónde están las solicitudes').first());
    await page.screenshot({ path: `${OUT}/0010c-05-help-mobile.png`, fullPage: false });
    await ctx.close();
  }

  // 4. SUPER ADMIN, DESKTOP: configure a synthetic recipient, retry, see "simulated"
  {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await login(ctx, ADMIN);
    const nav = await page.evaluate(() => document.querySelector('.fi-sidebar')?.textContent || '');
    record('desktop navigation shows "Solicitudes de categoría"', nav.includes('Solicitudes de categoría'));
    record('desktop navigation shows "Configuración de solicitudes"', nav.includes('Configuración de solicitudes'));

    await open(page, LIST);
    await shown(page, 'desktop list', page.getByText(SYNTH_NEED));
    record('desktop list: failed badge shown in the table row', await page.locator('.fi-ta-content').getByText('Falló', { exact: true }).first().isVisible());
    await page.screenshot({ path: `${OUT}/0010c-06-list-desktop.png`, fullPage: false });

    await open(page, LIMITS);
    record('limits page links to "Configuración de solicitudes" instead of a second field',
      (await page.getByRole('link', { name: 'Configuración de solicitudes' }).count()) > 0
        && (await page.locator('input[id*="category_request_recipient_email"]').count()) === 0);

    await open(page, SETTINGS);
    await shown(page, 'desktop settings', page.getByText('Correo responsable de solicitudes de categorías'));
    record('settings: transport shown as simulated', await page.getByText('Simulado (modo', { exact: false }).first().isVisible());
    await page.locator('input[id*="category_request_recipient_email"]').fill(SYNTH_RECIPIENT);
    await page.getByRole('button', { name: 'Guardar' }).click();
    await page.waitForTimeout(1500);
    record('settings: saved notification', await page.getByText('Configuración guardada').first().isVisible().catch(() => false));
    await page.screenshot({ path: `${OUT}/0010c-07-settings-saved-desktop.png`, fullPage: false });

    if (requestPath) {
      await open(page, requestPath);
      await shown(page, 'desktop detail', page.getByText(SYNTH_NEED));
      record('detail: still failed after saving the recipient (no automatic resend)',
        await page.getByText('Falló', { exact: true }).first().isVisible());
      const retry = page.getByRole('button', { name: 'Reintentar aviso' }).first();
      record('detail: retry enabled once a recipient exists', !(await retry.isDisabled()));
      await retry.click();
      await page.waitForTimeout(800);
      const modal = page.locator('.fi-modal-window').filter({ hasText: 'Reintentar el aviso' }).first();
      const modalText = (await modal.innerText().catch(() => '')) || '';
      record('confirmation names the recipient', modalText.includes(SYNTH_RECIPIENT));
      record('confirmation explains the simulated transport', modalText.includes('Simulado'));
      record('confirmation says no new request is created', modalText.includes('No se crea otra solicitud'));
      await page.screenshot({ path: `${OUT}/0010c-08-retry-confirm-desktop.png`, fullPage: false });
      await modal.getByRole('button', { name: 'Reintentar ahora' }).click();
      await page.waitForTimeout(2000);
      record('result notified as simulated, not as sent',
        await page.getByText('Aviso simulado: no se envió ningún correo').first().isVisible().catch(() => false));
      await open(page, requestPath);
      record('detail now shows the simulated status',
        await page.getByText('Simulado: registrado en el servidor, no se envió').first().isVisible());
      record('no "Aceptado por el servidor de correo" shown for a simulated notice',
        (await page.getByText('Aceptado por el servidor de correo').count()) === 0);
      const history = await page.getByText('Reintento manual').count();
      record('history shows the manual retry', history > 0, `entries=${history}`);
      await page.getByText('Historial de avisos').first().scrollIntoViewIfNeeded();
      await page.waitForTimeout(300);
      await page.screenshot({ path: `${OUT}/0010c-09-detail-after-retry-desktop.png`, fullPage: false });
    }

    await open(page, HELP);
    await shown(page, 'desktop help', page.getByText('Reintentar un aviso').first());
    await page.screenshot({ path: `${OUT}/0010c-10-help-desktop.png`, fullPage: false });
    await ctx.close();
  }
} catch (e) {
  record('script completed without an exception', false, String(e).slice(0, 300));
} finally {
  await browser.close();
}

const failed = results.filter((r) => !r.pass).length;
writeFileSync(`${OUT}/ux_results.json`, JSON.stringify({ results, passed: results.length - failed, failed }, null, 2));
console.log(`passed=${results.length - failed} failed=${failed}`);
process.exit(failed ? 1 : 0);
