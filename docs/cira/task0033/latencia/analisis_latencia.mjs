// TASK-0033 E4: análisis de latencia de CIRA a partir de datos ya existentes (solo lectura, sin tráfico nuevo).
// Entradas:
//   - <timings_vivo.json>: salida de docs/cira/task0030/latencia/n8n_timings.mjs sobre el flujo vivo desde 2d0a1ee8.
//   - docs/cira/task0032/resultados/ejecuciones_{V0,V1b_r1,V1b_r2,V1b_r3}.json: copias aisladas de TASK-0032.
// Uso: node analisis_latencia.mjs <timings_vivo.json> <salida.json>
import fs from 'node:fs';
import path from 'node:path';

const [vivoPath, outPath] = process.argv.slice(2);
const here = path.dirname(new URL(import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1'));
const t32 = path.resolve(here, '../../task0032/resultados');
const pct = (v, p) => {
	const s = v.filter((x) => x != null).sort((a, b) => a - b);
	if (!s.length) return null;
	const i = (s.length - 1) * p;
	const lo = Math.floor(i), hi = Math.ceil(i);
	return Math.round(s[lo] + (s[hi] - s[lo]) * (i - lo));
};
const stats = (v) => {
	const s = v.filter((x) => x != null);
	return { n: s.length, p50: pct(s, 0.5), p95: pct(s, 0.95), min: s.length ? Math.min(...s) : null, max: s.length ? Math.max(...s) : null, iqr: s.length ? pct(s, 0.75) - pct(s, 0.25) : null };
};
const stage = {
	total_n8n: (x) => x.total_ms,
	agente: (x) => x.nodos_ms['Interactive Intent Agent3'],
	modelo: (x) => x.nodos_ms['OpenAI Chat Model'],
	agente_sin_modelo: (x) => (x.nodos_ms['Interactive Intent Agent3'] ?? 0) - (x.nodos_ms['OpenAI Chat Model'] ?? 0),
	worker: (x) => x.nodos_ms['Call MCP Empresas'],
	registro: (x) => x.nodos_ms['Log Search'] || null,
	memoria_y_aprendizajes: (x) => (x.nodos_ms['Postgres Memory Intent3'] ?? 0) + (x.nodos_ms['Load Learnings'] ?? 0),
	tarjetas: (x) => x.nodos_ms['Format Cards2'],
};
const resume = (xs) => Object.fromEntries(Object.entries(stage).map(([k, f]) => [k, stats(xs.map(f))]));

// 1) Flujo vivo (2d0a1ee8).
const vivo = JSON.parse(fs.readFileSync(vivoPath, 'utf8')).filter((x) => x.started >= '2026-11-16T19:12:49Z'); // la API no trae versionId por ejecución: corte por la hora de publicación de 2d0a1ee8
const busqueda = (x) => x.tool === 'search_empresas';
const ficha = (x) => x.tool === 'get_empresa';
const uat = vivo.filter((x) => x.started >= '2026-11-17');
const e2e = vivo.filter((x) => x.started < '2026-11-17');

// 2) Tiempos de navegador informados por el propietario (6945403230) y su ejecución en n8n.
const navegador = [
	{ escenario: 'Empresas de perforación', navegador_ms: 12780, ejecucion: '23158' },
	{ escenario: 'Balancín', navegador_ms: 10740, ejecucion: '23159' },
	{ escenario: 'Macolla', navegador_ms: 8240, ejecucion: '23160' },
	{ escenario: 'Camiones de transporte de maquinaria pesada', navegador_ms: 10740, ejecucion: '23161' },
	{ escenario: 'Búsqueda directa de empresa', navegador_ms: 10080, ejecucion: '23163' },
].map((r) => {
	const x = vivo.find((e) => String(e.execution) === r.ejecucion);
	return { ...r, consulta_n8n: x?.consulta, inicio_n8n: x?.started, n8n_ms: x?.total_ms, diferencia_navegador_menos_n8n_ms: x ? r.navegador_ms - x.total_ms : null, agente_ms: stage.agente(x), modelo_ms: stage.modelo(x), worker_ms: stage.worker(x), registro_ms: stage.registro(x) };
});

// 3) Copias aisladas de TASK-0032 (mismo modelo; V0 = con resolve_search_intent, V1b = sin).
const bench = {};
for (const v of ['V0', 'V1b_r1', 'V1b_r2', 'V1b_r3']) {
	const rows = JSON.parse(fs.readFileSync(path.join(t32, `ejecuciones_${v}.json`), 'utf8')).filter((r) => r.exec?.nodos_ms);
	const xs = rows.map((r) => ({ ...r.exec, rep: r.rep, client_ms: r.client_ms, tool: r.exec.nodos_ms['Call MCP Empresas'] != null ? 'search_empresas' : null }));
	const red = rows.map((r) => (r.client_ms != null && r.exec.total_ms != null ? r.client_ms - r.exec.total_ms : null));
	bench[v] = {
		todas: resume(xs),
		worker_por_repeticion: Object.fromEntries([...new Set(xs.map((x) => x.rep))].sort().map((rep) => [`rep_${rep}`, stats(xs.filter((x) => x.rep === rep).map(stage.worker))])),
		cliente_menos_n8n_ms: stats(red),
	};
}

const out = {
	generado: new Date().toISOString(),
	fuentes: { vivo: path.basename(vivoPath), aisladas: 'docs/cira/task0032/resultados/ejecuciones_*.json' },
	vivo_2d0a1ee8: {
		uat_propietario_2026_11_17: { busquedas: resume(uat.filter(busqueda)), fichas: resume(uat.filter(ficha)), ejecuciones: uat.map((x) => x.execution) },
		e2e_2026_11_16: { busquedas: resume(e2e.filter(busqueda)), fichas: resume(e2e.filter(ficha)), ejecuciones: e2e.map((x) => x.execution) },
	},
	navegador_frente_a_n8n: navegador,
	navegador_resumen: { navegador: stats(navegador.map((r) => r.navegador_ms)), n8n: stats(navegador.map((r) => r.n8n_ms)), diferencia: stats(navegador.map((r) => r.diferencia_navegador_menos_n8n_ms)) },
	copias_aisladas_task0032: bench,
};
fs.writeFileSync(outPath, JSON.stringify(out, null, 1));
const fmt = (s) => `p50 ${s.p50} p95 ${s.p95} [${s.min}-${s.max}] n=${s.n}`;
console.log('UAT busquedas', Object.entries(out.vivo_2d0a1ee8.uat_propietario_2026_11_17.busquedas).map(([k, s]) => `${k}: ${fmt(s)}`).join(' | '));
console.log('E2E busquedas', Object.entries(out.vivo_2d0a1ee8.e2e_2026_11_16.busquedas).map(([k, s]) => `${k}: ${fmt(s)}`).join(' | '));
console.log('UAT fichas', Object.entries(out.vivo_2d0a1ee8.uat_propietario_2026_11_17.fichas).map(([k, s]) => `${k}: ${fmt(s)}`).join(' | '));
for (const r of navegador) console.log(r.escenario, r.navegador_ms, r.n8n_ms, r.diferencia_navegador_menos_n8n_ms, 'worker', r.worker_ms, 'modelo', r.modelo_ms);
for (const [v, b] of Object.entries(bench)) console.log(v, 'total', fmt(b.todas.total_n8n), '| modelo', fmt(b.todas.modelo), '| worker', fmt(b.todas.worker), '| por rep', JSON.stringify(Object.fromEntries(Object.entries(b.worker_por_repeticion).map(([k, s]) => [k, s.p50]))), '| cliente-n8n', fmt(b.cliente_menos_n8n_ms));
