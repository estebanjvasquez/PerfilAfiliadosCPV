// TASK-0031: compara las variantes recogidas por collect.mjs. Etiquetas del cliente de TASK-0028 (no se reetiqueta).
// Uso: node eval_variants.mjs <salida.json> <col_V0.json> <col_V1.json> ...   (la primera es la referencia)
import fs from 'node:fs';
import path from 'node:path';

const [outPath, ...files] = process.argv.slice(2);
const cases = JSON.parse(fs.readFileSync(new URL('../../task0028/experimentos/cases.json', import.meta.url), 'utf8'));
const golden = new Map(cases.map((c) => [c.id, c.golden ?? null]));
const fold = (s) => String(s ?? '').normalize('NFD').replace(/\p{M}/gu, '').toLowerCase().trim();
const pct = (v, p) => {
	const s = v.filter((x) => x != null).sort((a, b) => a - b);
	return s.length ? s[Math.min(s.length - 1, Math.floor((p / 100) * s.length))] : null;
};
const N = {
	agente: 'Interactive Intent Agent3',
	modelo: 'OpenAI Chat Model',
	herramienta_mcp: 'MCP Perfil Afiliados CPV',
	worker: 'Call MCP Empresas',
	tarjetas: 'Format Cards2',
	learnings: 'Load Learnings',
	memoria: 'Postgres Memory Intent3',
};
const variants = files.map((f) => ({ name: path.basename(f).replace(/^col_|\.json$/g, ''), runs: JSON.parse(fs.readFileSync(f, 'utf8')) }));
const ref = variants[0];
const sig = (r) => (r?.exec?.params ? JSON.stringify({ q: fold(r.exec.params.query), p: [...new Set((r.exec.params.resolvedPhrases ?? []).map(fold))].sort(), t: r.exec.tool }) : 'sin_busqueda:' + (r?.type ?? ''));
const g1 = (r) => (r?.exec?.rows ?? []).filter((x) => x.grupo === 'capacidad').map((x) => x.id);
const g2 = (r) => (r?.exec?.rows ?? []).filter((x) => x.grupo === 'experiencia').map((x) => x.id);

