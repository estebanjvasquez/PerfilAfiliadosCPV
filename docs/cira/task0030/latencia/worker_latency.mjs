// TASK-0030: latencia de search_empresas (solo lectura: no escribe registro) con y sin `grupos`, intercalado.
// Uso: CPV_MCP_TOKEN=... node worker_latency.mjs <url_mcp> <repeticiones> <salida.json>
import fs from 'node:fs';

const [url, reps = '5', outPath] = process.argv.slice(2);
const cases = [
	{ q: 'empresas de perforacion', args: { query: 'perforacion', resolvedPhrases: ['empresas de perforación'], queryIntent: 'SERVICE', explain: true } },
	{ q: 'balancin', args: { query: 'balancin', resolvedPhrases: ['balancín'], queryIntent: 'SERVICE', explain: true } },
	{ q: 'macolla', args: { query: 'macolla', resolvedPhrases: ['macolla'], queryIntent: 'SERVICE', explain: true } },
	{ q: 'gandola', args: { query: 'gandolas', resolvedPhrases: ['gandolas'], queryIntent: 'SERVICE', explain: true } },
];
async function call(args) {
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
	return { ms, n: rows.length, bytes: t.length };
}
const med = (a) => [...a].sort((x, y) => x - y)[Math.floor(a.length / 2)];
const out = [];
for (const c of cases) {
	const d = [], g = [];
	let nd, ng, bd, bg;
	for (let i = 0; i < Number(reps); i++) {
		const a = await call(c.args); d.push(a.ms); nd = a.n; bd = a.bytes;
		const b = await call({ ...c.args, grupos: true }); g.push(b.ms); ng = b.n; bg = b.bytes;
	}
	out.push({ consulta: c.q, por_defecto_ms: d, grupos_ms: g, mediana_por_defecto: med(d), mediana_grupos: med(g), resultados: { por_defecto: nd, grupos: ng }, bytes: { por_defecto: bd, grupos: bg } });
	console.log(c.q, 'defecto', med(d), JSON.stringify(d), '| grupos', med(g), JSON.stringify(g), '| n', nd, ng, '| KB', Math.round(bd / 1024), Math.round(bg / 1024));
}
fs.writeFileSync(outPath, JSON.stringify(out, null, 1));
