// TASK-0030: medianas por tramo de las ejecuciones de búsqueda (search_empresas) de CIRA Test, por período.
// Uso: node resumen.mjs <timings_antes.json> <timings_despues.json> <salida.json>
import fs from 'node:fs';
const [a, b, out] = process.argv.slice(2);
const all = [...JSON.parse(fs.readFileSync(a, 'utf8')), ...JSON.parse(fs.readFileSync(b, 'utf8'))];
const uniq = [...new Map(all.map((x) => [x.execution, x])).values()].filter((x) => x.tool === 'search_empresas' && x.nodos_ms['Call MCP Empresas'] != null && !/^dame información/.test(x.consulta ?? ''));
const period = (x) => (x.started < '2026-11-16T11:18:47Z' ? 'antes_T29 (bafd597a, Worker 1005d61f)' : x.started < '2026-11-16T15:01:54Z' ? 'T29 (d2f8efb5, Worker 8796f292)' : 'T30 (7feb48bc, Worker 8796f292)');
const med = (v) => { const s = v.filter((x) => x != null).sort((p, q) => p - q); return s.length ? s[Math.floor(s.length / 2)] : null; };
const stages = { total: (x) => x.total_ms, agente: (x) => x.nodos_ms['Interactive Intent Agent3'], modelo: (x) => x.nodos_ms['OpenAI Chat Model'], herramienta_mcp_del_agente: (x) => x.nodos_ms['MCP Perfil Afiliados CPV'], worker_search_empresas: (x) => x.nodos_ms['Call MCP Empresas'], registro: (x) => x.nodos_ms['Log Search'], tarjetas: (x) => x.nodos_ms['Format Cards2'], learnings_y_memoria: (x) => (x.nodos_ms['Load Learnings'] ?? 0) + (x.nodos_ms['Postgres Memory Intent3'] ?? 0) };
const res = {};
for (const p of [...new Set(uniq.map(period))]) {
	const xs = uniq.filter((x) => period(x) === p);
	res[p] = { ejecuciones: xs.length, mediana_ms: Object.fromEntries(Object.entries(stages).map(([k, f]) => [k, med(xs.map(f))])) };
	const m = res[p].mediana_ms;
	res[p].proporcion_agente = Math.round((100 * m.agente) / m.total) + ' %';
}
res.consultas_de_la_asignacion = uniq.filter((x) => /perforacion|^balancin$|^macolla|^gandola/i.test(x.consulta)).map((x) => ({ ejecucion: x.execution, hora: x.started, periodo: period(x).split(' ')[0], consulta: x.consulta, resultados: x.resultados, total: x.total_ms, agente: stages.agente(x), worker: stages.worker_search_empresas(x), registro: stages.registro(x), tarjetas: stages.tarjetas(x) }));
fs.writeFileSync(out, JSON.stringify(res, null, 1));
for (const [k, v] of Object.entries(res)) if (k !== 'consultas_de_la_asignacion') console.log(k, JSON.stringify(v));
