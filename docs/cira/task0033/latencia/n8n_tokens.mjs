// TASK-0033 E4: tokens del modelo y mensajes de memoria por ejecución (solo lectura, API de n8n). Sin textos de usuario.
// Uso: N8N_BASE_URL=... N8N_API_KEY=... node n8n_tokens.mjs <salida.json> <id> [<id> ...]
import fs from 'node:fs';

const [outPath, ...ids] = process.argv.slice(2);
const base = process.env.N8N_BASE_URL.replace(/\/$/, '');
const h = { 'X-N8N-API-KEY': process.env.N8N_API_KEY };
const out = [];
for (const id of ids) {
	const d = await (await fetch(`${base}/api/v1/executions/${id}?includeData=true`, { headers: h })).json();
	const rd = d.data?.resultData?.runData ?? {};
	const model = rd['OpenAI Chat Model'] ?? [];
	const tok = model.map((r) => r.data?.ai_languageModel?.[0]?.[0]?.json?.tokenUsage ?? r.data?.ai_languageModel?.[0]?.[0]?.json?.tokenUsageEstimate ?? null);
	const mem = rd['Postgres Memory Intent3']?.[0]?.data?.ai_memory?.[0]?.[0]?.json;
	const memMsgs = Array.isArray(mem?.response?.chat_history) ? mem.response.chat_history.length : Array.isArray(mem?.chat_history) ? mem.chat_history.length : null;
	out.push({ execution: id, started: d.startedAt, llamadas_modelo: model.length, tokens: tok, mensajes_memoria: memMsgs });
}
fs.writeFileSync(outPath, JSON.stringify(out, null, 1));
for (const x of out) console.log(x.execution, x.started, 'llamadas', x.llamadas_modelo, JSON.stringify(x.tokens), 'memoria', x.mensajes_memoria);
