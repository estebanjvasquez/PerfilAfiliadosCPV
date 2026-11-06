#!/usr/bin/env node
/**
 * Mide el buscador de CIRA contra la lista de referencia del cliente (docs/uat/golden_set_lote001.json).
 * Ver docs/uat/GOLDEN_SET_LOTE001.md para la semántica de la lista y docs/uat/tools/README.md para el uso.
 *
 * Solo lectura: llama herramientas de búsqueda del Worker, nunca escribe nada.
 * El token se lee de una variable de entorno y no se imprime ni se guarda (el repo es público).
 *
 * Node 18+ (usa fetch nativo). Sin dependencias.
 */

import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, resolve } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DEFAULT_URL = 'https://perfilafiliados-mcp.sisteg.workers.dev';
const DEFAULT_GOLDEN = join(__dirname, '..', 'golden_set_lote001.json');
const TOKEN_ENV_BY_MODE = { mcp: 'CPV_MCP_TOKEN', debug: 'CPV_DEBUG_TOKEN' };
// Mismo corte que search_empresas (perfilafiliados-mcp/src/empresa-tools.ts): 20, o 150 si hay más de 20 directas.
const DEFAULT_LIMIT = 20;
const EXACT_LIMIT = 150;
const KS = [5, 10];

// ---------------------------------------------------------------- argumentos

function parseArgs(argv) {
	const args = { mode: 'mcp', url: DEFAULT_URL, golden: DEFAULT_GOLDEN, tokenEnv: null, intent: true, save: null, from: null, compare: null, only: null, delayMs: 400, json: false };
	for (let i = 0; i < argv.length; i++) {
		const a = argv[i];
		const next = () => {
			if (i + 1 >= argv.length) fail(`Falta el valor de ${a}`);
			return argv[++i];
		};
		if (a === '--mode') args.mode = next();
		else if (a === '--url') args.url = next().replace(/\/+$/, '');
		else if (a === '--golden') args.golden = resolve(next());
		else if (a === '--token-env') args.tokenEnv = next();
		else if (a === '--no-intent') args.intent = false;
		else if (a === '--save') args.save = resolve(next());
		else if (a === '--from') args.from = resolve(next());
		else if (a === '--compare') args.compare = resolve(next());
		else if (a === '--only') args.only = new Set(next().split(',').map((s) => s.trim()));
		else if (a === '--delay') args.delayMs = Number(next());
		else if (a === '--json') args.json = true;
		else if (a === '--token') fail('No se acepta el token por argumento (quedaría en el historial). Use una variable de entorno: ver --token-env.');
		else if (a === '-h' || a === '--help') {
			printHelp();
			process.exit(0);
		} else fail(`Argumento desconocido: ${a}`);
	}
	if (!['mcp', 'debug'].includes(args.mode)) fail('--mode debe ser mcp o debug');
	args.tokenEnv ??= TOKEN_ENV_BY_MODE[args.mode];
	return args;
}

function printHelp() {
	console.log(`Uso:
  node evaluate_search.mjs [--mode mcp|debug] [--save corrida.json] [--compare base.json]
  node evaluate_search.mjs --from corrida.json [--compare base.json]

  --mode mcp      (por defecto) resolve_search_intent + search_empresas por /mcp, como CIRA. Token: $CPV_MCP_TOKEN
  --mode debug    /debug-search: ranking completo sin cortar; el corte 20/150 se simula. Token: $CPV_DEBUG_TOKEN
  --no-intent     (mcp) no llama resolve_search_intent; busca solo la consulta literal
  --token-env N   nombre de la variable de entorno con el token
  --url U         base del Worker (por defecto ${DEFAULT_URL})
  --golden P      lista de referencia (por defecto docs/uat/golden_set_lote001.json)
  --only A,B      solo esos test_id
  --delay MS      pausa entre consultas (por defecto 400)
  --save P        guarda la corrida (respuestas resumidas + métricas), sin token
  --from P        recalcula métricas desde una corrida guardada, sin red
  --compare P     compara contra otra corrida guardada
  --json          imprime el resumen como JSON`);
}

