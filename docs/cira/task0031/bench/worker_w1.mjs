// TASK-0031: Worker W1 (paralelismo) frente al código desplegado, ambos por URL de vista previa (sin tráfico real).
// 1) Equivalencia: misma consulta en ambas versiones (por defecto y con grupos) → ids, orden, grupo y puntaje idénticos.
// 2) Latencia en FRÍO: cada llamada usa una escritura distinta en mayúsculas/minúsculas de la consulta (ilike y unaccent
//    dan el mismo resultado, pero la consulta SQL es otra y no sale de la caché de Hyperdrive). Las variantes se alternan
//    entre versiones para no favorecer a ninguna. Solo `search_empresas`: no escribe registros.
// Uso: CPV_MCP_TOKEN=... node worker_w1.mjs <cases.json> <url_base_mcp> <url_w1_mcp> <salida.json>
import fs from 'node:fs';

const [casesPath, urlBase, urlW1, outPath] = process.argv.slice(2);
const cases = JSON.parse(fs.readFileSync(casesPath, 'utf8'));
const fallos = [];
async function call(url, args) {
	for (let i = 0; i < 2; i++) {
		try {
			return await callOnce(url, args);
		} catch (e) {
			fallos.push({ url: url.slice(8, 16), query: args.query, error: String(e.message ?? e).slice(0, 120) });
			await new Promise((r) => setTimeout(r, 2000));
		}
	}
	return { ms: null, sig: null };
}
async function callOnce(url, args) {
	const t0 = performance.now();
	const r = await fetch(url, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', Authorization: 'Bearer ' + process.env.CPV_MCP_TOKEN },
		body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name: 'search_empresas', arguments: args } }),
	});
	const t = await r.text();
	const ms = Math.round(performance.now() - t0);
	const m = t.match(/data:\s*(\{[\s\S]*\})/);
	const rows = JSON.parse(JSON.parse(m ? m[1] : t).result.content[0].text);
	return { ms, sig: rows.map((x) => [Number(x.id), x.grupo ?? null, x.explain?.puntaje ?? null]) };
}
const upper = (s) => s.toUpperCase();
const title = (s) => s.replace(/(^|\s)(\p{L})/gu, (_, a, b) => a + b.toUpperCase());
const swap = (s) => s.split('').map((c, i) => (i % 2 ? c.toUpperCase() : c)).join('');
const swap2 = (s) => s.split('').map((c, i) => (i % 2 ? c : c.toUpperCase())).join('');
const out = [];
for (const [i, c] of cases.entries()) {
	const args = { query: c.query, resolvedPhrases: c.phrases, explain: true };
	// equivalencia (texto original, ambos modos)
	const eq = {};
	for (const g of [false, true]) {
		const a = await call(urlBase, { ...args, grupos: g });
		const b = await call(urlW1, { ...args, grupos: g });
		eq[g ? "grupos" : "defecto"] = a.sig && b.sig ? JSON.stringify(a.sig) === JSON.stringify(b.sig) : null;
	}
	// frío, modo grupos: dos escrituras nuevas, alternando qué versión recibe cuál
	const [v1, v2] = [i % 2 ? upper : title, i % 2 ? title : upper];
	const w1First = i % 2 === 0;
	const mk = (f) => ({ query: f(c.query), resolvedPhrases: c.phrases.map(f), explain: true, grupos: true });
	const r1 = await call(w1First ? urlW1 : urlBase, mk(v1));
	const r2 = await call(w1First ? urlBase : urlW1, mk(v2));
	// tercera escritura para el segundo punto de cada versión
	const r3 = await call(w1First ? urlBase : urlW1, mk(swap));
	const r4 = await call(w1First ? urlW1 : urlBase, mk(swap2));
	const w1 = [w1First ? r1.ms : r2.ms, w1First ? r4.ms : r3.ms];
	const base = [w1First ? r2.ms : r1.ms, w1First ? r3.ms : r4.ms];
	out.push({ id: c.id, query: c.query, equivalente: eq, frio_ms: { base, w1 } });
	process.stderr.write(`${c.id} eq=${JSON.stringify(eq)} base=${base} w1=${w1}\n`);
}
const all = (k) => out.flatMap((x) => x.frio_ms[k]).filter((v) => v != null).sort((a, b) => a - b);
const pct = (a, p) => a[Math.min(a.length - 1, Math.floor((p / 100) * a.length))];
const res = {
	casos: out.length,
	fallos,
	equivalencia: { defecto: out.filter((x) => x.equivalente.defecto).length, grupos: out.filter((x) => x.equivalente.grupos).length },
	frio_grupos_ms: { base: { p50: pct(all('base'), 50), p95: pct(all('base'), 95), n: all('base').length }, w1: { p50: pct(all('w1'), 50), p95: pct(all('w1'), 95), n: all('w1').length } },
	por_consulta: out,
};
fs.writeFileSync(outPath, JSON.stringify(res, null, 1));
console.log(JSON.stringify({ casos: res.casos, equivalencia: res.equivalencia, frio_grupos_ms: res.frio_grupos_ms }));
