// Capturas de escritorio y movil despues del despliegue 0010 (instruccion 6174797031, punto 7).
// - login: pagina publica real de staging;
// - manual: el navegador pide la URL REAL del manual en staging, pero ese unico documento se
//   responde con el HTML que renderizo la app autenticada (smoke_authenticated_readonly.php), con
//   nombre/correo reemplazados. Todo lo demas (CSS, JS de Filament/Livewire/Alpine) llega del
//   origen real, asi que el layout es el desplegado. Desde file:// los modulos JS se bloquean por
//   origen nulo y Alpine no arranca: la primera version de este script fotografio una pagina
//   vacia y la dio por buena porque contaba nodos ocultos. Ahora se exige VISIBILIDAD.
import { chromium } from 'playwright';
import fs from 'node:fs';

const BASE = 'https://pruebas.camarapetrolera.app';
const out = '/out';
const viewports = { desktop: { width: 1366, height: 900 }, mobile: { width: 390, height: 844 } };
const results = [];
const record = (name, ok, detail = '') => results.push({ check: name, ok, detail });

const browser = await chromium.launch();
for (const [vp, size] of Object.entries(viewports)) {
  const ctx = await browser.newContext({ viewport: size, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  const bad = [];
  page.on('response', (r) => { if (r.status() >= 500) bad.push(`${r.status()} ${new URL(r.url()).pathname}`); });

  const resp = await page.goto(`${BASE}/admin/login`, { waitUntil: 'load', timeout: 60000 }); await page.waitForTimeout(2000);
  record(`${vp}: login 200`, resp.status() === 200, `status=${resp.status()}`);
  const emailVisible = await page.locator('input[type="email"]').first().isVisible();
  record(`${vp}: login muestra el campo de correo`, emailVisible);
  const overflowLogin = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  record(`${vp}: login sin scroll horizontal`, overflowLogin <= 1, `exceso=${overflowLogin}px`);
  await page.screenshot({ path: `${out}/login_${vp}.png`, fullPage: false });

  const manualHtml = fs.readFileSync('/in/manual_redacted.html', 'utf8').replace(/<base href="[^"]*">/, '');
  const manualUrl = `${BASE}/admin/manual-de-usuario`;
  await page.route(manualUrl, (route) => route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: manualHtml }));
  await page.goto(manualUrl, { waitUntil: 'load', timeout: 60000 }); await page.waitForTimeout(3000);
  const h1 = (await page.locator('h1').first().textContent() || '').trim();
  record(`${vp}: manual titulo visible`, h1.includes('Manual de usuario') && await page.locator('h1').first().isVisible(), `h1=${h1}`);
  const bodyText = (await page.locator('main').first().innerText().catch(() => '')).length;
  record(`${vp}: manual con contenido visible en main`, bodyText > 2000, `caracteres_visibles=${bodyText}`);
  await page.evaluate(() => window.scrollTo(0, 0));
  const cssOk = await page.evaluate(() => [...document.styleSheets].some((s) => { try { return s.cssRules.length > 50; } catch { return true; } }));
  record(`${vp}: manual con CSS desplegado cargado`, cssOk);
  const labels = JSON.parse(fs.readFileSync('/in/labels.json', 'utf8'));
  for (const [k, text] of Object.entries(labels)) {
    const all = page.getByText(text, { exact: false });
    const n = await all.count();
    let visibles = 0;
    for (let i = 0; i < n; i++) {
      await all.nth(i).scrollIntoViewIfNeeded({ timeout: 3000 }).catch(() => {});
      if (await all.nth(i).isVisible()) visibles++;
    }
    record(`${vp}: manual muestra VISIBLE ${k}`, visibles > 0, `apariciones=${n} visibles=${visibles}`);
  }
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  record(`${vp}: manual sin scroll horizontal de pagina`, overflow <= 1, `exceso=${overflow}px`);
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(500);
  await page.screenshot({ path: `${out}/manual_${vp}.png`, fullPage: false });
  const anchor = page.getByText(labels.SOLICITUD_MODAL_HEADING, { exact: false }).first();
  await anchor.scrollIntoViewIfNeeded();
  await page.screenshot({ path: `${out}/manual_seccion_solicitud_${vp}.png`, fullPage: false });

  record(`${vp}: ninguna respuesta 5xx`, bad.length === 0, bad.join(', '));
  await ctx.close();
}
await browser.close();

const failed = results.filter((r) => !r.ok).length;
fs.writeFileSync(`${out}/render_results.json`, JSON.stringify({ results, failed }, null, 2));
for (const r of results) console.log(`${r.ok ? 'PASS' : 'FAIL'} ${r.check}${r.detail ? ` [${r.detail}]` : ''}`);
console.log(`passed=${results.length - failed} failed=${failed}`);
process.exit(failed ? 1 : 0);
