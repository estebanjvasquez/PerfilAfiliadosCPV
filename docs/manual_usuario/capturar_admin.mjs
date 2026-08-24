// ============================================================================
//  Captura de pantallas del PANEL DE ADMINISTRADOR — Perfil de Afiliados (CPV)
//  MODO CDP: se conecta a TU Chrome real (ya con sesión iniciada), donde la
//  verificación anti-bot (Cloudflare Turnstile) sí pasa. Playwright NO abre su
//  propio navegador ni inicia sesión: solo navega y captura sobre tu sesión.
//
//  ── PASO 1: abre tu Chrome real con depuración remota ──────────────────────
//  Cierra Chrome si te lo pide y ejecuta en PowerShell (una sola línea):
//
//    & "C:\Program Files\Google\Chrome\Application\chrome.exe" `
//        --remote-debugging-port=9222 `
//        --user-data-dir="$env:TEMP\cpv-chrome" `
//        "https://camarapetrolera.app/admin/login"
//
//  (Si Chrome está en otra ruta, ajústala. El --user-data-dir es un perfil
//   aparte y limpio, para no tocar tu perfil normal.)
//
//  ── PASO 2: en esa ventana de Chrome, INICIA SESIÓN normalmente ────────────
//  (correo, contraseña y Turnstile — como es Chrome real, la verificación pasa).
//
//  ── PASO 3: ejecuta este script ────────────────────────────────────────────
//    node docs/manual_usuario/capturar_admin.mjs
//  (Si dice "Cannot find module 'playwright'":  npm i -D playwright)
//
//  Privacidad: estas pantallas muestran datos reales de TODAS las empresas.
//  Tapa los datos sensibles antes de publicar el manual.
// ============================================================================

import { chromium } from 'playwright';
import readline from 'node:readline';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import fs from 'node:fs';

const BASE = process.env.CPV_BASE_URL || 'https://camarapetrolera.app';
const CDP = process.env.CPV_CDP || 'http://localhost:9222';
const OUT = path.join(path.dirname(fileURLToPath(import.meta.url)), 'capturas_admin');
fs.mkdirSync(OUT, { recursive: true });

const PAGES = [
  { name: '20-dashboard',         url: '/admin' },
  { name: '21-empresas-listado',  url: '/admin/empresas' },
  { name: '24-estatus-perfiles',  url: '/admin/completion-view' },
  { name: '25-tablero-gerencial', url: '/admin/gerencia-dashboard' },
  { name: '26-roles',             url: '/admin/shield/roles' },
];

const ask = (q) => new Promise((res) => {
  const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
  rl.question(q, (a) => { rl.close(); res(a.trim()); });
});

async function shot(page, name) {
  const file = path.join(OUT, `${name}.png`);
  await page.waitForTimeout(1200); // deja asentar gráficos/tablas
  await page.screenshot({ path: file, fullPage: true });
  console.log('  ✔ ' + path.relative(process.cwd(), file));
}

(async () => {
  let browser;
  try {
    browser = await chromium.connectOverCDP(CDP);
  } catch (e) {
    console.error('\n✖ No pude conectar a Chrome en ' + CDP + '.');
    console.error('  Verifica que abriste Chrome con --remote-debugging-port=9222 (ver PASO 1 arriba).');
    console.error('  Detalle: ' + e.message.split('\n')[0] + '\n');
    process.exit(1);
  }

  const context = browser.contexts()[0] ?? await browser.newContext();
  const page = await context.newPage();
  await page.setViewportSize({ width: 1440, height: 900 });

  // Verifica que la sesión esté iniciada (si redirige a /login, avisa).
  await page.goto(BASE + '/admin', { waitUntil: 'domcontentloaded' }).catch(() => {});
  if (page.url().includes('/login')) {
    console.log('\n⚠ Parece que aún NO has iniciado sesión en Chrome.');
    console.log('  Inicia sesión en la ventana de Chrome y vuelve aquí.');
    await ask('  Presiona ENTER cuando ya estés DENTRO del panel… ');
  }

  console.log('\nCapturando pantallas de URL estable:');
  for (const p of PAGES) {
    try {
      await page.goto(BASE + p.url, { waitUntil: 'networkidle', timeout: 30000 });
      if (page.url().includes('/login')) { console.log('  ⚠ ' + p.url + ' redirigió a login (sin sesión)'); continue; }
      await shot(page, p.name);
    } catch (e) {
      console.log('  ⚠ omitida ' + p.url + ' (' + e.message.split('\n')[0] + ')');
    }
  }

  // Modo manual: navega tú (en la ventana que controla Playwright) a cualquier
  // otra pantalla —editar una empresa, un catálogo— y captúrala con un nombre.
  console.log('\nModo manual: navega a la pantalla que quieras y captúrala.');
  console.log('Sugeridas: 22-empresa-editar-datos-generales, 23-empresa-usuarios, 27-mantenimiento-sectores');
  while (true) {
    const name = await ask("\nNombre de archivo para capturar (sin .png), o 'fin' para terminar: ");
    if (!name || name.toLowerCase() === 'fin') break;
    try { await shot(page, name); } catch (e) { console.log('  ⚠ ' + e.message.split('\n')[0]); }
  }

  await page.close().catch(() => {});
  await browser.close().catch(() => {}); // solo desconecta; tu Chrome sigue abierto
  console.log('\nListo. Capturas en: ' + OUT);
  console.log('Avísame y regenero el Word del administrador con estas imágenes.');
})();