function fail(msg) {
	console.error(`ERROR: ${msg}`);
	process.exit(2);
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ---------------------------------------------------------------- clientes

/** Cliente MCP mínimo (Streamable HTTP, JSON-RPC). Acepta respuesta JSON o SSE. */
class McpClient {
	constructor(url, token) {
		this.url = `${url}/mcp`;
		this.token = token;
		this.sessionId = null;
		this.nextId = 1;
	}

	async rpc(method, params, { notification = false } = {}) {
		const body = notification ? { jsonrpc: '2.0', method, params } : { jsonrpc: '2.0', id: this.nextId++, method, params };
		const headers = { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', Authorization: `Bearer ${this.token}` };
		if (this.sessionId) headers['mcp-session-id'] = this.sessionId;
		const res = await fetch(this.url, { method: 'POST', headers, body: JSON.stringify(body) });
		const sid = res.headers.get('mcp-session-id');
		if (sid) this.sessionId = sid;
		if (res.status === 401) throw new Error('HTTP 401: token rechazado por /mcp');
		if (notification) return null;
		if (!res.ok) throw new Error(`HTTP ${res.status} en ${method}`);
		const text = await res.text();
		const msg = (res.headers.get('content-type') ?? '').includes('text/event-stream') ? parseSse(text, body.id) : JSON.parse(text);
		if (msg?.error) throw new Error(`MCP ${method}: ${msg.error.message ?? JSON.stringify(msg.error)}`);
		return msg?.result;
	}

	async init() {
		await this.rpc('initialize', { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'cpv-evaluate-search', version: '1.0.0' } });
		await this.rpc('notifications/initialized', {}, { notification: true });
	}

	async callTool(name, args) {
		const result = await this.rpc('tools/call', { name, arguments: args });
		const text = result?.content?.find((c) => c.type === 'text')?.text;
		if (result?.isError) throw new Error(`${name}: ${text ?? 'error'}`);
		return text ? JSON.parse(text) : null;
	}
}

function parseSse(text, id) {
	const messages = text
		.split(/\r?\n\r?\n/)
		.map((block) => block.split(/\r?\n/).filter((l) => l.startsWith('data:')).map((l) => l.slice(5).trim()).join(''))
		.filter(Boolean)
		.map((d) => JSON.parse(d));
	return messages.find((m) => m.id === id) ?? messages.at(-1);
}

async function runCaseMcp(client, c, useIntent) {
	let intent = null;
	let resolvedPhrases;
	if (useIntent) {
		intent = await client.callTool('resolve_search_intent', { query: c.query });
		if (intent?.interpretation === 'ambiguous' || intent?.interpretation === 'out_of_scope') {
			// CIRA no busca empresas en estos casos: pregunta o declina.
			return { intent: pickIntent(intent), results: [], searched: false };
		}
		resolvedPhrases = intent?.search_phrases?.length ? intent.search_phrases : undefined;
	}
	const rows = await client.callTool('search_empresas', { query: c.query, ...(resolvedPhrases ? { resolvedPhrases } : {}) });
	if (rows && !Array.isArray(rows)) throw new Error(`search_empresas: ${rows.error ?? 'respuesta inesperada'}`);
	return {
		intent: intent ? pickIntent(intent) : null,
		searched: true,
		// postgres.js devuelve bigint como string: normalizar a número para comparar con la lista.
		results: (rows ?? []).map((r) => ({ empresa_id: Number(r.id), name: r.name, match_type: r.match_type, matched_via: r.matched_via })),
	};
}

async function runCaseDebug(url, token, c) {
	const res = await fetch(`${url}/debug-search`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
		body: JSON.stringify({ query: c.query }),
	});
	if (res.status === 401) throw new Error('HTTP 401: token rechazado por /debug-search');
	if (!res.ok) throw new Error(`HTTP ${res.status} en /debug-search`);
	const j = await res.json();
	const all = (j.candidates ?? []).map((x) => ({ empresa_id: Number(x.empresa_id), name: x.empresa_name, match_type: x.evidence_strength, matched_via: x.explanation ?? null }));
	const direct = j.direct_company_count ?? 0;
	const cut = direct > DEFAULT_LIMIT ? EXACT_LIMIT : DEFAULT_LIMIT;
	return { intent: null, searched: true, direct_company_count: direct, total_candidates: all.length, results: all.slice(0, cut), beyond_cut: all.slice(cut).map((x) => x.empresa_id) };
}

