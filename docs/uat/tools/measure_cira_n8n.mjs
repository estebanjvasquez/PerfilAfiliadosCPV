#!/usr/bin/env node
/**
 * Mide la ruta completa de CIRA: chat -> webhook de n8n -> agente de intención -> MCP.
 *
 * A diferencia de evaluate_search.mjs (que llama al MCP directo con la frase del caso), esto pasa por
 * el agente de n8n, así que mide también cómo reescribe la consulta. Por cada envío:
 *   1. POST al webhook del flujo con una conversación nueva (sessionId único);
 *   2. lee la ejecución por la API de n8n y extrae la consulta original, la intención de
 *      resolve_search_intent, la tool y los parámetros que salieron del agente, los resultados en el
 *      orden en que se muestran y la versión del prompt y del flujo.
 *
 * Accesos (sólo por variables de entorno, nunca por argumento):
 *   CIRA_WEBHOOK_URL  URL del webhook del flujo a medir
 *   N8N_BASE_URL      base de la API de n8n
 *   N8N_API_KEY       clave de la API de n8n (sólo se usa para LEER ejecuciones)
 *
 * Uso:
 *   node measure_cira_n8n.mjs --workflow <id> --golden ../golden_set_lote001.json [--cases extra.json]
 *        [--only A,B] [--reps 3] [--delay-ms 1500] --save corrida.json
 *
 * Con --save escribe, además del archivo completo, un archivo por repetición (<save>.repN.json) con el
 * formato {meta, runs} que acepta `evaluate_search.mjs --from`, para calcular las mismas métricas.
 *
 * Una conversación nueva por envío: el agente guarda memoria por sessionId y una conversación previa
 * cambiaría la interpretación. El sessionId empieza con "eval-" para distinguir estas ejecuciones.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { randomUUID } from 'node:crypto';

const fail = (msg) => {
	console.error(msg);
	process.exit(2);
};

function parseArgs(argv) {
	const a = { reps: 1, delayMs: 1500, only: null, cases: null, golden: null, workflow: null, save: null };
	for (let i = 0; i < argv.length; i++) {
		const k = argv[i];
		const next = () => {
			if (i + 1 >= argv.length) fail(`Falta el valor de ${k}`);
			return argv[++i];
		};
		if (k === '--workflow') a.workflow = next();
		else if (k === '--golden') a.golden = next();
		else if (k === '--cases') a.cases = next();
		else if (k === '--only') a.only = new Set(next().split(',').map((s) => s.trim()));
		else if (k === '--reps') a.reps = Number(next());
		else if (k === '--delay-ms') a.delayMs = Number(next());
		else if (k === '--save') a.save = next();
		else if (/token|key|password/i.test(k)) fail(`${k}: los accesos sólo se leen de variables de entorno.`);
		else fail(`Argumento desconocido: ${k}`);
	}
	if (!a.workflow) fail('Falta --workflow <id>.');
	if (!a.golden && !a.cases) fail('Indica --golden y/o --cases.');
	return a;
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const env = (name) => process.env[name] || fail(`Falta la variable de entorno ${name}.`);

const first = (rd, node) => rd?.[node]?.[0]?.data?.main?.[0]?.[0]?.json ?? null;

async function n8n(path) {
	const res = await fetch(`${env('N8N_BASE_URL')}/api/v1${path}`, { headers: { 'X-N8N-API-KEY': env('N8N_API_KEY') } });
	if (res.status === 401) fail('HTTP 401: la API de n8n rechazó la clave.');
	if (!res.ok) throw new Error(`n8n HTTP ${res.status} en ${path}`);
	return res.json();
}

async function findExecution(workflow, sessionId, sentAt) {
	for (let attempt = 0; attempt < 40; attempt++) {
		const list = await n8n(`/executions?workflowId=${encodeURIComponent(workflow)}&limit=8`);
		for (const e of list.data ?? []) {
			if (new Date(e.startedAt).getTime() < sentAt - 5000) continue;
			const full = await n8n(`/executions/${e.id}?includeData=true`);
			const pre = first(full.data?.resultData?.runData, 'Pre-Procesing3');
			if (pre?.sessionId !== sessionId) continue;
			if (!full.finished && full.status !== 'success' && full.status !== 'error') break;
			return full;
		}
		await sleep(1500);
	}
	throw new Error('no se encontró la ejecución de n8n para este envío');
}

/** Llamadas que hizo el agente a las tools MCP (resolve_search_intent y, en el flujo anterior, otras). */
function agentToolCalls(rd) {
	const calls = [];
	for (const run of rd?.['MCP Perfil Afiliados CPV'] ?? []) {
		const input = run.inputOverride?.ai_tool?.[0]?.[0]?.json ?? {};
		const name = String(input.tool?.name ?? '').replace(/^MCP_Perfil_Afiliados_CPV_/, '');
		let parsed = null;
		try {
			parsed = JSON.parse(run.data?.ai_tool?.[0]?.[0]?.json?.response?.[0]?.text ?? 'null');
		} catch {
			parsed = null;
		}
		calls.push({ tool: name, args: input.query ?? null, result: name === 'resolve_search_intent' ? parsed : Array.isArray(parsed) ? `${parsed.length} filas` : null });
	}
	return calls;
}

