// TASK-0032 fase B: casos contra el webhook de una COPIA AISLADA (build_detail.mjs --copia-prueba) y recogida de la
// ejecución de n8n (tool, params, filas, nodos). Sesión nueva por caso. La copia no registra.
// Uso: N8N_BASE_URL=... N8N_API_KEY=... node test_detail_copy.mjs <webhook_path> <workflowId> <casos.json> <salida.json>
//   casos: [{"id":"B01","input":"...","espera":"..."}]
import fs from 'node:fs';

const [hookPath, wf, casesPath, outPath] = process.argv.slice(2);
const base = process.env.N8N_BASE_URL.replace(/\/$/, '');
const h = { 'X-N8N-API-KEY': process.env.N8N_API_KEY };
const cases = JSON.parse(fs.readFileSync(casesPath, 'utf8'));
const out = [];
for (const c of cases) {
	const sessionId = `t32b-${c.id}-${Date.now()}`;
	const t0 = Date.now();
	const r = await fetch(`${base}/webhook/${hookPath}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ chatInput: c.input, sessionId }) });
	const body = await r.json().catch(() => ({}));
	const html = String(body.output ?? '');
	out.push({
		...c,
		sessionId,
		ms: Date.now() - t0,
		status: r.status,
		type: body.type ?? null,
		no_afiliada: /Empresa no afiliada/.test(html),
		informacion_de: /Informaci(?:ó|&oacute;|\\u00f3)n de/.test(html) || /Información de/.test(html),
		marcadores_id: [...html.matchAll(/\[ID (\d+)\]/g)].map((m) => Number(m[1])).slice(0, 40),
	});
	process.stderr.write(`${c.id} ${r.status} ${Date.now() - t0}ms\n`);
}
// Ejecuciones de la copia (lectura) para tool/params/filas.
const list = await (await fetch(`${base}/api/v1/executions?workflowId=${wf}&limit=100`, { headers: h })).json();
for (const e of list.data) {
	const d = await (await fetch(`${base}/api/v1/executions/${e.id}?includeData=true`, { headers: h })).json();
	const rd = d.data?.resultData?.runData ?? {};
	const first = (n) => rd[n]?.[0]?.data?.main?.[0]?.[0]?.json;
	const sid = first('Pre-Procesing3')?.sessionId;
	const row = out.find((x) => x.sessionId === sid);
	if (!row) continue;
	const p = first('Parse Intent JSON3');
	const u = first('Unwrap MCP Result');
	row.execution = d.id;
	row.tool = p?.tool ?? null;
	row.params = p?.params ?? null;
	row.filas = Array.isArray(u?.rows) ? u.rows.map((x) => Number(x.id)) : null;
	row.nodos = Object.keys(rd).filter((n) => ['Code in JavaScript1', 'Format Cards2'].includes(n));
}
fs.writeFileSync(outPath, JSON.stringify(out, null, 1));
for (const r of out) console.log(r.id, r.type, r.ms + 'ms', r.tool, JSON.stringify(r.params), 'filas', JSON.stringify(r.filas), r.no_afiliada ? 'NO_AFILIADA' : '', r.informacion_de ? 'FICHA' : '', r.marcadores_id.length ? '[ID…] ' + r.marcadores_id.slice(0, 6).join(',') : '', '|', r.espera);
