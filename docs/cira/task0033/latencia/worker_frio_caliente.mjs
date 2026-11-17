// TASK-0033 E4: latencia del Worker MCP vivo, SOLO lectura (tools/list y tools/call de search_empresas/get_empresa;
// ninguna de las dos escribe cira_search_log, votos ni evaluaciones: el registro lo hace n8n en «Log Search»).
// Mide la primera llamada tras inactividad y luego repeticiones en caliente, con los mismos argumentos que n8n envió
// en la UAT del propietario (ejecuciones 23158-23163, `grupos: true` como en «Call MCP Empresas»).
// Uso: CPV_MCP_TOKEN=... node worker_frio_caliente.mjs <url_mcp> <repeticiones> <salida.json>
import fs from 'node:fs';

const [url, reps = '5', outPath] = process.argv.slice(2);
const cases = [
	{ id: 'perforacion', name: 'search_empresas', args: { query: 'perforacion', resolvedPhrases: ['perforación'], queryIntent: 'SERVICE', explain: true, grupos: true } },
	{ id: 'balacin', name: 'search_empresas', args: { query: 'balacin', resolvedPhrases: ['balancín'], queryIntent: 'SERVICE', explain: true, grupos: true } },
	{ id: 'macolla', name: 'search_empresas', args: { query: 'macolla', queryIntent: 'SERVICE', explain: true, grupos: true } },
	{ id: 'camiones', name: 'search_empresas', args: { query: 'camiones de transporte de maquinaria pesada', queryIntent: 'SERVICE', explain: true, grupos: true } },
	{ id: 'international_offshore', name: 'search_empresas', args: { query: 'international offshore', queryIntent: 'SERVICE', explain: true, grupos: true } },
	{ id: 'ficha_177', name: 'get_empresa', args: { id: 177 } },
];
async function rpc(method, params) {
	const t0 = performance.now();
	const r = await fetch(url, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', Authorization: 'Bearer ' + process.env.CPV_MCP_TOKEN },
		body: JSON.stringify({ jsonrpc: '2.0', id: 1, method, params }),
	});
	const t = await r.text();
	return { ms: Math.round(performance.now() - t0), status: r.status, bytes: t.length, at: new Date().toISOString() };
}
const med = (a) => [...a].sort((x, y) => x - y)[Math.floor(a.length / 2)];
const out = { inicio: new Date().toISOString(), tools_list: [], casos: [] };
// 1) Red + Worker sin BD.
for (let i = 0; i < 5; i++) out.tools_list.push(await rpc('tools/list', {}));
// 2) Primera llamada de cada caso (la primera del conjunto es la primera con BD tras inactividad), luego en caliente.
for (const c of cases) {
	const runs = [];
	for (let i = 0; i < Number(reps); i++) runs.push(await rpc('tools/call', { name: c.name, arguments: c.args }));
	out.casos.push({ id: c.id, tool: c.name, ms: runs.map((r) => r.ms), primera_ms: runs[0].ms, mediana_caliente_ms: med(runs.slice(1).map((r) => r.ms)), status: [...new Set(runs.map((r) => r.status))], bytes: runs[0].bytes, primera_at: runs[0].at });
}
out.fin = new Date().toISOString();
fs.writeFileSync(outPath, JSON.stringify(out, null, 1));
console.log('tools/list', JSON.stringify(out.tools_list.map((x) => x.ms)));
for (const c of out.casos) console.log(c.id, 'primera', c.primera_ms, 'caliente_med', c.mediana_caliente_ms, JSON.stringify(c.ms), c.status, Math.round(c.bytes / 1024) + 'KB');
