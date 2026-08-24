// Versión NO interactiva (para ejecución automática vía CDP).
// Se conecta a tu Chrome real ya autenticado y captura las pantallas estables.
import { chromium } from 'playwright';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import fs from 'node:fs';

const BASE = process.env.CPV_BASE_URL || 'https://camarapetrolera.app';
const CDP = process.env.CPV_CDP || 'http://localhost:9222';
const OUT = path.join(path.dirname(fileURLToPath(import.meta.url)), 'capturas_admin');
fs.mkdirSync(OUT, { recursive: true });

const PAGES = [
  { name: '20-dashboard',           url: '/admin' },
  { name: '21-empresas-listado',    url: '/admin/empresas' },
  { name: '24-estatus-perfiles',    url: '/admin/completion-view' },
  { name: '25-tablero-gerencial',   url: '/admin/gerencia-dashboard' },
  { name: '26-roles',               url: '/admin/shield/roles' },
  { name: '27-mantenimiento-sectores', url: '/admin/sectors' },
];

async function shot(page, name) {
  const f = path.join(OUT, name + '.png');
  await page.waitForTimeout(1500);
  await page.screenshot({ path: f, fullPage: true });
  console.log('OK   ' + name);
}

const b = await chromium.connectOverCDP(CDP).catch((e) => { console.error('CONNECT_FAIL ' + e.message.split('\n')[0]); process.exit(1); });
const ctx = b.contexts()[0] ?? await b.newContext();
const page = await ctx.newPage();
await page.setViewportSize({ width: 1440, height: 900 });

await page.goto(BASE + '/admin', { waitUntil: 'domcontentloaded' }).catch(() => {});
if (page.url().includes('/login')) { console.error('NO_SESSION (Chrome no tiene sesión iniciada)'); await b.close().catch(()=>{}); process.exit(2); }

for (const p of PAGES) {
  try {
    await page.goto(BASE + p.url, { waitUntil: 'networkidle', timeout: 30000 });
    if (page.url().includes('/login')) { console.log('skip(login) ' + p.url); continue; }
    await shot(page, p.name);
  } catch (e) { console.log('skip ' + p.url + '  ' + e.message.split('\n')[0]); }
}

// Formulario de editar empresa: tomar el primer enlace de fila de "Estatus de perfiles"
try {
  await page.goto(BASE + '/admin/completion-view', { waitUntil: 'networkidle', timeout: 30000 });
  const href = await page.$$eval('a[href*="/empresas/"][href*="/edit"]', a => (a[0] && a[0].href) || null).catch(() => null);
  if (href) {
    await page.goto(href, { waitUntil: 'networkidle', timeout: 30000 });
    await shot(page, '22-empresa-editar-datos-generales');
  } else { console.log('no encontré enlace de editar empresa'); }
} catch (e) { console.log('edit skip  ' + e.message.split('\n')[0]); }

await page.close().catch(() => {});
await b.close().catch(() => {});
console.log('DONE');