const pickIntent = (i) => ({ interpretation: i.interpretation ?? null, search_phrases: i.search_phrases ?? [], clarification_question: i.clarification_question ?? null });

// ---------------------------------------------------------------- métricas

function caseMetrics(c, run) {
	const ids = (run.results ?? []).map((r) => Number(r.empresa_id));
	const relevant = new Set(c.relevant.map((x) => x.empresa_id));
	const notRelevant = new Set(c.not_relevant.map((x) => x.empresa_id));
	const m = { test_id: c.test_id, query: c.query, expected: c.expected_behavior, returned: ids.length, error: run.error ?? null };
	if (run.error) return m;

	if (c.expected_behavior !== 'results') {
		m.behavior = behaviorCheck(c.expected_behavior, run);
		return m;
	}

	m.behavior = { pass: ids.length > 0, detail: ids.length > 0 ? 'devolvió empresas' : 'no devolvió empresas' };
	const firstRel = ids.findIndex((id) => relevant.has(id));
	m.first_relevant_rank = firstRel >= 0 ? firstRel + 1 : null;
	m.relevant_known = relevant.size;
	m.not_relevant_known = notRelevant.size;
	for (const k of KS) {
		const top = ids.slice(0, k);
		m[`hits_at_${k}`] = top.filter((id) => relevant.has(id)).length;
		m[`noise_at_${k}`] = top.filter((id) => notRelevant.has(id)).length;
		m[`unjudged_at_${k}`] = top.filter((id) => !relevant.has(id) && !notRelevant.has(id)).length;
	}
	m.hits_all = ids.filter((id) => relevant.has(id)).length;
	m.noise_all = ids.filter((id) => notRelevant.has(id)).length;
	m.recall_known_at_10 = relevant.size ? round(m.hits_at_10 / relevant.size) : null;
	m.relevant_missing = [...relevant].filter((id) => !ids.includes(id));
	return m;
}

/** CIRA arma el mensaje final en n8n; acá solo se puede verificar lo que decide el Worker. */
function behaviorCheck(expected, run) {
	const interp = run.intent?.interpretation ?? null;
	const n = (run.results ?? []).length;
	if (expected === 'clarification') {
		if (interp === null) return { pass: null, detail: 'no verificable sin resolve_search_intent' };
		return { pass: interp === 'ambiguous', detail: `interpretación: ${interp}` };
	}
	if (expected === 'out_of_scope_message' || expected === 'institutional_answer_no_companies') {
		if (interp === null) return { pass: n === 0 ? true : null, detail: `sin intención; ${n} empresas` };
		return { pass: interp === 'out_of_scope' || n === 0, detail: `interpretación: ${interp}; ${n} empresas` };
	}
	if (expected === 'no_results') return { pass: n === 0, detail: `${n} empresas` };
	return { pass: null, detail: `comportamiento desconocido: ${expected}` };
}

