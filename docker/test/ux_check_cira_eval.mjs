// TASK-0027 (Issue #2 `6852945146`): end-to-end browser check of the CIRA evaluation module, desktop and phone.
//
// CIRA Test (/cira-test/) -> search -> 👍 -> «Evaluar esta búsqueda» (/admin/evaluacion-cira/evaluar) -> login -> evaluate -> save draft -> reopen from
// «Mis pruebas» -> submit -> internal panel (evaluation, report) -> guide with the 8 tests.
//
// Nothing external is reached: the n8n chat webhook and the vote webhook are answered HERE (page.route), with cards
// built like the real ones (a .cira-vote span carrying data-log / data-emp). The search itself was seeded by
// ux_seed_cira_eval.php exactly as the CIRA flow logs it, owned by a fixed browser session (crypto.randomUUID is pinned
// so CIRA Test uses that session). The check asserts on the page; it never edits the database.
import { chromium, devices } from 'playwright';
import { writeFileSync, readFileSync, mkdirSync } from 'node:fs';

const BASE = process.env.BASE_URL;
const EVALUATOR = process.env.UX_EMAIL;
const ADMIN = process.env.UX_ADMIN_EMAIL;
const PASSWORD = process.env.UX_PASSWORD;
const OUT = '/out';
if (!BASE || !EVALUATOR || !ADMIN || !PASSWORD) { console.error('BASE_URL, UX_EMAIL, UX_ADMIN_EMAIL and UX_PASSWORD are required'); process.exit(2); }
mkdirSync(OUT, { recursive: true });

const seed = readFileSync(`${OUT}/ux_seed.log`, 'utf8');
const pick = (k) => (seed.match(new RegExp(`^${k}=(.+)$`, 'm')) || [])[1];
const LOG_ID = pick('CIRA_LOG_ID'); const SID = pick('CIRA_SID'); const EMPRESAS = (pick('CIRA_EMPRESAS') || '').split(',');
const UUID = SID ? SID.replace(/^test-/, '') : null;
const FORBIDDEN = ['/var/www', 'vendor/laravel', 'Stack trace', 'SQLSTATE', 'Whoops'];

const results = [];
const record = (name, pass, detail) => { results.push({ name, pass: !!pass, detail }); console.log(`${pass ? 'PASS' : 'FAIL'}  ${name}${detail !== undefined ? '  :: ' + detail : ''}`); };
record('seed provided a search, a session and companies', LOG_ID && SID && EMPRESAS.length === 3, `log=${LOG_ID}`);

const cards = () => `Encontré 3 empresas:<br>` + EMPRESAS.map((id, i) =>
  `<div class="card"><b>${i + 1}. ZZZ UX CIRA ${['ALFA', 'BETA', 'GAMMA'][i]}</b> <span class="cira-vote" data-log="${LOG_ID}" data-emp="${id}">` +
  `<button type="button" onclick="ciraVote(this,1)">👍</button><button type="button" onclick="ciraVote(this,-1)">👎</button></span></div>`).join('');

async function stubCira(page, calls) {
  // Every request to the n8n host is answered here: the chat (chatInput) and the vote (search_log_id).
  await page.route(/contaboserver\.net|\/webhook\//, async (route) => {
    const body = route.request().postData() || '';
    if (body.includes('search_log_id')) { calls.votes.push(JSON.parse(body)); return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) }); }
    if (body.includes('chatInput')) { calls.chats.push(JSON.parse(body)); return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ output: cards(), type: 'search' }) }); }
    return route.fulfill({ status: 204, body: '' });
  });
  await page.route(/fonts\.(googleapis|gstatic)\.com/, (r) => r.fulfill({ status: 204, body: '' }));
}

async function login(page, email) {
  await page.fill('input[type="email"], input[id$="email"]', email);
  await page.fill('input[type="password"], input[id$="password"]', PASSWORD);
  await Promise.all([page.waitForLoadState('networkidle').catch(() => {}), page.click('button[type="submit"]')]);
  await page.waitForTimeout(1200);
}

async function clean(page, label) {
  const html = await page.content();
  const bad = FORBIDDEN.filter((f) => html.includes(f));
  record(`${label}: no internal error text`, bad.length === 0, bad.join(',') || undefined);
}

async function noHorizontalScroll(page, label) {
  const ok = await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2);
  record(`${label}: no horizontal scroll`, ok);
}

const browser = await chromium.launch({ args: ['--no-sandbox'] });

