// TASK-0033 E5: ids, grupo (capacidad/experiencia) y tipos de evidencia de una ejecución de CIRA (solo lectura, API de n8n).
// No imprime nombres ni textos de experiencias: solo ids, grupo y tipo/fuente de la coincidencia.
// Uso: N8N_BASE_URL=... N8N_API_KEY=... node n8n_resultado_grupos.mjs <ejecucion> [<salida.json>]
import fs from 'node:fs';

const [id, outPath] = process.argv.slice(2);
const base = process.env.N8N_BASE_URL.replace(/\/$/, '');
const d = await (await fetch(`${base}/api/v1/executions/${id}?includeData=true`, { headers: { 'X-N8N-API-KEY': process.env.N8N_API_KEY } })).json();
const rd = d.data?.resultData?.runData ?? {};
const u = rd['Unwrap MCP Result']?.[0]?.data?.main?.[0]?.[0]?.json ?? {};
const keys = Object.keys(u);
const rows = Array.isArray(u.rows) ? u.rows : [];
const tipo = (v) => (v && typeof v === 'object' ? Object.keys(v) : v);
const out = {
	ejecucion: id,
	inicio: d.startedAt,
	claves: keys,
	filas: rows.map((r) => ({ id: r.id, claves: Object.keys(r), grupo: r.grupo ?? r.group ?? null, score: r.score ?? null, evidencia: tipo(r.explain ?? r.evidence ?? r.matches ?? null) })),
	grupos: u.grupos ?? u.groups ? Object.fromEntries(Object.entries(u.grupos ?? u.groups).map(([k, v]) => [k, Array.isArray(v) ? v.map((x) => (typeof x === 'object' ? { id: x.id, fuentes: tipo(x.explain ?? x.evidence ?? x.matches ?? x.fuentes ?? null) } : x)) : tipo(v)])) : null,
};
if (outPath) fs.writeFileSync(outPath, JSON.stringify(out, null, 1));
console.log(JSON.stringify(out, null, 1).slice(0, 6000));