function aggregate(golden, perCase, runs) {
	const resultsCases = perCase.filter((m) => m.expected === 'results' && !m.error);
	const withRel = resultsCases.filter((m) => m.relevant_known > 0);
	const otherCases = perCase.filter((m) => m.expected !== 'results' && !m.error);
	const mean = (xs) => (xs.length ? round(xs.reduce((s, x) => s + x, 0) / xs.length) : null);
	const out = {
		cases_run: perCase.length,
		errors: perCase.filter((m) => m.error).map((m) => m.test_id),
		results_cases: resultsCases.length,
		cases_with_known_relevant: withRel.length,
		mean_returned: mean(resultsCases.map((m) => m.returned)),
		pct_relevant_in_top5: withRel.length ? round(withRel.filter((m) => m.hits_at_5 > 0).length / withRel.length) : null,
		mrr_known: mean(withRel.map((m) => (m.first_relevant_rank ? 1 / m.first_relevant_rank : 0))),
		mean_recall_known_at_10: mean(withRel.map((m) => m.recall_known_at_10)),
		total_hits_at_10: withRel.reduce((s, m) => s + m.hits_at_10, 0),
		total_relevant_known: withRel.reduce((s, m) => s + m.relevant_known, 0),
		total_noise_at_5: resultsCases.reduce((s, m) => s + m.noise_at_5, 0),
		total_noise_at_10: resultsCases.reduce((s, m) => s + m.noise_at_10, 0),
		total_not_relevant_known: resultsCases.reduce((s, m) => s + m.not_relevant_known, 0),
		behavior_pass: otherCases.filter((m) => m.behavior?.pass === true).map((m) => m.test_id),
		behavior_fail: otherCases.filter((m) => m.behavior?.pass === false).map((m) => m.test_id),
		behavior_unverifiable: otherCases.filter((m) => m.behavior?.pass === null).map((m) => m.test_id),
	};
	// Empresas que el cliente marcó como ruido en varias consultas: ¿en cuántas aparecen hoy en el top 10
	// de una consulta donde NO fueron juzgadas relevantes? (LUKIVEN, p. ej., es ruido en unas y relevante en otras.)
	const relevantIn = new Map(golden.cases.map((c) => [c.test_id, new Set(c.relevant.map((x) => x.empresa_id))]));
	out.recurring_noise_in_top10 = (golden.recurring_noise_companies ?? []).map((e) => ({
		empresa_id: e.empresa_id,
		db_name: e.db_name,
		top10_in: Object.entries(runs)
			.filter(([id, r]) => !relevantIn.get(id)?.has(e.empresa_id) && (r.results ?? []).slice(0, 10).some((x) => Number(x.empresa_id) === e.empresa_id))
			.map(([id]) => id),
	}));
	return out;
}

const round = (x) => Math.round(x * 1000) / 1000;

// ---------------------------------------------------------------- comparación

const CASE_FIELDS = ['returned', 'first_relevant_rank', 'hits_at_5', 'hits_at_10', 'noise_at_5', 'noise_at_10'];
const SUMMARY_FIELDS = ['pct_relevant_in_top5', 'mrr_known', 'mean_recall_known_at_10', 'total_hits_at_10', 'total_noise_at_5', 'total_noise_at_10', 'mean_returned'];
// Dirección "mejor": +1 si subir es mejor, -1 si bajar es mejor.
const BETTER = { hits_at_5: 1, hits_at_10: 1, noise_at_5: -1, noise_at_10: -1, pct_relevant_in_top5: 1, mrr_known: 1, mean_recall_known_at_10: 1, total_hits_at_10: 1, total_noise_at_5: -1, total_noise_at_10: -1 };