for (const [vp, opts] of [['desktop', { viewport: { width: 1366, height: 900 } }], ['phone', { ...devices['Pixel 5'] }]]) {
  const test = vp === 'desktop' ? 'CPV-01' : 'CPV-06';
  const ctx = await browser.newContext({ ...opts, locale: 'es-VE' });
  await ctx.addInitScript((uuid) => { try { Object.defineProperty(crypto, 'randomUUID', { value: () => uuid }); } catch (e) { crypto.randomUUID = () => uuid; } }, UUID);
  const page = await ctx.newPage();
  const calls = { chats: [], votes: [] };
  await stubCira(page, calls);

  // 1. CIRA Test: search, results, vote.
  await page.goto(BASE + '/cira-test/index.html', { waitUntil: 'domcontentloaded' });
  record(`${vp}: CIRA Test shows «Guía de evaluación» and «Mis pruebas»`, (await page.locator('[data-cira-eval-guide]').count()) === 1 && (await page.locator('[data-cira-eval-home]').count()) === 1);
  await page.fill('#input', 'ZZZ UX mantenimiento de bombas');
  await page.click('#send');
  await page.waitForSelector('.cira-vote', { timeout: 15000 }).catch(() => {});
  record(`${vp}: the search reached the (stubbed) CIRA webhook with this page session`, calls.chats.length === 1 && calls.chats[0].sessionId === SID, calls.chats[0]?.sessionId);
  await page.locator('.cira-vote button').first().click();
  await page.waitForTimeout(800);
  record(`${vp}: 👍 sent with the search id and session`, calls.votes.length === 1 && String(calls.votes[0].search_log_id) === LOG_ID && calls.votes[0].voter_ref === SID);
  const link = page.locator('[data-cira-eval-link="log"]').last();
  const href = await link.getAttribute('href').catch(() => null);
  record(`${vp}: «Evaluar esta búsqueda» carries the search id and the session`, href === `/admin/evaluacion-cira/evaluar?log=${LOG_ID}&sid=${encodeURIComponent(SID)}`, href);
  await page.screenshot({ path: `${OUT}/${vp}_1_cira_test.png`, fullPage: true });

  // 2. Evaluate: the link goes through login (first time) and lands on the form with the search.
  await link.click();
  await page.waitForLoadState('networkidle').catch(() => {});
  if (page.url().includes('/login')) { await login(page, EVALUATOR); }
  if (!page.url().includes('/admin/')) {
    const txt = await page.evaluate(() => document.body.innerText).catch(() => '');
    const html = await page.content();
    const msgs = [...html.matchAll(/"(?:message|class)":"((?:[^"\\]|\\.){0,300})"/g)].map((m) => m[1]).filter((v, i, a) => a.indexOf(v) === i).slice(0, 8);
    const login = await page.request.get(BASE + '/admin/login').then((r) => r.status()).catch((e) => 'err ' + e.message);
    record(`${vp}: diagnostic of the unexpected page`, false, `${await page.title()} | /admin/login=${login} | ${msgs.join(' || ')}`);
  }
  record(`${vp}: landed on the evaluation form after login`, page.url().includes('/admin/evaluacion-cira/evaluar') && page.url().includes(`log=${LOG_ID}`), page.url().replace(BASE, ''));
  const snap = await page.locator('[data-cira-snapshot]').innerText().catch(() => '');
  record(`${vp}: the form shows the search exactly as logged`, snap.includes('ZZZ UX mantenimiento de bombas') && snap.includes('zzz-ux-engine'));
  record(`${vp}: the 3 results with names and the prior vote of this session`, (await page.locator('[data-cira-results] tbody tr').count()) === 3 && (await page.content()).includes('incluye su voto'));
  record(`${vp}: the 8 tests are offered`, (await page.locator('select[id$="test_code"] option[value^="CPV-"]').count()) === 8);
  await clean(page, `${vp} form`);
  if (vp === 'phone') { await noHorizontalScroll(page, 'phone form'); }

  await page.selectOption('select[id$="test_code"]', test);
  await page.locator('label:has-text("Parcial")').first().click();
  await page.locator('input[type="checkbox"][value="' + EMPRESAS[2] + '"]').check().catch(() => {});
  await page.fill('textarea[id$="comment"]', `ZZZ UX ${vp}: la tercera no hace bombas <b>no es html</b>`);
  await page.click('[data-cira-save-draft]');
  await page.waitForTimeout(1500);
  record(`${vp}: draft saved with a confirmation`, (await page.content()).includes('Borrador guardado'));
  await page.click('[data-cira-save-draft]'); // double click: still one evaluation (checked in «Mis pruebas» below)
  await page.waitForTimeout(1000);
  await page.screenshot({ path: `${OUT}/${vp}_2_form.png`, fullPage: true });

  // 3. Resume from «Mis pruebas» and submit.
  await page.locator('[data-cira-eval-home]').count(); // the header link of CIRA Test points here
  await page.goto(BASE + '/admin/evaluacion-cira', { waitUntil: 'networkidle' });
  const rows = page.locator('[data-cira-my-evaluations] tbody tr', { hasText: test });
  record(`${vp}: «Mis pruebas» lists the draft once`, (await rows.count()) === 1);
  await rows.first().locator('a', { hasText: 'Abrir' }).click();
  await page.waitForLoadState('networkidle').catch(() => {});
  const comment = await page.inputValue('textarea[id$="comment"]').catch(() => '');
  record(`${vp}: the reopened draft keeps what was written`, comment.startsWith(`ZZZ UX ${vp}`));
  await page.click('[data-cira-submit]');
  await page.waitForTimeout(1500);
  record(`${vp}: submitted with a confirmation`, (await page.content()).includes('Evaluación enviada'));
  await page.goto(BASE + '/admin/evaluacion-cira', { waitUntil: 'networkidle' });
  const progressRow = page.locator('[data-cira-progress] li', { hasText: test });
  record(`${vp}: progress shows ${test} sent`, (await progressRow.innerText().catch(() => '')).includes('enviada'));
  await page.screenshot({ path: `${OUT}/${vp}_3_mis_pruebas.png`, fullPage: true });
  if (vp === 'phone') { await noHorizontalScroll(page, 'phone mis pruebas'); }

  // 4. The guide: 8 tests.
  await page.goto(BASE + '/admin/evaluacion-cira/guia', { waitUntil: 'networkidle' });
  record(`${vp}: the guide lists the 8 tests`, (await page.locator('[data-cira-guide-test]').count()) === 8);
  await page.screenshot({ path: `${OUT}/${vp}_4_guia.png`, fullPage: true });

  // 5. The evaluator cannot reach the internal panel.
  const panel = await page.goto(BASE + '/admin/cira-evaluaciones');
  record(`${vp}: the evaluator gets 403 on the internal panel`, panel && panel.status() === 403, panel && panel.status());
  await ctx.close();
}

