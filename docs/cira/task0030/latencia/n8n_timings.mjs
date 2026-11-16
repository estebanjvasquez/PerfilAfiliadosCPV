// TASK-0030: tiempos por nodo de ejecuciones del flujo de CIRA Test (solo lectura, API de n8n).
// Uso: N8N_BASE_URL=... N8N_API_KEY=... node n8n_timings.mjs <workflowId> <desde_iso> <salida.json> [max]
// Salida sin datos personales: consulta del usuario, intención, cantidad de resultados y ms por nodo.
import fs from 'node:fs';

const [wf, since, outPath, max = '60'] = process.argv.slice(2);
const base = process.env.N8N_BASE_URL.replace(/\/$/, '');
const h = { 'X-N8N-API-KEY': process.env.N8N_API_KEY };
const get = async (p) => {
	const r = await fetch(base + p, { headers: h });
	if (!r.ok) throw new Error(p + ' ' + r.status);
	return r.json();
};

const list = [];
let cursor = null;
do {
	const q = `/api/v1/executions?workflowId=${wf}&limit=100${cursor ? '&cursor=' + cursor : ''}`;
	const r = await get(q);
	for (const e of r.data) if (e.startedAt >= since) list.push(e);
	cursor = r.nextCursor && r.data.length && r.data.at(-1).startedAt >= since ? r.nextCursor : null;
} while (cursor && list.length < Number(max));

const out = [];
for (const e of list.slice(0, Number(max))) {
	const d = await get(`/api/v1/executions/${e.id}?includeData=true`);
	const run = d.data?.resultData?.runData ?? {};
	const nodes = {};
	for (const [name, runs] of Object.entries(run)) {
		nodes[name] = runs.reduce((s, r) => s + (r.executionTime ?? 0), 0);
	}
	const pick = (n) => run[n]?.[0]?.data?.main?.[0]?.[0]?.json;
	const pre = pick('Pre-Procesing3');
	const parse = pick('Parse Intent JSON3');
	const unwrap = pick('Unwrap MCP Result');
	const t0 = Date.parse(d.startedAt);
	const t1 = Date.parse(d.stoppedAt);
	out.push({
		execution: d.id,
		started: d.startedAt,
		total_ms: t1 - t0,
		status: d.status,
		version: d.workflowData?.versionId ?? null,
		consulta: pre?.chatInput ?? null,
		intent: parse?.queryIntent ?? null,
		tool: parse?.tool ?? null,
		params: parse?.params ?? null,
		resultados: Array.isArray(unwrap?.rows) ? unwrap.rows.length : null,
		nodos_ms: nodes,
	});
}
fs.writeFileSync(outPath, JSON.stringify(out, null, 1));
for (const x of out) {
	const n = x.nodos_ms;
	console.log(
		[x.execution, x.started.slice(11, 19), (x.version || '').slice(0, 8), x.total_ms + 'ms', JSON.stringify(x.consulta).slice(0, 40), 'n=' + x.resultados,
			'agente=' + (n['Interactive Intent Agent3'] ?? '-'), 'modelo=' + (n['OpenAI Chat Model'] ?? '-'), 'mcpTool=' + (n['MCP Perfil Afiliados CPV'] ?? '-'),
			'worker=' + (n['Call MCP Empresas'] ?? '-'), 'log=' + (n['Log Search'] ?? '-'), 'cards=' + (n['Format Cards2'] ?? '-'), 'learn=' + (n['Load Learnings'] ?? '-'), 'mem=' + (n['Postgres Memory Intent3'] ?? '-')].join(' ')
	);
}