function compare(base, current) {
	const baseById = new Map(base.per_case.map((m) => [m.test_id, m]));
	const caseChanges = [];
	for (const m of current.per_case) {
		const b = baseById.get(m.test_id);
		if (!b) continue;
		const diffs = [];
		for (const f of CASE_FIELDS) if (b[f] !== m[f]) diffs.push({ field: f, before: b[f] ?? null, after: m[f] ?? null, verdict: verdict(f, b[f], m[f]) });
		if (b.behavior?.pass !== m.behavior?.pass) diffs.push({ field: 'behavior', before: b.behavior?.pass ?? null, after: m.behavior?.pass ?? null, verdict: m.behavior?.pass === true ? 'mejor' : m.behavior?.pass === false ? 'peor' : 'cambio' });
		if (diffs.length) caseChanges.push({ test_id: m.test_id, query: m.query, diffs });
	}
	const summary = SUMMARY_FIELDS.map((f) => ({ field: f, before: base.summary[f], after: current.summary[f], verdict: verdict(f, base.summary[f], current.summary[f]) }));
	const regressions = caseChanges.filter((c) => c.diffs.some((d) => d.verdict === 'peor')).map((c) => c.test_id);
	return { base_label: base.meta?.label ?? null, summary, case_changes: caseChanges, regressions };
}

function verdict(field, before, after) {
	if (before === after) return 'igual';
	const dir = BETTER[field];
	if (field === 'first_relevant_rank') {
		if (before == null) return 'mejor';
		if (after == null) return 'peor';
		return after < before ? 'mejor' : 'peor';
	}
	if (!dir || before == null || after == null) return 'cambio';
	return (after - before) * dir > 0 ? 'mejor' : 'peor';
}

// ---------------------------------------------------------------- salida

function printReport(report, cmp) {
	const s = report.summary;
	console.log(`\nCorrida: modo=${report.meta.mode}${report.meta.intent === false ? ' (sin intención)' : ''} · ${report.meta.ran_at} · lista ${report.meta.golden_file}`);
	console.log('\nCaso   Dev  1ªrel  Aciertos@5/@10  Ruido@5/@10  Consulta');
	for (const m of report.per_case) {
		const id = m.test_id.padEnd(6);
		if (m.error) {
			console.log(`${id} ERROR ${m.error}`);
			continue;
		}
		if (m.expected !== 'results') {
			const p = m.behavior?.pass === true ? 'OK' : m.behavior?.pass === false ? 'FALLA' : '¿?';
			console.log(`${id} ${String(m.returned).padStart(3)}  [${m.expected}: ${p} - ${m.behavior?.detail}]  ${m.query}`);
			continue;
		}
		const rel = m.relevant_known ? `${m.hits_at_5}/${m.hits_at_10} de ${m.relevant_known}` : 'sin juicios +';
		const noise = m.not_relevant_known ? `${m.noise_at_5}/${m.noise_at_10} de ${m.not_relevant_known}` : 'sin juicios -';
		console.log(`${id} ${String(m.returned).padStart(3)}  ${String(m.first_relevant_rank ?? '-').padStart(5)}  ${rel.padEnd(14)}  ${noise.padEnd(11)}  ${m.query}`);
	}
	console.log('\nResumen');
	console.log(`  Casos con relevante conocida en el top 5:   ${pct(s.pct_relevant_in_top5)}  (${s.cases_with_known_relevant} casos con juicios positivos)`);
	console.log(`  MRR sobre relevantes conocidas:             ${s.mrr_known}`);
	console.log(`  Relevantes conocidas recuperadas en top 10: ${s.total_hits_at_10} de ${s.total_relevant_known}  (media por caso ${pct(s.mean_recall_known_at_10)})`);
	console.log(`  Ruido conocido en top 5 / top 10:           ${s.total_noise_at_5} / ${s.total_noise_at_10}  (de ${s.total_not_relevant_known} marcadas como no relevantes)`);
	console.log(`  Empresas devueltas por consulta (media):    ${s.mean_returned}`);
	console.log(`  Comportamientos esperados OK / falla / ¿?:  ${s.behavior_pass.length} / ${s.behavior_fail.length} / ${s.behavior_unverifiable.length}${s.behavior_fail.length ? '  falla: ' + s.behavior_fail.join(', ') : ''}`);
	if (s.errors.length) console.log(`  Errores: ${s.errors.join(', ')}`);
	const noisy = s.recurring_noise_in_top10.filter((e) => e.top10_in.length);
	if (noisy.length) {
		console.log('  Ruido recurrente aún en top 10:');
		for (const e of noisy) console.log(`    ${e.empresa_id} ${e.db_name}: ${e.top10_in.join(', ')}`);
	}
	if (cmp) {
		console.log(`\nComparación contra ${cmp.base_label ?? 'la corrida base'}`);
		for (const d of cmp.summary) if (d.verdict !== 'igual') console.log(`  ${d.field}: ${d.before} → ${d.after}  (${d.verdict})`);
		for (const c of cmp.case_changes) console.log(`  ${c.test_id} ${c.query}: ${c.diffs.map((d) => `${d.field} ${d.before}→${d.after} (${d.verdict})`).join('; ')}`);
		console.log(cmp.regressions.length ? `  Casos que empeoran: ${cmp.regressions.join(', ')}` : '  Ningún caso empeora.');
	}
}