const res = { referencia: ref.name, variantes: {}, por_consulta: {} };
for (const v of variants) {
	const all = v.runs.filter((r) => r.exec);
	const corpus = v.runs.filter((r) => /^[LS]\d/.test(r.id));
	const uat = v.runs.filter((r) => /^U\d/.test(r.id));
	const lat = (rs) => ({
		n: rs.length,
		total_p50: pct(rs.map((r) => r.exec?.total_ms), 50),
		total_p95: pct(rs.map((r) => r.exec?.total_ms), 95),
		cliente_p50: pct(rs.map((r) => r.client_ms), 50),
		cliente_p95: pct(rs.map((r) => r.client_ms), 95),
		...Object.fromEntries(Object.entries(N).map(([k, n]) => [k + '_p50', pct(rs.map((r) => r.exec?.nodos_ms?.[n]), 50)])),
		registro_p50: pct(rs.map((r) => r.exec?.nodos_ms?.['Log Search']), 50),
	});
	const q = { rel_top5_g1: 0, norel_top5_g1: 0, sin_juicio_top5_g1: 0, rel_cobertura: 0, rel_etiquetadas: 0, vacias: 0, sin_busqueda: [], errores: [] };
	for (const r of corpus) {
		if (!r.exec || r.exec.status !== 'success') q.errores.push(r.id);
		if (!r.exec?.params) q.sin_busqueda.push(r.id);
		const a = g1(r), b = g2(r);
		if (a.length + b.length === 0) q.vacias++;
		const g = golden.get(r.id);
		if (g) {
			q.rel_etiquetadas += g.rel.length;
			q.rel_top5_g1 += a.slice(0, 5).filter((x) => g.rel.includes(x)).length;
			q.norel_top5_g1 += a.slice(0, 5).filter((x) => g.norel.includes(x)).length;
			q.sin_juicio_top5_g1 += a.slice(0, 5).filter((x) => !g.rel.includes(x) && !g.norel.includes(x)).length;
			const cover = new Set([...a.slice(0, 10), ...b.slice(0, 10)]);
			q.rel_cobertura += g.rel.filter((x) => cover.has(x)).length;
		}
	}
	q.precision_juzgada_g1 = q.rel_top5_g1 + q.norel_top5_g1 ? Math.round((1000 * q.rel_top5_g1) / (q.rel_top5_g1 + q.norel_top5_g1)) / 10 : null;
	// coincidencia con la referencia (corpus): mismos parámetros del agente y mismo top 5 de cada grupo
	let mismosParams = 0, mismoTop5 = 0, comparables = 0;
	for (const r of corpus) {
		const o = ref.runs.find((x) => x.id === r.id && x.rep === r.rep);
		if (!o) continue;
		comparables++;
		if (sig(r) === sig(o)) mismosParams++;
		if (JSON.stringify([g1(r).slice(0, 5), g2(r).slice(0, 5)]) === JSON.stringify([g1(o).slice(0, 5), g2(o).slice(0, 5)])) mismoTop5++;
	}
	const llm = all.map((r) => r.exec.llamadas_modelo);
	res.variantes[v.name] = {
		ejecuciones: all.length,
		latencia_corpus: lat(corpus),
		latencia_uat: lat(uat),
		latencia_uat_por_consulta: Object.fromEntries([...new Set(uat.map((r) => r.input))].map((inp) => [inp, uat.filter((r) => r.input === inp).map((r) => r.exec?.total_ms)])),
		llamadas_modelo_media: Math.round((10 * llm.reduce((a, b) => a + b, 0)) / Math.max(1, llm.length)) / 10,
		llamadas_herramienta_mcp_media: Math.round((10 * all.reduce((a, r) => a + r.exec.llamadas_herramienta_mcp, 0)) / Math.max(1, all.length)) / 10,
		tokens_media: {
			prompt: Math.round(all.reduce((a, r) => a + r.exec.tokens.prompt, 0) / Math.max(1, all.length)),
			completion: Math.round(all.reduce((a, r) => a + r.exec.tokens.completion, 0) / Math.max(1, all.length)),
		},
		calidad_corpus: q,
		coincidencia_con_referencia: { comparables, mismos_parametros: mismosParams, mismo_top5_por_grupo: mismoTop5 },
	};
	for (const r of corpus) {
		const e = (res.por_consulta[r.id] ??= { input: r.input, golden: golden.get(r.id) });
		e[v.name] = { ms: r.exec?.total_ms ?? null, params: r.exec?.params ?? null, tipo: r.type, g1_top5: g1(r).slice(0, 5), g2_top5: g2(r).slice(0, 5), n: (r.exec?.rows ?? []).length };
	}
}
fs.writeFileSync(outPath, JSON.stringify(res, null, 1));
for (const [k, v] of Object.entries(res.variantes)) {
	const c = v.latencia_corpus, u = v.latencia_uat, q = v.calidad_corpus;
	console.log(
		`${k.padEnd(6)} corpus p50/p95 ${c.total_p50}/${c.total_p95} | uat p50/p95 ${u.total_p50}/${u.total_p95} | agente ${c.agente_p50} modelo ${c.modelo_p50} mcp ${c.herramienta_mcp_p50} worker ${c.worker_p50} | llm ${v.llamadas_modelo_media} tok ${v.tokens_media.prompt}/${v.tokens_media.completion} | ruido ${q.norel_top5_g1} rel ${q.rel_top5_g1} prec ${q.precision_juzgada_g1} cob ${q.rel_cobertura}/${q.rel_etiquetadas} vacias ${q.vacias} sinbusq ${q.sin_busqueda.length} err ${q.errores.length} | =ref params ${v.coincidencia_con_referencia.mismos_parametros} top5 ${v.coincidencia_con_referencia.mismo_top5_por_grupo}/${v.coincidencia_con_referencia.comparables}`
	);
}
