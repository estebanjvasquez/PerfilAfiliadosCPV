#!/usr/bin/env node
/**
 * Compara dos corridas de measure_cira_n8n.mjs (antes / después) caso por caso:
 * qué tool eligió el agente, qué consulta mandó, cuánto varía entre repeticiones y cuántas empresas
 * devolvió. Las métricas contra la lista de referencia se calculan aparte con
 * `evaluate_search.mjs --from <corrida>.repN.json --compare ...`.
 *
 *   node compare_cira_n8n.mjs antes.json despues.json [--json]
 */
import { readFileSync } from 'node:fs';

const [, , beforePath, afterPath, flag] = process.argv;
if (!beforePath || !afterPath) {
	console.error('Uso: node compare_cira_n8n.mjs antes.json despues.json [--json]');
	process.exit(2);
}
const load = (p) => JSON.parse(readFileSync(p, 'utf8').replace(/^﻿/, ''));
const before = load(beforePath);
const after = load(afterPath);

const norm = (s) => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();
const STOP = new Set(['de', 'del', 'la', 'las', 'el', 'los', 'y', 'e', 'en', 'para', 'por', 'con', 'a', 'al', 'que']);
const contentWords = (s) => norm(s).split(' ').filter((t) => t && !STOP.has(t));

function signature(call) {
	if (call.error) return `ERROR`;
	if (!call.tool) return `texto`;
	const p = call.params ?? {};
	const parts = [call.tool, p.query ?? p.nombre ?? p.rif ?? ''];
	if (p.ciudad) parts.push(`ciudad=${p.ciudad}`);
	return parts.join(' | ');
}

/** La consulta mandada perdió palabras de contenido del texto del usuario (fuera de la ciudad). */
function reduced(call) {
	if (call.tool !== 'search_empresas' || !call.params?.query) return false;
	const sent = new Set(contentWords(call.params.query).map((t) => t.replace(/(es|s)$/, '')));
	const city = new Set(contentWords(call.params.ciudad ?? ''));
	const filler = new Set(['empresas', 'empresa', 'necesito', 'busco', 'quiero', 'dame', 'fabriquen', 'mantengan']);
	const lost = contentWords(call.input).filter((t) => !city.has(t) && !filler.has(t) && !sent.has(t.replace(/(es|s)$/, '')));
	return lost.length > 0 ? lost : false;
}

function summarize(run) {
	const byCase = new Map();
	for (const c of run.calls) {
		if (!byCase.has(c.case_id)) byCase.set(c.case_id, { case_id: c.case_id, input: c.input, kind: c.kind, calls: [] });
		byCase.get(c.case_id).calls.push(c);
	}
	for (const s of byCase.values()) {
		const sigs = s.calls.map(signature);
		s.variants = [...new Set(sigs)];
		s.counts = s.calls.map((c) => (c.error ? 'E' : c.results?.length ?? 0));
		s.reduced = s.calls.map(reduced).filter(Boolean);
		s.direct = s.calls.filter((c) => c.agent_direct_search).length;
		s.restored = s.calls.filter((c) => c.query_restored_from).map((c) => c.query_restored_from);
		s.errors = s.calls.filter((c) => c.error).length;
	}
	const all = run.calls;
	return {
		byCase,
		totals: {
			calls: all.length,
			errors: all.filter((c) => c.error).length,
			cases: byCase.size,
			unstable_cases: [...byCase.values()].filter((s) => s.variants.length > 1).map((s) => s.case_id),
			reduced_calls: all.filter((c) => reduced(c)).length,
			direct_agent_searches: all.filter((c) => c.agent_direct_search).length,
			restored_by_guard: all.filter((c) => c.query_restored_from).length,
			prompt_versions: [...new Set(all.map((c) => c.prompt_version).filter(Boolean))],
		},
	};
}

const A = summarize(before);
const B = summarize(after);
const ids = [...new Set([...A.byCase.keys(), ...B.byCase.keys()])];
const rows = ids.map((id) => {
	const a = A.byCase.get(id);
	const b = B.byCase.get(id);
	return { case_id: id, input: (a ?? b).input, kind: (a ?? b).kind, before: a && { variants: a.variants, counts: a.counts, reduced: a.reduced, direct: a.direct }, after: b && { variants: b.variants, counts: b.counts, reduced: b.reduced, direct: b.direct, restored: b.restored } };
});

if (flag === '--json') {
	console.log(JSON.stringify({ before: { meta: before.meta, totals: A.totals }, after: { meta: after.meta, totals: B.totals }, cases: rows }, null, 1));
} else {
	const t = (x) => `llamadas ${x.calls}, errores ${x.errors}, casos inestables ${x.unstable_cases.length}, consultas recortadas ${x.reduced_calls}, búsquedas directas del agente ${x.direct_agent_searches}, restituidas por la guarda ${x.restored_by_guard}`;
	console.log(`ANTES   ${before.meta.workflow_name} (${before.meta.workflow_version_id}): ${t(A.totals)}`);
	console.log(`DESPUÉS ${after.meta.workflow_name} (${after.meta.workflow_version_id}): ${t(B.totals)}\n`);
	for (const r of rows) {
		console.log(`${r.case_id}  «${r.input}»`);
		for (const [label, s] of [['  antes  ', r.before], ['  después', r.after]]) {
			if (!s) continue;
			const extra = [s.reduced?.length ? `recortada: ${s.reduced.map((l) => l.join(' ')).join(' / ')}` : null, s.direct ? `búsqueda directa x${s.direct}` : null, s.restored?.length ? `restituida desde: ${s.restored.join(', ')}` : null].filter(Boolean).join('; ');
			console.log(`${label} [${s.counts.join(',')}] ${s.variants.join('  ||  ')}${extra ? '  (' + extra + ')' : ''}`);
		}
	}
}
