// TASK-0033 E4: prueba acotada de la hipótesis «la consulta repetida es rápida por la caché de Hyperdrive (~60 s)».
// SOLO lectura (/mcp tools/call de search_empresas; no escribe registros). Una sola espera fija, sin sondeo.
// Secuencia por caso: llamada, llamada, llamada, espera de <pausa_s>, llamada, llamada.
// Uso: CPV_MCP_TOKEN=... node worker_cache_ttl.mjs <url_mcp> <pausa_s> <salida.json>
import fs from 'node:fs';

const [url, pausa = '75', outPath] = process.argv.slice(2);
const cases = [
	{ id: 'equipos de izamiento', args: { query: 'equipos de izamiento', queryIntent: 'SERVICE', explain: true, grupos: true } },
	{ id: 'inspeccion de tuberias', args: { query: 'inspeccion de tuberias', resolvedPhrases: ['inspección de tuberías'], queryIntent: 'SERVICE', explain: true, grupos: true } },
];
async function call(args) {
	const t0 = performance.now();
	const r = await fetch(url, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', Authorization: 'Bearer ' + process.env.CPV_MCP_TOKEN },
		body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name: 'search_empresas', arguments: args } }),
	});
	await r.text();
	return { ms: Math.round(performance.now() - t0), status: r.status, at: new Date().toISOString() };
}
const out = { pausa_s: Number(pausa), casos: {} };
for (const c of cases) out.casos[c.id] = { antes: [await call(c.args), await call(c.args), await call(c.args)] };
await new Promise((r) => setTimeout(r, Number(pausa) * 1000));
for (const c of cases) out.casos[c.id].despues = [await call(c.args), await call(c.args)];
fs.writeFileSync(outPath, JSON.stringify(out, null, 1));
for (const [k, v] of Object.entries(out.casos)) console.log(k, 'antes', JSON.stringify(v.antes.map((x) => x.ms)), '| tras', pausa, 's', JSON.stringify(v.despues.map((x) => x.ms)));
