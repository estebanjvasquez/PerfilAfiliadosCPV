// Browser check of CPV-VERIFICATION-FLOW-AND-COMPLETION (Issue #2 comment 6215451160).
//
// A. An UNVERIFIED synthetic user (ux_seed_verification.php) logs in. They:
//    - land on «Verifique su correo electrónico», whose wording does not claim a mail was sent (the stack
//      uses the `array` transport, as staging uses `log`);
//    - are sent back there from protected pages;
//    - get «Pedido registrado» from the resend link, which is throttled on the third click;
//    - can log out.
//    Then they open the signed link from the seed: the panel opens and gives no extra access.
// B. The synthetic affiliate toggles «No Aplica» in Sistemas de gestión. After a reload the badge follows,
//    and the saved ISO 9001 is still there.
// Desktop light does the actions; mobile dark shows the verification page and repeats B.
//
// The verification link is read from /out/ux_seed.log and never printed.

import { chromium } from 'playwright';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';

const BASE = process.env.BASE_URL;
const AFFILIATE = process.env.UX_EMAIL;
const PASSWORD = process.env.UX_PASSWORD;
const UNVERIFIED = 'zzz-ux-unverified@example.test';
const OUT = '/out';
if (!BASE || !AFFILIATE || !PASSWORD) { console.error('env missing'); process.exit(2); }
mkdirSync(OUT, { recursive: true });
const LINK = (readFileSync(`${OUT}/ux_seed.log`, 'utf8').match(/^VERIFY_LINK=(\S+)$/m) || [])[1];

