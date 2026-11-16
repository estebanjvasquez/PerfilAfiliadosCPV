// TASK-0032: E2E de CIRA Test en staging (escritorio y Pixel 5) con Playwright.
// Por consulta: ms del clic en enviar a la respuesta, tarjetas por vista, aria-pressed, cambio de vista, enlace de
// evaluación (log y sid), voto 👍 (opcional), clic en la primera empresa (ficha, opcional), desplazamiento horizontal
// y errores de JS. Cada búsqueda es uso normal: deja su fila en cira_search_log (y el voto, si se pide).
// Uso: node e2e_cira.mjs <salida.json> <dir_capturas> <prefijo> <json de casos> [ruta_playwright-core] [chrome.exe]
//   casos: [{"q":"balancin","vote":true,"perfil":true}, ...]
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const [outPath, shotDir, prefix, casesJson, pwPath, chromePath] = process.argv.slice(2);
const require = createRequire(import.meta.url);
const { chromium, devices } = require(pwPath || 'playwright-core');
const URL = 'https://pruebas.camarapetrolera.app/cira-test/';
const cases = JSON.parse(casesJson);
fs.mkdirSync(shotDir, { recursive: true });

const browser = await chromium.launch({ executablePath: chromePath || undefined });
const out = [];
for (const [dev, ctxOpts] of [['desktop', { viewport: { width: 1280, height: 900 } }], ['mobile', devices['Pixel 5']]]) {
	for (const [i, c] of cases.entries()) {
		const ctx = await browser.newContext(ctxOpts);
		const page = await ctx.newPage();
		const errors = [];
		page.on('pageerror', (e) => errors.push(String(e.message)));
		await page.goto(URL, { waitUntil: 'networkidle' });
		const ask = async (text, how) => {
			const before = await page.locator('.row.bot').count();
			const t0 = Date.now();
			await how(text);
			await page.waitForFunction((n) => document.querySelectorAll('.row.bot').length > n && !document.getElementById('loader-row'), before, { timeout: 90000 });
			return { ms: Date.now() - t0, bubble: page.locator('.row.bot .bubble').last() };
		};
		const r = { dev, q: c.q, errors };
		const { ms, bubble } = await ask(c.q, async (t) => {
			await page.fill('#input', t);
			await page.click('#send');
		});
		r.navegador_ms = ms;
		r.tipo = ((await bubble.locator('.meta-tag').textContent().catch(() => '')) || '').trim();
		r.texto = ((await bubble.innerText()) || '').replace(/\s+/g, ' ').slice(0, 160);
		const count = async () => ({
			cap: await bubble.locator('[data-vista-panel="cap"]:not([hidden]) div[role="button"]').count(),
			exp: await bubble.locator('[data-vista-panel="exp"]:not([hidden]) div[role="button"]').count(),
			pressed: (await bubble.locator('[data-vista-btn]').evaluateAll((b) => b.map((x) => x.getAttribute('aria-pressed')))).join('/'),
		});
		const grouped = (await bubble.locator('[data-vista-btn]').count()) > 0;
		if (grouped) {
			r.ini = await count();
			await bubble.locator('[data-vista-btn="exp"]').click();
			r.exp = await count();
			await bubble.locator('[data-vista-btn="cap"]').click();
			r.cap = await count();
		}
		r.eval = await bubble.locator('a[href*="evaluacion-cira"]').first().getAttribute('href').catch(() => null);
		await page.screenshot({ path: path.join(shotDir, `${prefix}_${dev}_${i}.png`), fullPage: false });
		if (c.vote && (await bubble.locator('.cira-vote button').count()) > 0) {
			const box = bubble.locator('.cira-vote').first();
			r.voto_emp = await box.getAttribute('data-emp');
			await box.locator('button').first().click();
			await page.waitForFunction((el) => /gracias|error/i.test(el.textContent || ''), await box.elementHandle(), { timeout: 20000 }).catch(() => {});
			r.voto = ((await box.locator('.cira-vote-status').textContent().catch(() => '')) || '').trim();
		}
		if (c.perfil) {
			const card = bubble.locator('[data-vista-panel="cap"]:not([hidden]) div[role="button"], [data-vista-panel="exp"]:not([hidden]) div[role="button"]').first();
			if ((await card.count()) > 0) {
				const p = await ask(null, async () => card.click());
				r.perfil_ms = p.ms;
				r.perfil_pedido = ((await page.locator('.row.user .bubble').last().textContent()) || '').trim();
				r.perfil = ((await p.bubble.innerText()) || '').replace(/\s+/g, ' ').slice(0, 140);
				await page.screenshot({ path: path.join(shotDir, `${prefix}_${dev}_${i}_perfil.png`), fullPage: false });
			}
		}
		r.scroll_horizontal = await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
		out.push(r);
		console.log(JSON.stringify(r));
		await ctx.close();
	}
}
await browser.close();
fs.writeFileSync(outPath, JSON.stringify(out, null, 1));