function extract(full, input) {
	const rd = full.data?.resultData?.runData ?? {};
	const bsp = first(rd, 'Build System Prompt');
	const parse = first(rd, 'Parse Intent JSON3');
	const agent = first(rd, 'Interactive Intent Agent3');
	const unwrap = first(rd, 'Unwrap MCP Result');
	const calls = agentToolCalls(rd);
	const intentCall = calls.find((c) => c.tool === 'resolve_search_intent' && c.result);
	const responded = ['Final Respond3', 'Respond Conversation5', 'Respond Conversation6'].find((n) => rd[n]) ?? null;
	return {
		execution_id: full.id,
		workflow_id: full.workflowId,
		input,
		prompt_version: bsp?.promptVersion ?? 'anterior (sin marca)',
		learnings_count: bsp?.learningsCount ?? null,
		intent: intentCall ? { interpretation: intentCall.result.interpretation ?? null, search_phrases: intentCall.result.search_phrases ?? [] } : null,
		agent_tool_calls: calls.map((c) => c.tool),
		agent_direct_search: calls.some((c) => c.tool === 'search_empresas' || c.tool === 'get_empresa'),
		tool: parse?.tool ?? null,
		params: parse?.params ?? null,
		query_restored_from: parse?.queryRestoredFrom ?? null,
		responded_with: responded,
		reply_text: parse?.isSearchReady === 'true' ? null : String(parse?.conversationalText ?? agent?.output ?? '').slice(0, 400),
		tool_error: unwrap?.toolError ?? null,
		results: (unwrap?.rows ?? []).map((r) => ({ empresa_id: Number(r.id), name: String(r.name ?? '').trim(), matched_via: r.matched_via ?? null })),
		status: full.status,
	};
}

async function send(c, workflow) {
	const sessionId = `eval-${randomUUID()}`;
	const sentAt = Date.now();
	const res = await fetch(env('CIRA_WEBHOOK_URL'), {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify({ chatInput: c.query, sessionId }),
	});
	await res.text();
	if (!res.ok) throw new Error(`webhook HTTP ${res.status}`);
	const full = await findExecution(workflow, sessionId, sentAt);
	return { ...extract(full, c.query), ms: Date.now() - sentAt };
}

/** Formato de evaluate_search.mjs --from. */
function asEvalRun(call) {
	return {
		intent: call.intent,
		searched: call.tool === 'search_empresas',
		agent_tool: call.tool,
		agent_query: call.params?.query ?? call.params?.nombre ?? call.params?.rif ?? null,
		agent_phrases: call.params?.resolvedPhrases ?? [],
		results: call.results,
		...(call.error ? { error: call.error } : {}),
	};
}

async function main() {
	const args = parseArgs(process.argv.slice(2));
	let cases = [];
	if (args.golden) cases.push(...JSON.parse(readFileSync(args.golden, 'utf8')).cases.map((c) => ({ id: c.test_id, query: c.query, golden: true })));
	if (args.cases) cases.push(...JSON.parse(readFileSync(args.cases, 'utf8')).map((c) => ({ id: c.id, query: c.query, kind: c.kind ?? null, golden: false })));
	if (args.only) cases = cases.filter((c) => args.only.has(c.id));
	if (!cases.length) fail('Ningún caso para correr.');

	const wf = await n8n(`/workflows/${encodeURIComponent(args.workflow)}`);
	const meta = {
		mode: 'n8n',
		workflow_id: wf.id,
		workflow_name: wf.name,
		workflow_version_id: wf.versionId,
		workflow_updated_at: wf.updatedAt,
		reps: args.reps,
		ran_at: new Date().toISOString(),
		label: args.save ? args.save.split(/[\\/]/).pop() : null,
	};

	const calls = [];
	const total = cases.length * args.reps;
	let n = 0;
	for (let rep = 1; rep <= args.reps; rep++) {
		for (const c of cases) {
			n++;
			process.stderr.write(`[${n}/${total}] rep ${rep} ${c.id} ${c.query}\n`);
			let call;
			try {
				call = await send(c, args.workflow);
			} catch (e) {
				call = { input: c.query, error: String(e.message ?? e) };
			}
			calls.push({ rep, case_id: c.id, golden: c.golden, kind: c.kind ?? null, ...call });
			await sleep(args.delayMs);
		}
	}

	const out = { meta, calls };
	if (args.save) {
		writeFileSync(args.save, JSON.stringify(out, null, 1) + '\n', 'utf8');
		for (let rep = 1; rep <= args.reps; rep++) {
			const runs = {};
			for (const call of calls.filter((x) => x.rep === rep && x.golden)) runs[call.case_id] = asEvalRun(call);
			if (Object.keys(runs).length) {
				const file = args.save.replace(/\.json$/, '') + `.rep${rep}.json`;
				writeFileSync(file, JSON.stringify({ meta: { ...meta, mode: 'n8n', intent: true, label: `${meta.label} rep ${rep}` }, runs }, null, 1) + '\n', 'utf8');
			}
		}
		process.stderr.write(`Corrida guardada en ${args.save}\n`);
	}

	for (const call of calls) {
		const q = call.error ? `ERROR ${call.error}` : `${call.tool ?? 'texto'} ${JSON.stringify(call.params ?? {})}${call.query_restored_from ? ` (restituida desde "${call.query_restored_from}")` : ''} -> ${call.results?.length ?? 0}`;
		console.log(`r${call.rep} ${String(call.case_id).padEnd(8)} ${call.input} | ${q}`);
	}
}

main().catch((e) => fail(String(e.stack ?? e)));
