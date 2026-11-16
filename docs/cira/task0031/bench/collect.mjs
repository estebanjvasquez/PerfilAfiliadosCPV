// TASK-0031: recoge las ejecuciones de una copia aislada (API de n8n, lectura) y las empareja con run_variant.mjs.
// Por ejecución: ms por nodo, llamadas al modelo, tokens, parámetros del agente y resultados (id + grupo).
// Uso: N8N_BASE_URL=... N8N_API_KEY=... node collect.mjs <workflowId> <run.json> <salida.json>
import fs from 'node:fs';

const [wf, runPath, outPath] = process.argv.slice(2);
const runs = JSON.parse(fs.readFileSync(runPath, 'utf8'));
const base = process.env.N8N_BASE_URL.replace(/\/$/, '');
const h = { 'X-N8N-API-KEY': process.env.N8N_API_KEY };
const get = async (p) => {
	const r = await fetch(base + p, { headers: h });
	if (!r.ok) throw new Error(p + ' ' + r.status);
	return r.json();
};

const ids = [];
let cursor = null;
do {
	const r = await get(`/api/v1/executions?workflowId=${wf}&limit=100${cursor ? '&cursor=' + cursor : ''}`);
	ids.push(...r.data.map((e) => e.id));
	cursor = r.nextCursor;
} while (cursor);

const bySession = new Map();
for (const id of ids) {
	const d = await get(`/api/v1/executions/${id}?includeData=true`);
	const rd = d.data?.resultData?.runData ?? {};
	const first = (n) => rd[n]?.[0]?.data?.main?.[0]?.[0]?.json;
	const pre = first('Pre-Procesing3');
	if (!pre?.sessionId) continue;
	const nodos = {};
	for (const [name, rr] of Object.entries(rd)) nodos[name] = rr.reduce((s, x) => s + (x.executionTime ?? 0), 0);
	const llm = rd['OpenAI Chat Model'] ?? [];
	const tokens = { prompt: 0, completion: 0, total: 0 };
	for (const x of llm) {
		const tu = x.data?.ai_languageModel?.[0]?.[0]?.json?.tokenUsage ?? x.data?.ai_languageModel?.[0]?.[0]?.json?.tokenUsageEstimate;
		if (tu) {
			tokens.prompt += tu.promptTokens ?? 0;
			tokens.completion += tu.completionTokens ?? 0;
			tokens.total += tu.totalTokens ?? 0;
		}
	}
	const parse = first('Parse Intent JSON3');
	const unwrap = first('Unwrap MCP Result');
	bySession.set(pre.sessionId, {
		execution: d.id,
		status: d.status,
		total_ms: Date.parse(d.stoppedAt) - Date.parse(d.startedAt),
		nodos_ms: nodos,
		llamadas_modelo: llm.length,
		llamadas_herramienta_mcp: (rd['MCP Perfil Afiliados CPV'] ?? []).length,
		tokens,
		tool: parse?.tool ?? null,
		params: parse?.params ?? null,
		search_ready: parse?.isSearchReady ?? null,
		rows: Array.isArray(unwrap?.rows) ? unwrap.rows.map((r) => ({ id: Number(r.id), grupo: r.grupo ?? null, origen: r.capacidad?.origen ?? null })) : null,
		error: d.data?.resultData?.error?.message ?? null,
	});
}
const out = runs.map((r) => ({ ...r, exec: bySession.get(r.sessionId) ?? null }));
fs.writeFileSync(outPath, JSON.stringify(out, null, 1));
console.log('runs', runs.length, 'emparejadas', out.filter((x) => x.exec).length);