// 6. Internal panel (privileged role): evaluations, detail, report.
{
  const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, locale: 'es-VE' });
  const page = await ctx.newPage();
  await page.goto(BASE + '/admin/login', { waitUntil: 'domcontentloaded' });
  await login(page, ADMIN);
  await page.goto(BASE + '/admin/cira-evaluaciones', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1500);
  const list = await page.content();
  record('panel: both evaluations listed with the pseudonym', list.includes('CPV-01') && list.includes('CPV-06') && list.includes('E-01'));
  await page.screenshot({ path: `${OUT}/panel_1_list.png`, fullPage: true });
  const viewLink = page.locator('a[href*="/admin/cira-evaluaciones/"]').first();
  await viewLink.click();
  await page.waitForLoadState('networkidle').catch(() => {});
  const detail = await page.content();
  record('panel: detail shows the immutable snapshot verified and the comment as text', detail.includes('verificada (SHA-256)') && detail.includes('&lt;b&gt;no es html&lt;/b&gt;') && !detail.includes('<b>no es html</b>'));
  await clean(page, 'panel detail');
  await page.screenshot({ path: `${OUT}/panel_2_detail.png`, fullPage: true });
  await page.goto(BASE + '/admin/evaluacion-cira/informe', { waitUntil: 'networkidle' });
  const report = await page.locator('[data-cira-report-tests]').innerText().catch(() => '');
  record('panel: report counts the two «Parcial» judgements and no decision is assumed', report.includes('CPV-01') && (await page.content()).includes('no declara aceptado'));
  await page.screenshot({ path: `${OUT}/panel_3_report.png`, fullPage: true });
  await ctx.close();
}

await browser.close();
writeFileSync(`${OUT}/ux_results.json`, JSON.stringify(results, null, 2));
const failed = results.filter((r) => !r.pass).length;
console.log(`\n${results.length - failed}/${results.length} checks passed`);
process.exit(failed ? 1 : 0);