const pct = (x) => (x == null ? '-' : `${Math.round(x * 100)}%`);

// ---------------------------------------------------------------- main

async function main() {
	const args = parseArgs(process.argv.slice(2));
	const golden = JSON.parse(readFileSync(args.golden, 'utf8'));
	let cases = golden.cases;
	if (args.only) cases = cases.filter((c) => args.only.has(c.test_id));
	if (!cases.length) fail('Ningún caso para correr.');

	let runs;
	let meta;
	if (args.from) {
		const saved = JSON.parse(readFileSync(args.from, 'utf8'));
		runs = saved.runs;
		meta = { ...saved.meta, recomputed_at: new Date().toISOString() };
		cases = cases.filter((c) => runs[c.test_id]);
	} else {
		const token = process.env[args.tokenEnv];
		if (!token) fail(`Falta la variable de entorno ${args.tokenEnv} con el token del modo ${args.mode}.`);
		runs = {};
		let client = null;
		if (args.mode === 'mcp') {
			client = new McpClient(args.url, token);
			await client.init();
		}
		for (const [i, c] of cases.entries()) {
			process.stderr.write(`[${i + 1}/${cases.length}] ${c.test_id} ${c.query}\n`);
			try {
				runs[c.test_id] = args.mode === 'mcp' ? await runCaseMcp(client, c, args.intent) : await runCaseDebug(args.url, token, c);
			} catch (e) {
				runs[c.test_id] = { error: String(e.message ?? e) };
				if (/401/.test(runs[c.test_id].error)) fail(runs[c.test_id].error);
			}
			if (i < cases.length - 1) await sleep(args.delayMs);
		}
		meta = { mode: args.mode, intent: args.mode === 'mcp' ? args.intent : null, url: args.url, ran_at: new Date().toISOString(), label: args.save ? args.save.split(/[\\/]/).pop() : null };
	}
	meta.golden_file = args.golden.split(/[\\/]/).pop();
	meta.golden_generated_on = golden.source?.generated_on ?? null;

	const perCase = cases.map((c) => caseMetrics(c, runs[c.test_id]));
	const report = { meta, summary: aggregate(golden, perCase, runs), per_case: perCase, runs };

	const cmp = args.compare ? compare(JSON.parse(readFileSync(args.compare, 'utf8')), report) : null;
	if (cmp) report.comparison = cmp;

	if (args.save) {
		writeFileSync(args.save, JSON.stringify(report, null, 2) + '\n', 'utf8');
		process.stderr.write(`Corrida guardada en ${args.save}\n`);
	}
	if (args.json) console.log(JSON.stringify({ meta, summary: report.summary, comparison: cmp }, null, 2));
	else printReport(report, cmp);

	process.exit(cmp?.regressions.length ? 1 : 0);
}

main().catch((e) => fail(e.stack ?? String(e)));
