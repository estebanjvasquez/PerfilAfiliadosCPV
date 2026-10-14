import { chromium } from 'playwright';
import fs from 'node:fs';
const BASE = 'https://pruebas.camarapetrolera.app';
const html = fs.readFileSync('/in/manual_redacted.html', 'utf8').replace(/<base href="[^"]*">/, '');
const browser = await chromium.launch();
const page = await (await browser.newContext({ viewport: { width: 390, height: 844 } })).newPage();
const log = [];
page.on('console', (m) => { if (['error', 'warning'].includes(m.type())) log.push(`console.${m.type()}: ${m.text().slice(0, 200)}`); });
page.on('pageerror', (e) => log.push(`pageerror: ${String(e).slice(0, 200)}`));
page.on('requestfailed', (r) => log.push(`requestfailed: ${new URL(r.url()).pathname} ${r.failure()?.errorText}`));
page.on('response', (r) => { if (r.status() >= 400) log.push(`http ${r.status()}: ${new URL(r.url()).pathname}`); });
const url = `${BASE}/admin/manual-de-usuario`;
await page.route(url, (route) => route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: html }));
await page.goto(url, { waitUntil: 'load', timeout: 60000 });
await page.waitForTimeout(4000);
const info = await page.evaluate(() => {
  const pick = (sel) => {
    const el = document.querySelector(sel);
    if (!el) return `${sel}: (ausente)`;
    const cs = getComputedStyle(el); const r = el.getBoundingClientRect();
    return `${sel}: display=${cs.display} visibility=${cs.visibility} opacity=${cs.opacity} rect=${Math.round(r.x)},${Math.round(r.y)},${Math.round(r.width)}x${Math.round(r.height)} cloak=${el.hasAttribute('x-cloak')}`;
  };
  const center = document.elementFromPoint(window.innerWidth / 2, window.innerHeight / 2);
  return [
    `html.class=${document.documentElement.className}`,
    `body.class=${document.body.className}`,
    `Alpine=${typeof window.Alpine} Livewire=${typeof window.Livewire}`,
    pick('.fi-layout'), pick('.fi-main-ctn'), pick('.fi-main'), pick('main'), pick('.cpv-manual'), pick('h1'),
    `x-cloak restantes=${document.querySelectorAll('[x-cloak]').length}`,
    `centro=${center ? center.tagName + '.' + center.className.toString().slice(0, 80) : 'null'}`,
  ];
});
console.log(info.join('\n'));
console.log(log.slice(0, 30).join('\n'));
await browser.close();