const results = [];
const record = (name, pass, detail) => {
  results.push({ name, pass: !!pass, detail });
  console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail !== undefined ? '  :: ' + detail : ''}`);
};
const step = async (name, fn) => { try { await fn(); } catch (e) { record(`${name} (crashed)`, false, String(e && e.message || e).split(/\r?\n/)[0]); } };
const path = (page) => new URL(page.url()).pathname;
const PROMPT = '/admin/email-verification/prompt';

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

const toast = async (page) => (await page.locator('.fi-no-notification').allInnerTexts().catch(() => [])).join(' | ').replace(/\s+/g, ' ');

try {
  record('seed produced a verification link (not printed)', !!LINK);

  // ------------------------------------------------------------------ A0. unverified user, mobile dark: layout only
  {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, colorScheme: 'dark' });
    await ctx.addInitScript(() => { try { localStorage.setItem('theme', 'dark'); } catch (e) {} });
    const page = await login(ctx, UNVERIFIED);
    await step('[unverified/dark/mobile] prompt', async () => {
      record('[unverified/dark/mobile] lands on the verification page', path(page) === PROMPT, path(page));
      const ov = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      record('[unverified/dark/mobile] no horizontal overflow', ov <= 1, `excess=${ov}px`);
      await page.screenshot({ path: `${OUT}/ver-prompt-dark-mobile.png`, fullPage: true });
    });
    await ctx.close();
  }

  // ------------------------------------------------------------------ A. unverified user, desktop light
  {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: 'light' });
    let page = await login(ctx, UNVERIFIED);
    const tag = '[unverified/light/desktop]';

    await step(`${tag} prompt`, async () => {
      record(`${tag} after login lands on the verification page (no 500)`, path(page) === PROMPT, path(page));
      const text = (await page.locator('main, body').first().innerText()).replace(/\s+/g, ' ');
      record(`${tag} heading «Verifique su correo electrónico»`, /Verifique su correo electrónico/.test(text));
      record(`${tag} honest wording: mail is not being sent, contact the Cámara`, /todavía no envía correos/.test(text) && /comuníquese con la Cámara/.test(text), text.slice(0, 200));
      record(`${tag} does not claim a mail was sent`, !/Hemos enviado|Le enviamos un correo/.test(text));
      await page.screenshot({ path: `${OUT}/ver-prompt-light-desktop.png` });
    });

    await step(`${tag} protected pages`, async () => {
      for (const p of ['/admin', '/admin/empresas', '/admin/manual-de-usuario', '/admin/mapa-de-la-taxonomia']) {
        const resp = await page.goto(BASE + p, { waitUntil: 'domcontentloaded' });
        record(`${tag} ${p} → verification page`, path(page) === PROMPT && resp.status() === 200, `${resp.status()} ${path(page)}`);
      }
    });

    await step(`${tag} resend`, async () => {
      const link = page.getByRole('button', { name: 'Pedir un nuevo enlace' }).or(page.getByText('Pedir un nuevo enlace')).first();
      for (let i = 1; i <= 3; i++) {
        await link.click();
        await page.waitForTimeout(1500);
        const t = await toast(page);
        if (i < 3) record(`${tag} resend #${i}: «Pedido registrado», no delivery promised`, /Pedido registrado/.test(t) && /no llegará a su buzón/.test(t), t.slice(0, 160));
        else record(`${tag} resend #3 is throttled`, /Demasiados intentos/.test(t), t.slice(0, 160));
        await page.locator('.fi-no-notification button[type="button"]').first().click().catch(() => {});
      }
      await page.screenshot({ path: `${OUT}/ver-resend-throttled.png` });
    });

    await step(`${tag} logout`, async () => {
      await page.getByRole('button', { name: 'Cerrar sesión' }).click();
      await page.waitForLoadState('networkidle').catch(() => {});
      record(`${tag} «Cerrar sesión» goes to the login page`, path(page) === '/admin/login', path(page));
    });

    await step(`${tag} verification link`, async () => {
      page = await login(ctx, UNVERIFIED);
      await page.goto(LINK, { waitUntil: 'networkidle' });
      record(`${tag} the signed link leaves the verification page and opens the panel`, path(page).startsWith('/admin') && path(page) !== PROMPT && path(page) !== '/admin/login', path(page));
      const resp = await page.goto(BASE + '/admin/empresas', { waitUntil: 'networkidle' });
      const text = (await page.locator('main').first().innerText().catch(() => '')).replace(/\s+/g, ' ');
      record(`${tag} after verifying: Empresas opens (200)`, resp.status() === 200 && path(page) === '/admin/empresas', `${resp.status()} ${path(page)}`);
      record(`${tag} verifying gives no extra access: no company listed`, !/ZZZ UX EMPRESA/i.test(text), text.slice(0, 160));
      await page.screenshot({ path: `${OUT}/ver-after-link.png` });
    });
    await ctx.close();
  }

  // ------------------------------------------------------------------ B. No Aplica, affiliate
  for (const [label, viewport, scheme] of [['desktop', { width: 1440, height: 900 }, 'light'], ['mobile', { width: 390, height: 844 }, 'dark']]) {
    const tag = `[affiliate/${scheme}/${label}]`;
    const ctx = await browser.newContext({ viewport, colorScheme: scheme });
    await ctx.addInitScript((s) => { try { localStorage.setItem('theme', s); } catch (e) {} }, scheme);
    const page = await login(ctx, AFFILIATE);
    await step(`${tag} No Aplica`, async () => {
      await page.goto(BASE + '/admin/empresas', { waitUntil: 'networkidle' });
      const edit = page.locator('a[href*="/admin/empresas/"][href$="/edit"]').first();
      await edit.waitFor({ timeout: 20000 });
      const editUrl = new URL(await edit.getAttribute('href'), BASE).href;
      const openTab = async () => {
        await page.goto(editUrl, { waitUntil: 'networkidle' });
        const tab = page.getByRole('tab', { name: /Sistemas de gestión/ }).first();
        for (let i = 0; i < 6 && !(await tab.isVisible().catch(() => false)); i++) { await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight)); await page.waitForTimeout(500); }
        await tab.click();
        await page.waitForTimeout(1500);
      };
      const rm = () => page.locator('.fi-resource-relation-manager').first();
      const badge = async () => ((await rm().getByRole('button', { name: /Completado:/ }).first().innerText()).match(/(\d+)%/) || [])[1];
      const setNoAplica = async (on) => {
        await rm().getByRole('button', { name: 'No Aplica', exact: true }).first().click();
        const modal = page.locator('.fi-modal-window:visible').last();
        await modal.waitFor({ state: 'visible' });
        const sw = modal.getByRole('switch').first();
        if ((await sw.getAttribute('aria-checked') === 'true') !== on) await sw.click();
        await modal.getByRole('button', { name: 'Guardar' }).click();
        await page.waitForTimeout(1500);
      };

      await openTab();
      const start = await badge();
      const before = (await rm().innerText()).replace(/\s+/g, ' ');
      record(`${tag} starts with ISO 9001 saved and a partial badge`, /ISO 9001/.test(before) && Number(start) < 100, `badge=${start}%`);
      await setNoAplica(true);
      await openTab();
      const on = await badge();
      record(`${tag} after «No Aplica» on and a reload: badge 100%`, on === '100', `badge=${on}%`);
      record(`${tag} the saved ISO 9001 is still there`, /ISO 9001/.test((await rm().innerText())));
      await page.screenshot({ path: `${OUT}/ver-noaplica-on-${scheme}-${label}.png` });
      await setNoAplica(false);
      await openTab();
      const off = await badge();
      record(`${tag} after «No Aplica» off and a reload: badge back to ${start}%`, off === start, `badge=${off}%`);
      record(`${tag} the saved ISO 9001 is still there after turning it off`, /ISO 9001/.test((await rm().innerText())));
      const ov = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      record(`${tag} no horizontal overflow`, ov <= 1, `excess=${ov}px`);
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
